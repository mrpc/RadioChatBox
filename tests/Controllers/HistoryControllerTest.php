<?php

namespace RadioChatBox\Tests\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Http\Response;
use RadioChatBox\Controllers\HistoryController;

/**
 * Golden-contract test for the migrated GET /api/history endpoint.
 *
 * Replaced public/api/history.php; must keep the {success, messages} contract and
 * honour the ?limit query parameter (capped at 100).
 */
class HistoryControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        $_GET = [];
    }

    public function testReturnsMessagesPayloadShape(): void
    {
        $_GET = ['limit' => '5', 'username' => 'tester'];

        $response = (new HistoryController())->index();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertTrue($body['success']);
        $this->assertIsArray($body['messages']);
        // With ?limit=5 the runner never returns more than the requested page.
        $this->assertLessThanOrEqual(5, count($body['messages']));
    }
}
