<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use Pramnos\Cache\FlatCache;
use Pramnos\Framework\Testing\TestDatabase;
use RadioChatBox\Services\SettingsService;
use RadioChatBox\Services\SpamGuard;

/**
 * Duplicate-message spam detection: off by default, trips only after the same
 * message exceeds the threshold within the window, is case/whitespace
 * insensitive, and is scoped per user.
 */
class SpamGuardTest extends TestCase
{
    protected function setUp(): void
    {
        TestDatabase::connection();
        FlatCache::default()->clear();
        $s = new SettingsService();
        $s->set('spam_detection_enabled', 'true');
        $s->set('spam_duplicate_threshold', '3');
        $s->set('spam_window_seconds', '60');
        $s->invalidateCache();
    }

    protected function tearDown(): void
    {
        TestDatabase::connection()
            ->prepare("DELETE FROM settings WHERE setting LIKE 'spam_%'")->execute();
        (new SettingsService())->invalidateCache();
        FlatCache::default()->clear();
    }

    /** Off by default (feature flag false) → never spam. */
    public function testDisabledNeverFlags(): void
    {
        (new SettingsService())->set('spam_detection_enabled', 'false');
        (new SettingsService())->invalidateCache();
        $guard = new SpamGuard();
        for ($i = 0; $i < 10; $i++) {
            $this->assertFalse($guard->isDuplicateSpam('bob', 'buy now'));
        }
    }

    /** The (threshold+1)-th identical message trips the guard. */
    public function testTripsAfterThreshold(): void
    {
        $guard = new SpamGuard();
        $u = 'spammer_' . substr(bin2hex(random_bytes(4)), 0, 6);
        // threshold = 3, so sends 1..3 are fine, the 4th trips.
        $this->assertFalse($guard->isDuplicateSpam($u, 'same text'));
        $this->assertFalse($guard->isDuplicateSpam($u, 'same text'));
        $this->assertFalse($guard->isDuplicateSpam($u, 'same text'));
        $this->assertTrue($guard->isDuplicateSpam($u, 'same text'), '4th identical message is spam');
    }

    /** Different messages don't accumulate toward the same counter. */
    public function testDifferentMessagesDoNotTrip(): void
    {
        $guard = new SpamGuard();
        $u = 'chatter_' . substr(bin2hex(random_bytes(4)), 0, 6);
        for ($i = 0; $i < 6; $i++) {
            $this->assertFalse($guard->isDuplicateSpam($u, 'message number ' . $i));
        }
    }

    /** Matching is case- and whitespace-insensitive. */
    public function testCaseAndWhitespaceInsensitive(): void
    {
        $guard = new SpamGuard();
        $u = 'noisy_' . substr(bin2hex(random_bytes(4)), 0, 6);
        $this->assertFalse($guard->isDuplicateSpam($u, 'Hello   World'));
        $this->assertFalse($guard->isDuplicateSpam($u, 'hello world'));
        $this->assertFalse($guard->isDuplicateSpam($u, 'HELLO WORLD'));
        $this->assertTrue($guard->isDuplicateSpam($u, '  hello world  '));
    }

    /** The counter is per user — one user's repeats don't affect another. */
    public function testScopedPerUser(): void
    {
        $guard = new SpamGuard();
        $suffix = substr(bin2hex(random_bytes(4)), 0, 6);
        for ($i = 0; $i < 4; $i++) {
            $guard->isDuplicateSpam('alice_' . $suffix, 'ping');
        }
        // Bob saying the same thing once is still fine.
        $this->assertFalse($guard->isDuplicateSpam('bob_' . $suffix, 'ping'));
    }
}
