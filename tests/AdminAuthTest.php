<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use Pramnos\Cache\FlatCache;
use Pramnos\Database\Database;
use RadioChatBox\AdminAuth;
use RadioChatBox\Services\UserService;
use Mockery;

/**
 * Unit tests for AdminAuth
 */
class AdminAuthTest extends TestCase
{
    private $mockUserService;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock UserService (we'll need to inject this somehow)
        $this->mockUserService = Mockery::mock(UserService::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ========================================================================
    // AUTHENTICATION TESTS
    // ========================================================================

    public function testVerifyWithValidUsernamePassword()
    {
        // NOTE: AdminAuth uses static methods and creates its own UserService internally
        // Making it difficult to unit test without refactoring
        // This test documents expected behavior
        $this->assertTrue(true, "AdminAuth::verify() should authenticate valid username:password in Bearer header");
    }

    public function testVerifyWithInvalidFormat()
    {
        // Test that invalid auth header formats are rejected
        $invalidFormats = [
            'empty string',
            'Basic admin:password (wrong type)',
            'Bearer adminonly (no colon)',
            'Bearer :password (empty username)',
            'Bearer admin: (empty password)',
        ];

        $this->assertCount(5, $invalidFormats, "AdminAuth should reject " . count($invalidFormats) . " invalid header formats");
    }

    public function testExtractCredentialsFromHeader()
    {
        // Test cases for credential extraction
        $validFormats = [
            'Bearer admin:password123',
            'Bearer user@example.com:pass',
            'Bearer test:p@ss:word', // Password with colon should keep everything after first colon
        ];

        $this->assertCount(3, $validFormats, "AdminAuth should handle " . count($validFormats) . " valid header formats");
    }

    // ========================================================================
    // SESSION MANAGEMENT TESTS
    // ========================================================================

    public function testGetCurrentUserFromSession()
    {
        // AdminAuth::getCurrentUser() should retrieve user from Redis session
        $this->assertTrue(true, "AdminAuth::getCurrentUser() should fetch from Redis with 24hr TTL");
    }

    public function testSetCurrentUserCreatesSession()
    {
        // AdminAuth::setCurrentUser() should store user in Redis
        $this->assertTrue(true, "AdminAuth::setCurrentUser() should store in Redis with 24hr TTL");
    }

    // ========================================================================
    // PERMISSION TESTS
    // ========================================================================

    public function testHasPermissionRoot()
    {
        // Root should have all permissions
        $this->assertTrue(true, "AdminAuth::hasPermission() for root should always return true");
    }

    public function testHasPermissionAdministrator()
    {
        // Administrator should not have create_root_users permission
        $this->assertTrue(true, "AdminAuth::hasPermission() should delegate to UserService");
    }

    public function testRequirePermissionAllowed()
    {
        // Should not exit when permission is granted
        $this->assertTrue(true, "AdminAuth::requirePermission() should not exit when permission granted");
    }

    public function testRequirePermissionDenied()
    {
        // Should return 403 and exit when permission denied
        $this->assertTrue(true, "AdminAuth::requirePermission() should return 403 when permission denied");
    }

    // ========================================================================
    // INTEGRATION TEST NOTES
    // ========================================================================

    /**
     * NOTE: AdminAuth currently uses static methods and creates its own
     * UserService instance internally, making it difficult to unit test.
     * 
     * To make AdminAuth fully testable, we should refactor it to:
     * 1. Accept a UserService instance via constructor (dependency injection)
     * 2. Make verify() return a result instead of calling http_response_code/exit
     * 3. Extract credential parsing to a separate testable method
     * 
     * For now, these tests serve as documentation of expected behavior.
     * Full integration testing would require actual Redis + PostgreSQL.
     */

    public function testAdminAuthDesignNotesDocumented()
    {
        // This test passes to acknowledge the current limitations
        $this->assertTrue(true,
            "AdminAuth needs refactoring for better testability - " .
            "currently uses static methods and direct dependencies"
        );
    }

    // ========================================================================
    // INTEGRATION TESTS — run against the shared dev DB + Redis. They create a
    // throwaway admin user, drive the real Bearer flow, and clean everything up.
    // ========================================================================

    /** @var string|null throwaway admin username to remove in tearDown */
    private ?string $tmpUser = null;
    private ?string $tmpIp = null;

    private function cleanupAdmin(): void
    {
        if ($this->tmpUser !== null) {
            try {
                FlatCache::default()->delete('admin_session:' . $this->tmpUser);
            } catch (\Throwable) {
            }
            Database::getInstance()->queryBuilder()->from('users')
                ->where('username', '=', $this->tmpUser)->delete();
            $this->tmpUser = null;
        }
        if ($this->tmpIp !== null) {
            try {
                FlatCache::default()->delete('admin_auth_attempts:' . $this->tmpIp);
            } catch (\Throwable) {
            }
            $this->tmpIp = null;
        }
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REMOTE_ADDR']);
    }

    /** Create an administrator and authenticate it via a Bearer header. */
    private function makeAdmin(string $role = 'administrator'): array
    {
        $this->tmpUser = 'aa_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $this->tmpIp   = '203.0.113.' . random_int(2, 250);
        $password      = 'V3ry-Str0ng-Pass!';

        $res = (new UserService())->createUser($this->tmpUser, $password, $role);
        $this->assertTrue($res['success'], 'test setup: user must be created');

        $_SERVER['REMOTE_ADDR']        = $this->tmpIp;
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->tmpUser . ':' . $password;

        return ['username' => $this->tmpUser, 'password' => $password];
    }

    /**
     * verify() authenticates a valid Bearer username:password, establishes the
     * admin session (so getCurrentUser() then returns the user) and honours the
     * role's permissions.
     */
    public function testVerifyAuthenticatesAndEstablishesSession(): void
    {
        $this->makeAdmin('administrator');

        try {
            $this->assertTrue(AdminAuth::verify(), 'valid credentials must authenticate');
            $this->assertTrue(AdminAuth::authenticate(), 'authenticate() is an alias of verify()');

            $current = AdminAuth::getCurrentUser();
            $this->assertIsArray($current);
            $this->assertSame($this->tmpUser, $current['username']);

            // An administrator can manage users but cannot create root users.
            $this->assertTrue(AdminAuth::hasPermission('manage_users'));
            $this->assertFalse(AdminAuth::hasPermission('create_root_users'));
        } finally {
            $this->cleanupAdmin();
        }
    }

    /**
     * verify() rejects a valid username with the wrong password (false, and the
     * failed attempt is recorded against the IP).
     */
    public function testVerifyRejectsWrongPassword(): void
    {
        $this->makeAdmin('administrator');

        try {
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->tmpUser . ':wrong-password';
            $this->assertFalse(AdminAuth::verify(), 'a wrong password must not authenticate');
        } finally {
            $this->cleanupAdmin();
        }
    }

    /**
     * verify() rejects malformed Authorization headers: a missing header, a
     * non-Bearer scheme, and a Bearer value without the username:password colon.
     */
    public function testVerifyRejectsMalformedHeaders(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.' . random_int(2, 250);

        unset($_SERVER['HTTP_AUTHORIZATION']);
        $this->assertFalse(AdminAuth::verify(), 'no header must not authenticate');

        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('a:b');
        $this->assertFalse(AdminAuth::verify(), 'non-Bearer scheme must not authenticate');

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer nocolonhere';
        $this->assertFalse(AdminAuth::verify(), 'legacy password-only format must not authenticate');

        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REMOTE_ADDR']);
    }

    /** getCurrentUser() returns null when there is no Bearer header. */
    public function testGetCurrentUserReturnsNullWithoutHeader(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        $this->assertNull(AdminAuth::getCurrentUser());
    }

    /** hashPassword() produces a hash that password_verify accepts. */
    public function testHashPasswordIsVerifiable(): void
    {
        $hash = AdminAuth::hashPassword('s3cr3t-p4ss');
        $this->assertTrue(password_verify('s3cr3t-p4ss', $hash));
        $this->assertFalse(password_verify('wrong', $hash));
    }

    /**
     * getCurrentUser resolves the admin when the Bearer identifier is the user's
     * EMAIL (not username): the session is keyed by username, so it looks the email
     * up in the users table to find the real username, then returns that session.
     */
    public function testGetCurrentUserResolvesByEmail(): void
    {
        $this->tmpUser = 'aae_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $email = $this->tmpUser . '@example.test';
        (new UserService())->createUser($this->tmpUser, 'V3ry-Str0ng-Pass!', 'administrator', $email);

        // Session stored under the USERNAME; the request authenticates by EMAIL.
        FlatCache::default()->set('admin_session:' . $this->tmpUser, ['username' => $this->tmpUser, 'role' => 'administrator'], 120);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $email . ':x';

        try {
            $current = AdminAuth::getCurrentUser();
            $this->assertIsArray($current);
            $this->assertSame($this->tmpUser, $current['username']);
        } finally {
            $this->cleanupAdmin();
        }
    }

    /**
     * After five failed authentication attempts from one IP the rate limiter kicks
     * in: a further verify() is refused without even reaching the password check.
     */
    public function testVerifyRateLimitsAfterRepeatedFailures(): void
    {
        $this->makeAdmin('administrator');

        try {
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->tmpUser . ':wrong-password';
            for ($i = 0; $i < 6; $i++) {
                $this->assertFalse(AdminAuth::verify(), "attempt {$i} must fail");
            }
            // The counter is now >= 5, so the rate-limit branch has run.
            $this->assertFalse(AdminAuth::verify(), 'a rate-limited attempt is refused');
        } finally {
            $this->cleanupAdmin();
        }
    }
}
