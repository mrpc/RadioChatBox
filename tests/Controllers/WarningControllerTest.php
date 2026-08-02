<?php

namespace RadioChatBox\Tests\Controllers;

use PDO;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\FlatCache;
use Pramnos\Framework\Testing\TestDatabase;
use RadioChatBox\Controllers\WarningController;
use RadioChatBox\Services\ChatService;
use RadioChatBox\Services\SettingsService;
use RadioChatBox\Services\WarningService;

/**
 * Tests for the escalating-warning system: the WarningService (active count,
 * expiry, auto-timeout at threshold, removal) and the admin controller
 * (validation, warn, list, remove).
 */
class WarningControllerTest extends TestCase
{
    private PDO $pdo;
    private string $target;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::connection();
        $this->target = 'warned_' . substr(bin2hex(random_bytes(5)), 0, 8);
        // Deterministic threshold for the tests.
        (new SettingsService())->set('warning_auto_timeout_threshold', '2');
        (new SettingsService())->set('warning_auto_timeout_minutes', '30');
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare('DELETE FROM user_warnings WHERE username = ?')->execute([$this->target]);
        $this->pdo->prepare("DELETE FROM settings WHERE setting IN ('warning_auto_timeout_threshold', 'warning_auto_timeout_minutes')")->execute();
        (new ChatService())->clearUserTimeout($this->target);
        FlatCache::default()->clear();
        $_POST = [];
        $_GET = [];
    }

    // ---- service ----------------------------------------------------

    /** Warnings accumulate; reaching the threshold auto-timeouts the user. */
    public function testThresholdAutoTimesOut(): void
    {
        $service = new WarningService();

        $first = $service->warn($this->target, 'mod', 'spam');
        $this->assertSame(1, $first['active_count']);
        $this->assertFalse($first['auto_timed_out']);
        $this->assertSame(0, (new ChatService())->getTimeoutRemaining($this->target));

        $second = $service->warn($this->target, 'mod', 'more spam');
        $this->assertSame(2, $second['active_count']);
        $this->assertTrue($second['auto_timed_out']);
        $this->assertGreaterThan(0, (new ChatService())->getTimeoutRemaining($this->target));
    }

    /** Expired warnings don't count towards the active total. */
    public function testExpiredWarningsAreNotCounted(): void
    {
        $this->pdo->prepare(
            "INSERT INTO user_warnings (username, moderator, reason, created_at, expires_at)
             VALUES (?, 'mod', 'old', NOW() - INTERVAL '2 days', NOW() - INTERVAL '1 day')"
        )->execute([$this->target]);

        $this->assertSame(0, (new WarningService())->activeCount($this->target));
    }

    /** A removed warning drops the active count. */
    public function testRemoveWarning(): void
    {
        $service = new WarningService();
        $r = $service->warn($this->target, 'mod', 'once');
        $this->assertSame(1, $service->activeCount($this->target));

        $service->remove($r['warning_id']);
        $this->assertSame(0, $service->activeCount($this->target));
    }

    // ---- controller -------------------------------------------------

    /** Warn requires a username. */
    public function testWarnRequiresUsername(): void
    {
        $_POST = ['reason' => 'x'];
        $this->assertSame(400, (new WarningController())->warn()->getStatusCode());
    }

    /** Warn returns the escalation state. */
    public function testWarnReturnsState(): void
    {
        $_POST = ['username' => $this->target, 'reason' => 'flooding'];
        $response = (new WarningController())->warn();
        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertSame(1, $body['active_count']);
        $this->assertArrayHasKey('auto_timed_out', $body);
    }

    /** The list endpoint returns a user's warnings and active count. */
    public function testListReturnsWarnings(): void
    {
        (new WarningService())->warn($this->target, 'mod', 'be nice');

        $_GET = ['username' => $this->target];
        $response = (new WarningController())->list();
        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame(1, $body['active_count']);
        $this->assertCount(1, $body['warnings']);
    }

    /** Remove validates the id. */
    public function testRemoveValidatesId(): void
    {
        $_POST = ['id' => 0];
        $this->assertSame(400, (new WarningController())->remove()->getStatusCode());
    }
}
