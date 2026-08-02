<?php

namespace RadioChatBox\Tests\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Framework\Testing\TestDatabase;
use RadioChatBox\Controllers\ReportController;
use RadioChatBox\Services\ReportService;

/**
 * The admin reports stats endpoint returns the aggregate {by_status, by_reason,
 * top_reported} shape. The AdminAuthMiddleware only runs through the router, so
 * calling the action directly exercises the handler without auth.
 */
class ReportControllerTest extends TestCase
{
    /** @var list<int> */
    private array $ids = [];

    protected function tearDown(): void
    {
        if ($this->ids !== []) {
            $pdo = TestDatabase::connection();
            $place = implode(',', array_fill(0, count($this->ids), '?'));
            try {
                $pdo->prepare("DELETE FROM message_reports WHERE id IN ($place)")->execute($this->ids);
            } catch (\Throwable) {
            }
        }
        $this->ids = [];
        $_GET = [];
    }

    /** stats endpoint returns success and the aggregate structure. */
    public function testStatsEndpointReturnsAggregates(): void
    {
        $svc = new ReportService();
        $target = 'rc_target_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $this->ids[] = $svc->create('rc_a', null, null, 'public', $target, 'spam');
        $this->ids[] = $svc->create('rc_b', null, null, 'public', $target, 'spam');

        $_GET = ['days' => '30'];
        $response = (new ReportController())->stats();

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertArrayHasKey('by_status', $body['stats']);
        $this->assertArrayHasKey('by_reason', $body['stats']);
        $this->assertArrayHasKey('top_reported', $body['stats']);
        $this->assertGreaterThanOrEqual(2, $body['stats']['by_reason']['spam']);
    }
}
