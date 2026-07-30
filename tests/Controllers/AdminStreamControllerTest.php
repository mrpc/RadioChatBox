<?php

namespace RadioChatBox\Tests\Controllers;

use Pramnos\Broadcasting\Drivers\SubscribableDriverInterface;
use Pramnos\Broadcasting\SubscriptionOptions;
use PHPUnit\Framework\TestCase;
use Pramnos\Http\Sse\SseWriter;
use Pramnos\Http\StreamedResponse;
use Pramnos\Redis\ConnectionManager;
use RadioChatBox\Controllers\AdminStreamController;

/**
 * A scripted backplane for the admin stream: replays notification events to the
 * consumer then returns, so the producer can be driven without a live Redis loop.
 */
final class ScriptedAdminDriver implements SubscribableDriverInterface
{
    /** @param list<array{0:string,1:string,2:array}> $events */
    public function __construct(private array $events = [])
    {
    }

    public function name(): string
    {
        return 'scripted-admin';
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
 * Contract test for the migrated admin SSE endpoint GET /api/admin/stream
 * (replaced public/api/admin/stream.php).
 *
 * This endpoint authenticates inside the stream (session_token / header) and, on
 * failure, emits an SSE error event on a 200 event-stream rather than a 401 —
 * the legacy contract, because EventSource cannot send auth headers. We assert
 * the event-stream response contract and that an unauthenticated producer run
 * emits exactly the {"error":"Unauthorized"} error event and nothing else.
 */
class AdminStreamControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        $_GET = [];
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    }

    public function testReturnsEventStreamResponse(): void
    {
        $response = (new AdminStreamController())->index();

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/event-stream', $response->getHeaderLine('Content-Type'));
    }

    public function testUnauthenticatedProducerEmitsErrorEvent(): void
    {
        $_GET = [];
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);

        $response = (new AdminStreamController())->index();

        ob_start();
        ($response->getProducer())(); // no auth -> emits error and returns (no subscribe loop)
        $out = ob_get_clean();

        $this->assertSame("event: error\ndata: {\"error\":\"Unauthorized\"}\n\n", $out);
    }

    /**
     * Regression for the #14 ConnectionManager migration of the SSE stream token:
     * a token minted the way AdminSystemController::createSession() writes it — a
     * raw, UNPREFIXED admin_session:<token> JSON entry on the shared framework
     * connection — must be resolvable by AdminStreamController's authenticator,
     * which now reads it over that same ConnectionManager connection. This pins
     * both the keyspace (unprefixed) and the connection source across the two
     * sides; if either drifts, the query-param SSE auth silently breaks.
     */
    public function testResolvesStreamTokenMintedOnTheSharedConnection(): void
    {
        $redis = ConnectionManager::getInstance()->connection();
        $token = 'test-stream-token-' . bin2hex(random_bytes(8));
        $key   = 'admin_session:' . $token;
        $redis->setex($key, 3600, (string) json_encode([
            'username'   => 'streamadmin',
            'role'       => 'administrator',
            'expires_at' => time() + 3600,
            'created_at' => time(),
        ]));

        try {
            $_GET['session_token'] = $token;

            $method = new \ReflectionMethod(AdminStreamController::class, 'authenticate');
            $method->setAccessible(true);
            $resolved = $method->invoke(new AdminStreamController());

            $this->assertSame(
                ['username' => 'streamadmin', 'role' => 'administrator'],
                $resolved
            );
        } finally {
            $redis->del($key);
            unset($_GET['session_token']);
        }
    }

    /**
     * A session token for a non-privileged role passes authentication but fails
     * the admin-role gate: the producer emits the Forbidden error event and stops
     * before any subscribe.
     */
    public function testForbiddenRoleEmitsErrorEvent(): void
    {
        $redis = ConnectionManager::getInstance()->connection();
        $token = 'test-stream-token-' . bin2hex(random_bytes(8));
        $key   = 'admin_session:' . $token;
        $redis->setex($key, 3600, (string) json_encode([
            'username'   => 'lowuser',
            'role'       => 'simple_user',
            'expires_at' => time() + 3600,
        ]));

        try {
            $_GET['session_token'] = $token;
            $response = (new AdminStreamController())->index();

            ob_start();
            ($response->getProducer())();
            $out = (string) ob_get_clean();

            $this->assertSame(
                "event: error\ndata: {\"error\":\"Forbidden - Admin access required\"}\n\n",
                $out
            );
        } finally {
            $redis->del($key);
            unset($_GET['session_token']);
        }
    }

    /**
     * An authenticated admin (via session token) with an injected scripted
     * backplane: the producer emits the initial notification_count, maps a
     * chat:admin_notifications message to a `notification` event, and closes with a
     * reconnect prompt at the runtime ceiling.
     */
    public function testAuthenticatedProducerStreamsNotifications(): void
    {
        $redis = ConnectionManager::getInstance()->connection();
        $token = 'test-stream-token-' . bin2hex(random_bytes(8));
        $key   = 'admin_session:' . $token;
        $redis->setex($key, 3600, (string) json_encode([
            'username'   => 'streamadmin',
            'role'       => 'administrator',
            'expires_at' => time() + 3600,
        ]));

        $driver = new ScriptedAdminDriver([
            ['chat:admin_notifications', '', ['type' => 'fake_user_dm', 'message_preview' => 'hi admin']],
        ]);

        try {
            $_GET['session_token'] = $token;
            $response = (new AdminStreamController($driver))->index();

            ob_start();
            ($response->getProducer())();
            $out = (string) ob_get_clean();

            $this->assertStringContainsString('event: notification_count', $out);
            $this->assertStringContainsString('event: notification', $out);
            $this->assertStringContainsString('hi admin', $out);
            $this->assertStringContainsString('event: reconnect', $out);
        } finally {
            $redis->del($key);
            unset($_GET['session_token']);
        }
    }
}
