<?php

namespace RadioChatBox\Tests;

use Pramnos\Framework\Testing\TestDatabase;
use PHPUnit\Framework\TestCase;
use RadioChatBox\Services\ReportService;

/**
 * Abuse reports: create validates the reason, list/count filter by status, and
 * setStatus resolves/dismisses a pending report.
 */
class ReportServiceTest extends TestCase
{
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
    }

    private function svc(): ReportService
    {
        return new ReportService();
    }

    public function testCreateStoresAReportAndReturnsId(): void
    {
        $id = $this->svc()->create('reporter1', 'sess-1', 'msg_abc', 'public', 'baddy', 'spam', 'buying followers', 'spammy text');
        $this->ids[] = $id;
        $this->assertGreaterThan(0, $id);

        $pending = $this->svc()->list('pending', 200, 0);
        $mine = null;
        foreach ($pending as $r) {
            if ((int) $r['id'] === $id) { $mine = $r; break; }
        }
        $this->assertNotNull($mine, 'the new report appears in the pending queue');
        $this->assertSame('spam', $mine['reason']);
        $this->assertSame('baddy', $mine['reported_username']);
        $this->assertSame('spammy text', $mine['content_snapshot']);
        $this->assertSame('pending', $mine['status']);
    }

    public function testCreateRejectsAnInvalidReason(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc()->create('reporter1', 'sess-1', null, 'public', null, 'not_a_reason');
    }

    public function testCreateRejectsAnEmptyReporter(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc()->create('   ', null, null, 'public', null, 'spam');
    }

    public function testSetStatusResolvesAReportOutOfPending(): void
    {
        $id = $this->svc()->create('reporter2', null, null, 'private', 'meany', 'harassment');
        $this->ids[] = $id;

        $before = $this->svc()->count('pending');
        $ok = $this->svc()->setStatus($id, 'resolved', 'adminuser');
        $this->assertTrue($ok);

        $after = $this->svc()->count('pending');
        $this->assertSame($before - 1, $after, 'one fewer pending report');

        $resolved = $this->svc()->list('resolved', 200, 0);
        $found = false;
        foreach ($resolved as $r) {
            if ((int) $r['id'] === $id) {
                $found = true;
                $this->assertSame('adminuser', $r['resolved_by']);
                $this->assertNotNull($r['resolved_at']);
            }
        }
        $this->assertTrue($found, 'the report is now in the resolved list');
    }

    public function testSetStatusRejectsAnInvalidTransition(): void
    {
        $this->assertFalse($this->svc()->setStatus(0, 'resolved', 'a'));
        $this->assertFalse($this->svc()->setStatus(5, 'pending', 'a'), 'cannot set back to pending');
    }

    public function testSetStatusStoresAModeratorNote(): void
    {
        $id = $this->svc()->create('rep3', null, null, 'public', 'target3', 'offensive');
        $this->ids[] = $id;
        $this->svc()->setStatus($id, 'resolved', 'modx', 'warned the user');

        $rows = $this->svc()->list('resolved', 200, 0);
        $mine = null;
        foreach ($rows as $r) {
            if ((int) $r['id'] === $id) { $mine = $r; break; }
        }
        $this->assertNotNull($mine);
        $this->assertSame('warned the user', $mine['resolution_note']);
    }

    public function testForReportedUserReturnsReportsAgainstThem(): void
    {
        $victim = 'victim_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $this->ids[] = $this->svc()->create('r1', null, null, 'public', $victim, 'spam');
        $this->ids[] = $this->svc()->create('r2', null, null, 'private', $victim, 'harassment');
        $this->ids[] = $this->svc()->create('r3', null, null, 'public', 'someone_else', 'spam');

        $reports = $this->svc()->forReportedUser($victim);
        $this->assertCount(2, $reports, 'only reports against this user');
        foreach ($reports as $r) {
            $this->assertSame($victim, $r['reported_username']);
        }
    }
}
