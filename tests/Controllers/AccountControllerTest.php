<?php

namespace RadioChatBox\Tests\Controllers;

use PDO;
use PHPUnit\Framework\TestCase;
use Pramnos\Framework\Testing\TestDatabase;
use RadioChatBox\Controllers\AccountController;
use RadioChatBox\Services\ConsentService;

/**
 * The two account actions that had no endpoint: changing a password you still
 * know, and answering (or withdrawing) the newsletter question after
 * registration.
 *
 * Everything else the account panel offers — 2FA, passkeys, verification — was
 * already served; these fill the gaps and the overview draws the panel in one
 * request.
 */
class AccountControllerTest extends TestCase
{
    private PDO $pdo;
    private string $user;
    private string $session;
    private int $userId = 0;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::connection();
        $this->user = 'acct_' . substr(bin2hex(random_bytes(5)), 0, 8);
        $this->session = 'sess_' . bin2hex(random_bytes(6));

        $this->pdo->prepare(
            "INSERT INTO users (username, password, email, usertype, active, validated, regdate, modified)
             VALUES (?, ?, ?, 0, 1, 1, 0, 0)"
        )->execute([$this->user, password_hash('oldpassword1', PASSWORD_BCRYPT), $this->user . '@example.test']);
        $this->userId = (int) $this->pdo->query(
            'SELECT userid FROM users WHERE username = ' . $this->pdo->quote($this->user)
        )->fetchColumn();

        $this->pdo->prepare(
            'INSERT INTO presence_sessions (username, session_id, ip_address, user_id, last_heartbeat, joined_at)
             VALUES (?, ?, ?, ?, NOW(), NOW())'
        )->execute([$this->user, $this->session, '127.0.0.1', $this->userId]);
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare('DELETE FROM authserver.user_consents WHERE userid = ?')->execute([$this->userId]);
        $this->pdo->prepare('DELETE FROM presence_sessions WHERE session_id = ?')->execute([$this->session]);
        $this->pdo->prepare('DELETE FROM users WHERE username = ?')->execute([$this->user]);
        $_POST = [];
        $_GET = [];
    }

    private function storedHash(): string
    {
        return (string) $this->pdo->query(
            'SELECT password FROM users WHERE userid = ' . $this->userId
        )->fetchColumn();
    }

    /** The overview answers the whole panel in one request. */
    public function testOverviewDescribesTheAccount(): void
    {
        $_GET = ['username' => $this->user, 'session_id' => $this->session];

        $body = json_decode((new AccountController())->overview()->getBody(), true);

        $this->assertTrue($body['success']);
        $this->assertSame($this->user, $body['account']['username']);
        $this->assertSame($this->user . '@example.test', $body['account']['email']);
        $this->assertFalse($body['account']['email_verified']);
        $this->assertFalse($body['account']['marketing_opt_in']);
    }

    /** A session that is not live cannot read an account. */
    public function testOverviewRefusesAnUnknownSession(): void
    {
        $_GET = ['username' => $this->user, 'session_id' => 'not-a-session'];

        $this->assertSame(403, (new AccountController())->overview()->getStatusCode());
    }

    /** The happy path replaces the hash. */
    public function testPasswordChanges(): void
    {
        $before = $this->storedHash();
        $_POST = [
            'username' => $this->user,
            'session_id' => $this->session,
            'current_password' => 'oldpassword1',
            'new_password' => 'brandnewpass2',
        ];

        $response = (new AccountController())->changePassword();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotSame($before, $this->storedHash());
        $this->assertTrue(password_verify('brandnewpass2', $this->storedHash()));
    }

    /**
     * The current password is required even though the session is already
     * proven — a session can be a borrowed laptop, and this is the change that
     * would lock the owner out of their own account.
     */
    public function testPasswordRefusesWithoutTheCurrentOne(): void
    {
        $before = $this->storedHash();
        $_POST = [
            'username' => $this->user,
            'session_id' => $this->session,
            'current_password' => 'not-the-password',
            'new_password' => 'brandnewpass2',
        ];

        $response = (new AccountController())->changePassword();

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame($before, $this->storedHash(), 'the stored password must not move');
    }

    /** A short new password is refused before it is stored. */
    public function testPasswordEnforcesAMinimumLength(): void
    {
        $_POST = [
            'username' => $this->user,
            'session_id' => $this->session,
            'current_password' => 'oldpassword1',
            'new_password' => 'short',
        ];

        $this->assertSame(400, (new AccountController())->changePassword()->getStatusCode());
        $this->assertTrue(password_verify('oldpassword1', $this->storedHash()));
    }

    /**
     * Consent can be given and taken back, and withdrawing leaves the original
     * grant standing as history rather than deleting it.
     */
    public function testMarketingConsentCanBeGivenAndWithdrawn(): void
    {
        $consent = new ConsentService();

        $_POST = ['username' => $this->user, 'session_id' => $this->session, 'opt_in' => 'true'];
        $this->assertSame(200, (new AccountController())->marketing()->getStatusCode());
        $this->assertTrue($consent->has($this->userId, ConsentService::MARKETING_EMAIL));

        $_POST = ['username' => $this->user, 'session_id' => $this->session, 'opt_in' => 'false'];
        $this->assertSame(200, (new AccountController())->marketing()->getStatusCode());
        $this->assertFalse($consent->has($this->userId, ConsentService::MARKETING_EMAIL));

        $rows = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM authserver.user_consents WHERE userid = ' . $this->userId
        )->fetchColumn();
        $this->assertSame(2, $rows, 'the grant and the withdrawal are both on the record');
    }
}
