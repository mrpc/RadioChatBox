<?php

namespace RadioChatBox\Tests\Controllers;

use PDO;
use PHPUnit\Framework\TestCase;
use Pramnos\Framework\Testing\TestDatabase;
use RadioChatBox\Controllers\AuthController;
use RadioChatBox\Services\PasswordResetService;
use RadioChatBox\Services\UserService;

/**
 * Self-service password reset: forgot issues a single-use token (and never
 * reveals whether an account exists); reset sets a new password with a valid
 * token, rejects weak/mismatched passwords and expired/used tokens.
 */
class PasswordResetTest extends TestCase
{
    private PDO $pdo;
    private string $user;
    private int $userId;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::connection();
        $this->user = 'pwr_' . substr(bin2hex(random_bytes(5)), 0, 8);
        $res = (new UserService())->createUser($this->user, 'oldpassword', 'simple_user', $this->user . '@example.com');
        $this->userId = (int) $res['user']['userid'];
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare('DELETE FROM password_resets WHERE userid = ?')->execute([$this->userId]);
        $this->pdo->prepare('DELETE FROM users WHERE username = ?')->execute([$this->user]);
        $_POST = [];
    }

    /** forgot always returns a generic success, and issues a token for a real user. */
    public function testForgotIssuesTokenAndIsGeneric(): void
    {
        $_POST = ['identifier' => $this->user];
        $response = (new AuthController())->forgotPassword();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue(json_decode($response->getBody(), true)['success']);

        $count = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM password_resets WHERE userid = ' . $this->userId . ' AND used_at IS NULL'
        )->fetchColumn();
        $this->assertSame(1, $count, 'a reset token was issued');
    }

    /** forgot for an unknown identifier still returns generic success (no enumeration). */
    public function testForgotUnknownIsGeneric(): void
    {
        $_POST = ['identifier' => 'no_such_user_' . bin2hex(random_bytes(4))];
        $response = (new AuthController())->forgotPassword();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue(json_decode($response->getBody(), true)['success']);
    }

    /** A valid token resets the password and can't be reused. */
    public function testResetWithValidToken(): void
    {
        $token = (new PasswordResetService())->issue($this->userId);

        $_POST = ['token' => $token, 'password' => 'brandnew123', 'password_confirm' => 'brandnew123'];
        $response = (new AuthController())->resetPassword();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue(json_decode($response->getBody(), true)['success']);

        // The new password authenticates; the old one no longer does.
        $this->assertNotNull((new UserService())->authenticate($this->user, 'brandnew123'));
        $this->assertNull((new UserService())->authenticate($this->user, 'oldpassword'));

        // The token is single-use.
        $_POST = ['token' => $token, 'password' => 'another123', 'password_confirm' => 'another123'];
        $this->assertSame(400, (new AuthController())->resetPassword()->getStatusCode());
    }

    /** reset rejects a bad token, weak password, and mismatch. */
    public function testResetValidation(): void
    {
        $_POST = ['token' => 'not-a-real-token', 'password' => 'brandnew123', 'password_confirm' => 'brandnew123'];
        $this->assertSame(400, (new AuthController())->resetPassword()->getStatusCode());

        $token = (new PasswordResetService())->issue($this->userId);
        $_POST = ['token' => $token, 'password' => 'short', 'password_confirm' => 'short'];
        $this->assertSame(400, (new AuthController())->resetPassword()->getStatusCode());

        $_POST = ['token' => $token, 'password' => 'brandnew123', 'password_confirm' => 'different'];
        $this->assertSame(400, (new AuthController())->resetPassword()->getStatusCode());
    }

    /** Issuing a new token invalidates the previous unused one. */
    public function testIssueInvalidatesPrevious(): void
    {
        $svc = new PasswordResetService();
        $first = $svc->issue($this->userId);
        $svc->issue($this->userId); // invalidates $first

        $this->assertNull($svc->resolve($first), 'the old token is no longer valid');
    }
}
