<?php

namespace RadioChatBox\Tests;

use Pramnos\Framework\Testing\TestDatabase;
use PHPUnit\Framework\TestCase;
use RadioChatBox\Services\UserService;

/**
 * Brute-force protection on the login path: UserService::authenticate() drives
 * the framework's native Loginlockout (authserver.loginlockouts), so repeated
 * failures temporarily lock the identifier — even against the correct password —
 * while other identifiers and a successful login are unaffected.
 */
class AuthLockoutTest extends TestCase
{
    /** @var string[] identifiers whose lockout rows to clean up */
    private array $lockIds = [];
    /** @var int[] user ids to delete */
    private array $userIds = [];

    protected function tearDown(): void
    {
        $pdo = TestDatabase::connection();
        foreach ($this->lockIds as $id) {
            try {
                $pdo->prepare('DELETE FROM authserver.loginlockouts WHERE lookupvalue = ?')->execute([$id]);
            } catch (\Throwable) {
            }
        }
        foreach ($this->userIds as $uid) {
            try {
                $pdo->prepare('DELETE FROM users WHERE userid = ?')->execute([$uid]);
            } catch (\Throwable) {
            }
        }
        $this->lockIds = [];
        $this->userIds = [];
        parent::tearDown();
    }

    private function makeUser(string $username, string $password): int
    {
        $svc = new UserService();
        $res = $svc->createUser($username, $password, 'simple_user', null, null);
        $this->assertTrue($res['success'] ?? false, 'test user should be created');
        $uid = (int) ($res['user']['userid'] ?? 0);
        $this->userIds[] = $uid;
        $this->lockIds[] = mb_strtolower($username);
        return $uid;
    }

    public function testRepeatedFailuresLockOutEvenTheCorrectPassword(): void
    {
        $username = 'lockout_' . uniqid();
        $password = 'correct-horse-1';
        $this->makeUser($username, $password);

        // Sanity: the right password works before any failures.
        $this->assertNotNull((new UserService())->authenticate($username, $password));

        // DEFAULT_STEPS locks at 3 failures (60s). Trip it.
        $svc = new UserService();
        for ($i = 0; $i < 3; $i++) {
            $this->assertNull($svc->authenticate($username, 'wrong-' . $i), "attempt $i must fail");
        }

        // Now even the correct password is refused while the lock is active.
        $this->assertNull(
            $svc->authenticate($username, $password),
            'the identifier must be locked out after repeated failures'
        );
    }

    public function testLockoutIsPerIdentifier(): void
    {
        $victim = 'victim_' . uniqid();
        $other  = 'other_' . uniqid();
        $pass   = 'pass-word-9';
        $this->makeUser($victim, $pass);
        $this->makeUser($other, $pass);

        $svc = new UserService();
        for ($i = 0; $i < 3; $i++) {
            $svc->authenticate($victim, 'nope');
        }

        // The victim is locked, but an unrelated identifier still authenticates.
        $this->assertNull($svc->authenticate($victim, $pass), 'victim locked');
        $this->assertNotNull($svc->authenticate($other, $pass), 'unrelated identifier unaffected');
    }

    public function testSuccessBelowThresholdClearsTheCounter(): void
    {
        $username = 'clearing_' . uniqid();
        $password = 'right-pass-2';
        $this->makeUser($username, $password);

        $svc = new UserService();
        // Two failures (below the 3-attempt threshold), then a success clears state.
        $svc->authenticate($username, 'x1');
        $svc->authenticate($username, 'x2');
        $this->assertNotNull($svc->authenticate($username, $password), 'success below threshold');

        // The counter was reset, so two more failures still do NOT lock (would need
        // 3 consecutive within the window).
        $svc->authenticate($username, 'y1');
        $svc->authenticate($username, 'y2');
        $this->assertNotNull(
            $svc->authenticate($username, $password),
            'a successful login must have reset the failure counter'
        );
    }
}
