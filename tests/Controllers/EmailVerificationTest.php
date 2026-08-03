<?php

namespace RadioChatBox\Tests\Controllers;

use PDO;
use PHPUnit\Framework\TestCase;
use Pramnos\Framework\Testing\TestDatabase;
use RadioChatBox\Controllers\AuthController;
use RadioChatBox\Services\EmailVerificationService;
use RadioChatBox\Services\SettingsService;
use RadioChatBox\Services\UserService;

/**
 * Email verification: registration issues a token when enabled, the verify
 * endpoint stamps the account verified (single-use), and resend is session- and
 * account-gated (and a no-op once verified).
 */
class EmailVerificationTest extends TestCase
{
    private PDO $pdo;
    private string $user;
    private string $session;
    private int $userId;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::connection();
        $this->user = 'ev_' . substr(bin2hex(random_bytes(5)), 0, 8);
        $this->session = 'evsess_' . substr(bin2hex(random_bytes(5)), 0, 8);
        $res = (new UserService())->createUser($this->user, 'secret123', 'simple_user', $this->user . '@example.com');
        $this->userId = (int) $res['user']['userid'];
        $this->pdo->prepare(
            'INSERT INTO presence_sessions (username, session_id, user_id, ip_address, last_heartbeat, joined_at)
             VALUES (?, ?, ?, ?, NOW(), NOW())'
        )->execute([$this->user, $this->session, $this->userId, '127.0.0.1']);
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare('DELETE FROM email_verifications WHERE userid = ?')->execute([$this->userId]);
        $this->pdo->prepare('DELETE FROM presence_sessions WHERE username = ?')->execute([$this->user]);
        $this->pdo->prepare('DELETE FROM users WHERE username = ?')->execute([$this->user]);
        $this->pdo->prepare("DELETE FROM settings WHERE setting = 'email_verification_enabled'")->execute();
        $_POST = [];
    }

    /** A valid token verifies the account and can't be reused. */
    public function testVerifyStampsAndIsSingleUse(): void
    {
        $verifier = new EmailVerificationService();
        $this->assertFalse($verifier->isVerified($this->userId));

        $token = $verifier->issue($this->userId);
        $_POST = ['token' => $token];
        $resp = (new AuthController())->verifyEmail();
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertTrue(json_decode($resp->getBody(), true)['success']);
        $this->assertTrue($verifier->isVerified($this->userId));

        // Single-use.
        $_POST = ['token' => $token];
        $this->assertSame(400, (new AuthController())->verifyEmail()->getStatusCode());
    }

    /** An invalid token is a 400. */
    public function testVerifyBadToken(): void
    {
        $_POST = ['token' => 'nope'];
        $this->assertSame(400, (new AuthController())->verifyEmail()->getStatusCode());
    }

    /** resend requires a valid session + account; is a no-op once verified. */
    public function testResendFlow(): void
    {
        // Bad session.
        $_POST = ['username' => $this->user, 'session_id' => 'wrong'];
        $this->assertSame(403, (new AuthController())->resendVerification()->getStatusCode());

        // Good session issues a token.
        $_POST = ['username' => $this->user, 'session_id' => $this->session];
        $this->assertSame(200, (new AuthController())->resendVerification()->getStatusCode());
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM email_verifications WHERE userid = ' . $this->userId . ' AND used_at IS NULL')->fetchColumn();
        $this->assertSame(1, $count);

        // Once verified, resend is a no-op reporting already_verified.
        (new EmailVerificationService())->verify((new EmailVerificationService())->issue($this->userId));
        $_POST = ['username' => $this->user, 'session_id' => $this->session];
        $body = json_decode((new AuthController())->resendVerification()->getBody(), true);
        $this->assertTrue($body['already_verified'] ?? false);
    }

    /** Registration with the feature on issues a verification token. */
    public function testRegistrationIssuesToken(): void
    {
        (new SettingsService())->set('self_registration_enabled', 'true');
        (new SettingsService())->set('email_verification_enabled', 'true');
        $newUser = 'evreg_' . substr(bin2hex(random_bytes(4)), 0, 8);
        try {
            $_POST = ['username' => $newUser, 'password' => 'secret123', 'email' => $newUser . '@example.com'];
            $resp = (new AuthController())->registerAccount();
            $this->assertSame(200, $resp->getStatusCode());
            $uid = (int) json_decode($resp->getBody(), true)['user']['userid'];
            $count = (int) $this->pdo->query('SELECT COUNT(*) FROM email_verifications WHERE userid = ' . $uid)->fetchColumn();
            $this->assertSame(1, $count);
            $this->pdo->prepare('DELETE FROM email_verifications WHERE userid = ?')->execute([$uid]);
            $this->pdo->prepare('DELETE FROM users WHERE username = ?')->execute([$newUser]);
        } finally {
            $this->pdo->prepare("DELETE FROM settings WHERE setting = 'self_registration_enabled'")->execute();
        }
    }
}
