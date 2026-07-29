<?php

namespace RadioChatBox\Tests\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Http\Sse\SseWriter;
use Pramnos\Http\StreamedResponse;
use Pramnos\Redis\ConnectionManager;
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
}
