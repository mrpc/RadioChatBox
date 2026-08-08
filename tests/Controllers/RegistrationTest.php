<?php

namespace RadioChatBox\Tests\Controllers;

use PDO;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\FlatCache;
use Pramnos\Framework\Testing\TestDatabase;
use RadioChatBox\Controllers\AuthController;
use RadioChatBox\Services\SettingsService;

/**
 * Public account self-registration: gated by a setting, validates
 * password-match / email, creates a simple_user, and rejects duplicates.
 */
class RegistrationTest extends TestCase
{
    private PDO $pdo;
    private string $user;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::connection();
        $this->user = 'reg_' . substr(bin2hex(random_bytes(5)), 0, 8);
        (new SettingsService())->set('self_registration_enabled', 'true');
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare('DELETE FROM users WHERE username = ?')->execute([$this->user]);
        $this->pdo->prepare("DELETE FROM settings WHERE setting = 'self_registration_enabled'")->execute();
        FlatCache::default()->clear();
        $_POST = [];
    }

    /** With the feature off, registration is 403. */
    public function testDisabledReturns403(): void
    {
        (new SettingsService())->set('self_registration_enabled', 'false');
        $_POST = ['username' => $this->user, 'password' => 'secret123'];
        $this->assertSame(403, (new AuthController())->registerAccount()->getStatusCode());
    }

    /** The happy path creates a simple_user account. */
    public function testRegistersNewUser(): void
    {
        $_POST = ['username' => $this->user, 'password' => 'secret123', 'password_confirm' => 'secret123', 'email' => 'a@b.com'];
        $response = (new AuthController())->registerAccount();
        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertSame($this->user, $body['user']['username']);

        $usertype = $this->pdo->query('SELECT usertype FROM users WHERE username = ' . $this->pdo->quote($this->user))->fetchColumn();
        $this->assertSame(0, (int) $usertype, 'a fresh account is a plain simple_user');
    }

    /**
     * A station that verifies addresses refuses an account without one.
     *
     * Otherwise the account is created permanently unverified, cannot reset its
     * own password, and never receives a single mail the feature exists to send.
     */
    public function testEmailIsRequiredWhenVerificationIsOn(): void
    {
        (new SettingsService())->set('email_verification_enabled', 'true');
        try {
            $_POST = ['username' => $this->user, 'password' => 'secret123', 'password_confirm' => 'secret123'];
            $response = (new AuthController())->registerAccount();

            $this->assertSame(400, $response->getStatusCode());
            $this->assertStringContainsString('email', strtolower(json_decode($response->getBody(), true)['error']));
            $this->assertFalse(
                (bool) $this->pdo->query('SELECT 1 FROM users WHERE username = ' . $this->pdo->quote($this->user))->fetchColumn(),
                'the account must not be created'
            );
        } finally {
            $this->pdo->prepare("DELETE FROM settings WHERE setting = 'email_verification_enabled'")->execute();
            FlatCache::default()->clear();
        }
    }

    /** With verification off, the address stays optional. */
    public function testEmailStaysOptionalWhenVerificationIsOff(): void
    {
        (new SettingsService())->set('email_verification_enabled', 'false');
        $_POST = ['username' => $this->user, 'password' => 'secret123', 'password_confirm' => 'secret123'];
        $this->assertSame(200, (new AuthController())->registerAccount()->getStatusCode());
    }

    /**
     * Marketing consent is recorded only when asked for, and dated — silence is
     * not consent.
     */
    public function testMarketingConsentIsRecordedOnlyWhenGiven(): void
    {
        $_POST = [
            'username' => $this->user,
            'password' => 'secret123',
            'password_confirm' => 'secret123',
            'email' => 'a@b.com',
            'marketing_opt_in' => true,
        ];
        $body = json_decode((new AuthController())->registerAccount()->getBody(), true);
        $userId = (int) ($body['user']['userid'] ?? $body['user']['id'] ?? 0);
        $this->assertGreaterThan(0, $userId);

        $row = $this->pdo->query(
            'SELECT granted, legal_basis FROM authserver.user_consents
              WHERE userid = ' . $userId . " AND consent_type = 'marketing_email'"
        )->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($row, 'the grant must be on the record');
        $this->assertSame(1, (int) $row['granted']);
        $this->assertSame('consent', $row['legal_basis']);

        $this->pdo->prepare('DELETE FROM authserver.user_consents WHERE userid = ?')->execute([$userId]);
    }

    /** No checkbox, no row: an unticked box must not become a grant. */
    public function testNoMarketingConsentRecordedWhenNotAsked(): void
    {
        $_POST = [
            'username' => $this->user,
            'password' => 'secret123',
            'password_confirm' => 'secret123',
            'email' => 'a@b.com',
        ];
        $body = json_decode((new AuthController())->registerAccount()->getBody(), true);
        $userId = (int) ($body['user']['userid'] ?? $body['user']['id'] ?? 0);

        $count = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM authserver.user_consents WHERE userid = ' . $userId
        )->fetchColumn();
        $this->assertSame(0, $count);
    }

    /** Mismatched passwords are a 400. */
    public function testPasswordMismatch(): void
    {
        $_POST = ['username' => $this->user, 'password' => 'secret123', 'password_confirm' => 'different'];
        $this->assertSame(400, (new AuthController())->registerAccount()->getStatusCode());
    }

    /** An invalid email is a 400. */
    public function testInvalidEmail(): void
    {
        $_POST = ['username' => $this->user, 'password' => 'secret123', 'email' => 'not-an-email'];
        $this->assertSame(400, (new AuthController())->registerAccount()->getStatusCode());
    }

    /** A short password is rejected (UserService rule) with a 400. */
    public function testShortPassword(): void
    {
        $_POST = ['username' => $this->user, 'password' => 'short'];
        $this->assertSame(400, (new AuthController())->registerAccount()->getStatusCode());
    }

    /** Registering an existing username is a 409 conflict. */
    public function testDuplicateUsername(): void
    {
        $_POST = ['username' => $this->user, 'password' => 'secret123'];
        $this->assertSame(200, (new AuthController())->registerAccount()->getStatusCode());

        $_POST = ['username' => $this->user, 'password' => 'secret123'];
        $this->assertSame(409, (new AuthController())->registerAccount()->getStatusCode());
    }
}
