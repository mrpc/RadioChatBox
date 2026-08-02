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

    /** details endpoint returns the report plus the reported user's history. */
    public function testDetailsReturnsReportAndHistory(): void
    {
        $svc = new ReportService();
        $target = 'rc_det_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $id = $svc->create('rc_r1', null, 'mid1', 'public', $target, 'spam', 'note here', 'the bad content');
        $this->ids[] = $id;
        $this->ids[] = $svc->create('rc_r2', null, 'mid2', 'public', $target, 'harassment');

        $_GET = ['id' => (string) $id];
        $response = (new ReportController())->adminDetails();
        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertSame($id, (int) $body['report']['id']);
        $this->assertSame('the bad content', $body['report']['content_snapshot']);
        $this->assertCount(2, $body['against_user']);
        $this->assertSame(2, $body['pending_against']);
    }

    /** details endpoint 404s for a missing report. */
    public function testDetailsMissingReport404(): void
    {
        $_GET = ['id' => '99999999'];
        $this->assertSame(404, (new ReportController())->adminDetails()->getStatusCode());
    }

    /** bulk-resolve marks several reports handled and reports the count. */
    public function testBulkResolveMarksSeveralHandled(): void
    {
        $svc = new ReportService();
        $a = $svc->create('rc_b1', null, null, 'public', 'rc_bt', 'spam');
        $b = $svc->create('rc_b2', null, null, 'public', 'rc_bt', 'spam');
        $this->ids[] = $a;
        $this->ids[] = $b;

        $_POST = ['ids' => [$a, $b], 'action' => 'resolve'];
        $response = (new ReportController())->adminBulkResolve();
        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertSame(2, $body['updated']);

        $this->assertSame('resolved', $svc->find($a)['status']);
        $this->assertSame('resolved', $svc->find($b)['status']);
        $_POST = [];
    }

    /** bulk-resolve with no ids or a bad action is a 400. */
    public function testBulkResolveValidation(): void
    {
        $_POST = ['ids' => [], 'action' => 'resolve'];
        $this->assertSame(400, (new ReportController())->adminBulkResolve()->getStatusCode());
        $_POST = ['ids' => [1], 'action' => 'nope'];
        $this->assertSame(400, (new ReportController())->adminBulkResolve()->getStatusCode());
        $_POST = [];
    }
}
