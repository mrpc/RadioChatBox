<?php

namespace RadioChatBox\Tests\Controllers;

use PDO;
use PHPUnit\Framework\TestCase;
use Pramnos\Framework\Testing\TestDatabase;
use RadioChatBox\Controllers\PasskeyController;
use RadioChatBox\Services\SettingsService;
use RadioChatBox\Services\UserService;

/**
 * WebAuthn passkey endpoints. The full ceremony needs a real authenticator, so
 * these cover what can run headless: options generation for an account, session/
 * account gating, and the empty credential list. The Redis-backed challenge store
 * (RcbPasskeyService) is exercised implicitly by the options calls.
 */
class PasskeyControllerTest extends TestCase
{
    private PDO $pdo;
    private string $user;
    private string $session;
    private int $userId;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::connection();
        $suffix = substr(bin2hex(random_bytes(5)), 0, 8);
        $this->user = 'pk_' . $suffix;
        $this->session = 'pksess_' . $suffix;

        // RP config for headless option generation.
        (new SettingsService())->set('passkey_rp_id', 'localhost');
        (new SettingsService())->set('passkey_origins', 'http://localhost');

        $res = (new UserService())->createUser($this->user, 'secret123', 'simple_user');
        $this->userId = (int) $res['user']['userid'];
        $this->pdo->prepare(
            'INSERT INTO presence_sessions (username, session_id, user_id, ip_address, last_heartbeat, joined_at)
             VALUES (?, ?, ?, ?, NOW(), NOW())'
        )->execute([$this->user, $this->session, $this->userId, '127.0.0.1']);
    }

    protected function tearDown(): void
    {
        try { $this->pdo->prepare('DELETE FROM authserver.passkey_credentials WHERE userid = ?')->execute([$this->userId]); } catch (\Throwable) {}
        $this->pdo->prepare('DELETE FROM presence_sessions WHERE username = ?')->execute([$this->user]);
        $this->pdo->prepare('DELETE FROM users WHERE username = ?')->execute([$this->user]);
        $this->pdo->prepare("DELETE FROM settings WHERE setting LIKE 'passkey_%'")->execute();
        $_POST = [];
        $_GET = [];
    }

    /** register/options returns a WebAuthn creation-options JSON with a challenge. */
    public function testRegisterOptions(): void
    {
        $_POST = ['username' => $this->user, 'session_id' => $this->session];
        $response = (new PasskeyController())->registerOptions();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('application/json', (string) $response->getHeaderLine('Content-Type'));
        $doc = json_decode($response->getBody(), true);
        $this->assertIsArray($doc);
        $this->assertArrayHasKey('challenge', $doc);
    }

    /** register/options rejects a bad session. */
    public function testRegisterOptionsRejectsBadSession(): void
    {
        $_POST = ['username' => $this->user, 'session_id' => 'nope'];
        $this->assertSame(403, (new PasskeyController())->registerOptions()->getStatusCode());
    }

    /** login/options returns options for a known account and 404 for unknown. */
    public function testLoginOptions(): void
    {
        $_POST = ['username' => $this->user];
        $ok = (new PasskeyController())->loginOptions();
        $this->assertSame(200, $ok->getStatusCode());
        $this->assertArrayHasKey('challenge', json_decode($ok->getBody(), true));

        $_POST = ['username' => 'does_not_exist_' . bin2hex(random_bytes(4))];
        $this->assertSame(404, (new PasskeyController())->loginOptions()->getStatusCode());
    }

    /** A new account has no passkeys yet. */
    public function testListEmpty(): void
    {
        $_GET = ['username' => $this->user, 'session_id' => $this->session];
        $response = (new PasskeyController())->list();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], json_decode($response->getBody(), true)['passkeys']);
    }

    /** register (finish) requires a credential payload. */
    public function testRegisterRequiresCredential(): void
    {
        $_POST = ['username' => $this->user, 'session_id' => $this->session];
        $this->assertSame(400, (new PasskeyController())->register()->getStatusCode());
    }
}
