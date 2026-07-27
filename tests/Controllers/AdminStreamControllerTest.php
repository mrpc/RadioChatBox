<?php

namespace RadioChatBox\Tests\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Http\Sse\SseWriter;
use Pramnos\Http\StreamedResponse;
use RadioChatBox\Controllers\AdminStreamController;

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
}
