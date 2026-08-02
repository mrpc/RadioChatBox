<?php

namespace RadioChatBox\Tests;

use Pramnos\Framework\Testing\TestDatabase;
use PHPUnit\Framework\TestCase;
use RadioChatBox\Services\ModerationLog;

/**
 * The moderator audit trail records an action and returns it (newest first),
 * with optional filtering by action type.
 */
class ModerationLogTest extends TestCase
{
    private string $marker;

    protected function setUp(): void
    {
        $this->marker = 'modlogtest_' . substr(bin2hex(random_bytes(4)), 0, 8);
    }

    protected function tearDown(): void
    {
        try {
            TestDatabase::connection()
                ->prepare('DELETE FROM moderation_log WHERE target = ?')
                ->execute([$this->marker]);
        } catch (\Throwable) {
        }
    }

    public function testRecordThenRecentReturnsTheAction(): void
    {
        $log = new ModerationLog();
        $log->record('adminA', 'timeout', $this->marker, '300s');

        $recent = $log->recent(200);
        $mine = null;
        foreach ($recent as $r) {
            if (($r['target'] ?? null) === $this->marker) { $mine = $r; break; }
        }
        $this->assertNotNull($mine, 'the recorded action is returned');
        $this->assertSame('adminA', $mine['admin_username']);
        $this->assertSame('timeout', $mine['action']);
        $this->assertSame('300s', $mine['details']);
    }

    public function testRecentFiltersByAction(): void
    {
        $log = new ModerationLog();
        $log->record('adminB', 'kick', $this->marker);

        $kicks = $log->recent(200, 0, 'kick');
        foreach ($kicks as $r) {
            $this->assertSame('kick', $r['action'], 'the filter returns only kicks');
        }
        $matched = array_filter($kicks, fn ($r) => ($r['target'] ?? null) === $this->marker);
        $this->assertNotEmpty($matched, 'our kick is present under the kick filter');

        $bans = $log->recent(200, 0, 'ban_nickname');
        $mineInBans = array_filter($bans, fn ($r) => ($r['target'] ?? null) === $this->marker);
        $this->assertEmpty($mineInBans, 'our kick is absent from the ban filter');
    }
}
