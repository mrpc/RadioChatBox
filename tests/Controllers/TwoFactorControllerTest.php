<?php

namespace RadioChatBox\Tests\Controllers;

use PDO;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\TOTPHelper;
use Pramnos\Framework\Testing\TestDatabase;
use RadioChatBox\Controllers\AuthController;
use RadioChatBox\Controllers\TwoFactorController;
use RadioChatBox\Services\UserService;

/**
 * TOTP 2FA management + login step-up: enroll (setup → verify with a real TOTP
 * code), status reflects it, and login then demands a code before binding the
 * session. Uses the framework TwoFactorAuthService/TOTPHelper end to end.
 */
class TwoFactorControllerTest extends TestCase
{
    private PDO $pdo;
    private string $user;
    private string $session;
    private int $userId;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::connection();
        $suffix = substr(bin2hex(random_bytes(5)), 0, 8);
        $this->user = 'tfa_' . $suffix;
        $this->session = 'tfasess_' . $suffix;

        // A real account + a chat session bound to it.
        $res = (new UserService())->createUser($this->user, 'secret123', 'simple_user');
        $this->userId = (int) $res['user']['userid'];
        $this->pdo->prepare(
            'INSERT INTO presence_sessions (username, session_id, user_id, ip_address, last_heartbeat, joined_at)
             VALUES (?, ?, ?, ?, NOW(), NOW())'
        )->execute([$this->user, $this->session, $this->userId, '127.0.0.1']);
    }

    protected function tearDown(): void
    {
        foreach (['user_twofactor', 'twofactor_setup'] as $t) {
            try { $this->pdo->prepare("DELETE FROM authserver.$t WHERE userid = ?")->execute([$this->userId]); } catch (\Throwable) {}
        }
        $this->pdo->prepare('DELETE FROM presence_sessions WHERE username = ?')->execute([$this->user]);
        $this->pdo->prepare('DELETE FROM users WHERE username = ?')->execute([$this->user]);
        $_POST = [];
        $_GET = [];
    }

    /** Full enrollment: setup returns a secret, verify with a real code enables it. */
    public function testEnrollAndStatus(): void
    {
        // Not enabled initially.
        $_GET = ['username' => $this->user, 'session_id' => $this->session];
        $status = json_decode((new TwoFactorController())->status()->getBody(), true);
        $this->assertFalse((bool) ($status['status']['enabled'] ?? false));

        // Begin setup.
        $_POST = ['username' => $this->user, 'session_id' => $this->session];
        $setupResp = (new TwoFactorController())->setup();
        $this->assertSame(200, $setupResp->getStatusCode());
        $secret = json_decode($setupResp->getBody(), true)['setup']['secret'];
        $this->assertNotEmpty($secret);

        // Complete setup with a real TOTP code for that secret.
        $_POST = ['username' => $this->user, 'session_id' => $this->session, 'code' => TOTPHelper::generateCode($secret)];
        $verifyResp = (new TwoFactorController())->verifySetup();
        $this->assertSame(200, $verifyResp->getStatusCode());
        $this->assertTrue(json_decode($verifyResp->getBody(), true)['success']);

        // Status now reflects it.
        $_GET = ['username' => $this->user, 'session_id' => $this->session];
        $status2 = json_decode((new TwoFactorController())->status()->getBody(), true);
        $this->assertTrue((bool) $status2['status']['enabled']);
    }

    /** Setup requires a real (registered) account + valid session. */
    public function testSetupRejectsBadSession(): void
    {
        $_POST = ['username' => $this->user, 'session_id' => 'nope'];
        $this->assertSame(403, (new TwoFactorController())->setup()->getStatusCode());
    }

    /** Once 2FA is on, login demands a code, then accepts a valid one. */
    public function testLoginStepUp(): void
    {
        // Enroll first.
        $_POST = ['username' => $this->user, 'session_id' => $this->session];
        $secret = json_decode((new TwoFactorController())->setup()->getBody(), true)['setup']['secret'];
        $_POST = ['username' => $this->user, 'session_id' => $this->session, 'code' => TOTPHelper::generateCode($secret)];
        (new TwoFactorController())->verifySetup();

        // Login without a code → twofa_required, session NOT bound.
        $_POST = ['username' => $this->user, 'password' => 'secret123', 'sessionId' => $this->session];
        $resp = (new AuthController())->login();
        $body = json_decode($resp->getBody(), true);
        $this->assertTrue($body['twofa_required'] ?? false);
        $this->assertFalse($body['success'] ?? true);

        // Wrong code → 401.
        $_POST = ['username' => $this->user, 'password' => 'secret123', 'sessionId' => $this->session, 'code' => '000000'];
        $this->assertSame(401, (new AuthController())->login()->getStatusCode());

        // Correct code → success.
        $_POST = ['username' => $this->user, 'password' => 'secret123', 'sessionId' => $this->session, 'code' => TOTPHelper::generateCode($secret)];
        $ok = (new AuthController())->login();
        $this->assertSame(200, $ok->getStatusCode());
        $this->assertTrue(json_decode($ok->getBody(), true)['success']);
    }
}
