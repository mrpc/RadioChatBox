<?php

namespace RadioChatBox\Tests\Controllers;

use Pramnos\Broadcasting\Drivers\SubscribableDriverInterface;
use Pramnos\Broadcasting\SubscriptionOptions;
use PHPUnit\Framework\TestCase;
use Pramnos\Http\StreamedResponse;
use RadioChatBox\Controllers\StreamController;

/**
 * A scripted backplane: subscribe() replays a fixed list of (channel, event,
 * payload) tuples to the consumer and returns, so the SSE producer can be driven
 * to completion without a live Redis subscribe loop.
 */
final class ScriptedStreamDriver implements SubscribableDriverInterface
{
    /** @param list<array{0:string,1:string,2:array}> $events */
    public function __construct(private array $events = [])
    {
    }

    public function name(): string
    {
        return 'scripted';
    }

    public function broadcast(string $channel, string $event, array $payload): void
    {
    }

    public function subscribe(array $channels, callable $onEvent, ?SubscriptionOptions $options = null): void
    {
        foreach ($this->events as [$channel, $event, $payload]) {
            if ($onEvent($channel, $event, $payload) === false) {
                return;
            }
        }
    }
}

/**
 * Contract + producer tests for the migrated public SSE endpoint GET /api/stream
 * (replaced public/api/stream.php).
 *
 * The controller must hand back a StreamedResponse carrying the event-stream
 * headers the browser's EventSource and the Cloudflare/nginx edge rely on, and
 * its producer must emit the initial snapshot and map each backplane channel to
 * the right named SSE event. The producer is driven with a scripted driver so no
 * live Redis loop is needed.
 */
class StreamControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        $_GET = [];
    }

    public function testReturnsEventStreamResponse(): void
    {
        $_GET = ['username' => 'tester'];

        $response = (new StreamController())->index();

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/event-stream', $response->getHeaderLine('Content-Type'));
        $this->assertSame('no-cache', $response->getHeaderLine('Cache-Control'));
        $this->assertSame('no', $response->getHeaderLine('X-Accel-Buffering'));
    }

    /**
     * Running the producer with a scripted backplane emits the initial snapshot
     * (history/users/config) and maps each channel to its SSE event: chat:updates
     * → clear/message_deleted/reaction/message by payload type, chat:user_updates
     * → users, and a chat:private_messages addressed to the viewer → private (a DM
     * for someone else is filtered out). A reconnect event closes the stream.
     */
    public function testProducerEmitsSnapshotAndMapsBackplaneEvents(): void
    {
        $_GET = ['username' => 'viewer'];

        $driver = new ScriptedStreamDriver([
            ['chat:updates', '', ['type' => 'clear']],
            ['chat:updates', '', ['type' => 'message_deleted', 'message_id' => 'm1']],
            ['chat:updates', '', ['type' => 'reaction', 'emoji' => '👍']],
            ['chat:updates', '', ['message' => 'plain message']],
            ['chat:user_updates', '', ['count' => 3]],
            ['chat:private_messages', '', ['to_username' => 'viewer', 'from_username' => 'a', 'message' => 'mine']],
            ['chat:private_messages', '', ['to_username' => 'someone_else', 'from_username' => 'x', 'message' => 'not-mine']],
        ]);

        $response = (new StreamController($driver))->index();

        ob_start();
        ($response->getProducer())();
        $out = (string) ob_get_clean();

        // Initial snapshot.
        $this->assertStringContainsString('event: history', $out);
        $this->assertStringContainsString('event: users', $out);
        $this->assertStringContainsString('event: config', $out);

        // Channel → event mapping.
        $this->assertStringContainsString('event: clear', $out);
        $this->assertStringContainsString('event: message_deleted', $out);
        $this->assertStringContainsString('event: reaction', $out);
        $this->assertStringContainsString('event: message', $out);

        // Private message for the viewer is delivered; the one for someone else is not.
        $this->assertStringContainsString('event: private', $out);
        $this->assertStringContainsString('mine', $out);
        $this->assertStringNotContainsString('not-mine', $out);

        // The max-runtime reconnect prompt closes the stream.
        $this->assertStringContainsString('event: reconnect', $out);
    }
}
