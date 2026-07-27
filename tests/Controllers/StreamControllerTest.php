<?php

namespace RadioChatBox\Tests\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Http\StreamedResponse;
use RadioChatBox\Controllers\StreamController;

/**
 * Contract test for the migrated public SSE endpoint GET /api/stream (replaced
 * public/api/stream.php).
 *
 * The controller must hand back a StreamedResponse carrying the event-stream
 * headers the browser's EventSource and the Cloudflare/nginx edge rely on. The
 * streaming producer itself is a long-lived Redis subscribe loop, so it is not
 * driven here — its behaviour is covered by the framework SseWriter/RedisDriver
 * unit tests and by live verification; this test pins the response contract.
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
}
