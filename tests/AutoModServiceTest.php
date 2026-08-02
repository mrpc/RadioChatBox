<?php

namespace RadioChatBox\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\FlatCache;
use Pramnos\Framework\Testing\TestDatabase;
use RadioChatBox\Services\AutoModService;
use RadioChatBox\Services\ChatService;
use RadioChatBox\Services\ReportService;
use RadioChatBox\Services\SettingsService;

/**
 * Auto-moderation: once a user accumulates enough PENDING reports, the configured
 * action (timeout or ban) is applied automatically; staff are exempt, the feature
 * gate and threshold are honoured, and actions are idempotent.
 */
class AutoModServiceTest extends TestCase
{
    private PDO $pdo;
    private string $target;
    /** @var list<int> */
    private array $reportIds = [];

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::connection();
        $this->target = 'am_' . substr(bin2hex(random_bytes(5)), 0, 8);
        $settings = new SettingsService();
        $settings->set('automod_enabled', 'true');
        $settings->set('automod_report_threshold', '3');
        $settings->set('automod_action', 'timeout');
        $settings->set('automod_timeout_minutes', '30');
    }

    protected function tearDown(): void
    {
        foreach ($this->reportIds as $id) {
            $this->pdo->prepare('DELETE FROM message_reports WHERE id = ?')->execute([$id]);
        }
        $this->pdo->prepare('DELETE FROM message_reports WHERE reported_username = ?')->execute([$this->target]);
        $this->pdo->prepare('DELETE FROM banned_nicknames WHERE LOWER(nickname) = LOWER(?)')->execute([$this->target]);
        $this->pdo->prepare('DELETE FROM users WHERE username = ?')->execute([$this->target]);
        $this->pdo->prepare('DELETE FROM moderation_log WHERE target = ?')->execute([$this->target]);
        $this->pdo->prepare("DELETE FROM settings WHERE setting LIKE 'automod_%'")->execute();
        FlatCache::default()->clear();
    }

    private function fileReports(int $n): void
    {
        $svc = new ReportService();
        for ($i = 0; $i < $n; $i++) {
            $this->reportIds[] = $svc->create('reporter' . $i, null, null, 'public', $this->target, 'spam');
        }
    }

    /** Below the threshold, nothing happens. */
    public function testBelowThresholdDoesNothing(): void
    {
        $this->fileReports(2); // threshold is 3
        $this->assertNull((new AutoModService())->onReport($this->target));
        $this->assertSame(0, (new ChatService())->getTimeoutRemaining($this->target));
    }

    /** At the threshold, the user is timed out. */
    public function testThresholdTriggersTimeout(): void
    {
        $this->fileReports(3);
        $result = (new AutoModService())->onReport($this->target);
        $this->assertStringContainsStringIgnoringCase('auto-timed-out', (string) $result);
        $this->assertGreaterThan(0, (new ChatService())->getTimeoutRemaining($this->target));
    }

    /** With action=ban, the user's nickname is banned at the threshold. */
    public function testThresholdTriggersBan(): void
    {
        (new SettingsService())->set('automod_action', 'ban');
        $this->fileReports(3);
        $result = (new AutoModService())->onReport($this->target);
        $this->assertStringContainsStringIgnoringCase('auto-banned', (string) $result);
        $this->assertTrue((new ChatService())->nicknameIsBanned($this->target));
    }

    /** Staff (moderator+) are never auto-moderated. */
    public function testStaffAreExempt(): void
    {
        $this->pdo->prepare('INSERT INTO users (username, password, usertype) VALUES (?, ?, 50)')
            ->execute([$this->target, 'x']);
        $this->fileReports(5);
        $this->assertNull((new AutoModService())->onReport($this->target));
        $this->assertSame(0, (new ChatService())->getTimeoutRemaining($this->target));
    }

    /** When the feature is off, nothing happens even past the threshold. */
    public function testDisabledDoesNothing(): void
    {
        (new SettingsService())->set('automod_enabled', 'false');
        $this->fileReports(5);
        $this->assertNull((new AutoModService())->onReport($this->target));
    }

    /** A second trigger doesn't re-time-out an already timed-out user. */
    public function testIdempotentTimeout(): void
    {
        $this->fileReports(3);
        $mod = new AutoModService();
        $this->assertNotNull($mod->onReport($this->target));
        $this->assertNull($mod->onReport($this->target), 'already timed out → no repeat');
    }

    /** The action is recorded in the moderation log under an auto_* verb. */
    public function testActionIsLogged(): void
    {
        $this->fileReports(3);
        (new AutoModService())->onReport($this->target);
        $count = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM moderation_log WHERE action = 'auto_timeout' AND target = "
            . $this->pdo->quote($this->target)
        )->fetchColumn();
        $this->assertSame(1, $count);
    }
}
