<?php

namespace RadioChatBox\Tests;

use Pramnos\Framework\Testing\TestDatabase;

use PHPUnit\Framework\TestCase;
use RadioChatBox\Services\UserService;
use Pramnos\Database\Database;

/**
 * Integration tests for UserService against the real (shared) database.
 *
 * These were rewritten from PDO-mock unit tests when UserService moved onto the
 * framework QueryBuilder in Phase 7: mocking the raw \PDO API no longer reflects
 * how the service talks to the database, and real-DB tests additionally catch
 * SQL/type issues the mocks hid (e.g. the boolean is_active handling below).
 *
 * Every test creates users with a unique random suffix and removes them in
 * tearDown, so it never disturbs the real admin accounts in the shared database.
 */
class UserServiceTest extends TestCase
{
    private UserService $userService;
    /** @var string[] Usernames created during the test, deleted in tearDown. */
    private array $created = [];
    private string $suffix;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userService = new UserService();
        $this->suffix = substr(bin2hex(random_bytes(4)), 0, 8);
    }

    protected function tearDown(): void
    {
        $pdo = TestDatabase::connection();
        foreach (array_unique($this->created) as $username) {
            $pdo->prepare('DELETE FROM users WHERE username = ?')->execute([$username]);
        }
        parent::tearDown();
    }

    /** A username unique to this test run; registered for cleanup. */
    private function uniqueUsername(string $base = 'utest'): string
    {
        $username = $base . '_' . $this->suffix . '_' . count($this->created);
        $this->created[] = $username;
        return $username;
    }

    // ========================================================================
    // CREATE USER TESTS
    // ========================================================================

    /**
     * A valid create inserts the row and returns the sanitized user (no password
     * hash), with the username and role echoed back from the RETURNING clause.
     */
    public function testCreateUserSuccess(): void
    {
        $username = $this->uniqueUsername();

        $result = $this->userService->createUser(
            $username,
            'password123',
            'administrator',
            $username . '@example.com',
            1
        );

        $this->assertTrue($result['success']);
        $this->assertEquals($username, $result['user']['username']);
        $this->assertEquals('administrator', $result['user']['role']);
        $this->assertArrayNotHasKey('password', $result['user']);
    }

    /** A username shorter than 3 characters is rejected before any DB write. */
    public function testCreateUserWithShortUsername(): void
    {
        $result = $this->userService->createUser('ab', 'password123', 'administrator');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('3-50 characters', $result['error']);
    }

    /** A password shorter than 8 characters is rejected before any DB write. */
    public function testCreateUserWithShortPassword(): void
    {
        $result = $this->userService->createUser($this->uniqueUsername(), 'pass', 'administrator');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('at least 8 characters', $result['error']);
    }

    /** An unknown role is rejected before any DB write. */
    public function testCreateUserWithInvalidRole(): void
    {
        $result = $this->userService->createUser($this->uniqueUsername(), 'password123', 'invalid_role');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Invalid role', $result['error']);
    }

    /**
     * Creating a second user with an existing username hits the unique constraint
     * (users_username_key). The framework returns false + a driver error string
     * rather than throwing, and createUser must translate the "duplicate key"
     * text into the friendly "Username already exists" message.
     */
    public function testCreateUserDuplicateUsernameFails(): void
    {
        $username = $this->uniqueUsername();

        $first = $this->userService->createUser($username, 'password123', 'moderator', $username . '@a.example');
        $this->assertTrue($first['success']);

        $second = $this->userService->createUser($username, 'password123', 'moderator', $username . '@b.example');
        $this->assertFalse($second['success']);
        $this->assertSame('Username already exists', $second['error']);
    }

    // ========================================================================
    // UPDATE USER TESTS
    // ========================================================================

    /** Updating allowed fields persists and returns the new values. */
    public function testUpdateUserSuccess(): void
    {
        $username = $this->uniqueUsername();
        $created = $this->userService->createUser($username, 'password123', 'administrator', $username . '@example.com');
        $id = (int) $created['user']['userid'];

        $result = $this->userService->updateUser($id, [
            'email' => 'newemail@example.com',
            'role'  => 'moderator',
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('moderator', $result['user']['role']);
        $this->assertEquals('newemail@example.com', $result['user']['email']);
    }

    /** An update with no recognised fields is a no-op error. */
    public function testUpdateUserWithNoFields(): void
    {
        $result = $this->userService->updateUser(999999, []);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('No valid fields', $result['error']);
    }

    // ========================================================================
    // DELETE USER TESTS
    // ========================================================================

    /** Deleting an existing user succeeds and the row is gone afterwards. */
    public function testDeleteUserSuccess(): void
    {
        $username = $this->uniqueUsername();
        $created = $this->userService->createUser($username, 'password123', 'moderator', $username . '@example.com');
        $id = (int) $created['user']['userid'];

        $result = $this->userService->deleteUser($id);

        $this->assertTrue($result['success']);
        $this->assertNull($this->userService->getUserById($id), 'the user must be gone after delete');
    }

    /** Deleting a non-existent id reports "not found" rather than succeeding. */
    public function testDeleteUserNotFound(): void
    {
        $result = $this->userService->deleteUser(999999);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not found', $result['error']);
    }

    // ========================================================================
    // GET USER TESTS
    // ========================================================================

    /** getUserById returns the sanitized row for an existing user. */
    public function testGetUserByIdSuccess(): void
    {
        $username = $this->uniqueUsername();
        $created = $this->userService->createUser($username, 'password123', 'administrator', $username . '@example.com');
        $id = (int) $created['user']['userid'];

        $user = $this->userService->getUserById($id);

        $this->assertIsArray($user);
        $this->assertEquals($username, $user['username']);
        $this->assertEquals('administrator', $user['role']);
        $this->assertArrayNotHasKey('password', $user);
    }

    /** getUserByUsername returns the sanitized row for an existing user. */
    public function testGetUserByUsernameSuccess(): void
    {
        $username = $this->uniqueUsername();
        $this->userService->createUser($username, 'password123', 'administrator', $username . '@example.com');

        $user = $this->userService->getUserByUsername($username);

        $this->assertIsArray($user);
        $this->assertEquals($username, $user['username']);
    }

    // ========================================================================
    // AUTHENTICATION TESTS
    // ========================================================================

    /** Correct credentials authenticate and return the user without its hash. */
    public function testAuthenticateSuccess(): void
    {
        $username = $this->uniqueUsername();
        $this->userService->createUser($username, 'password123', 'administrator', $username . '@example.com');

        $user = $this->userService->authenticate($username, 'password123');

        $this->assertIsArray($user);
        $this->assertEquals($username, $user['username']);
        $this->assertEquals('administrator', $user['role']);
        $this->assertArrayNotHasKey('password', $user);
    }

    /** A wrong password does not authenticate. */
    public function testAuthenticateWrongPassword(): void
    {
        $username = $this->uniqueUsername();
        $this->userService->createUser($username, 'password123', 'administrator', $username . '@example.com');

        $this->assertNull($this->userService->authenticate($username, 'wrongpassword'));
    }

    /**
     * An INACTIVE user must not authenticate even with the right password.
     *
     * Regression test for the boolean-column handling: under raw PDO+pgsql
     * is_active came back as the string 'f' (which is truthy, so the
     * "!is_active" guard never fired and disabled users could still log in).
     * The framework Result casts the column to a real bool, so the guard now
     * correctly rejects them. This pins that behaviour.
     */
    public function testAuthenticateInactiveUserIsRejected(): void
    {
        $username = $this->uniqueUsername();
        $created = $this->userService->createUser($username, 'password123', 'administrator', $username . '@example.com');
        $id = (int) $created['user']['userid'];

        $this->userService->updateUser($id, ['is_active' => false]);

        $this->assertNull(
            $this->userService->authenticate($username, 'password123'),
            'a deactivated user must not be able to log in'
        );
    }

    // ========================================================================
    // PERMISSION TESTS
    // ========================================================================

    public function testHasPermissionRoot(): void
    {
        $this->assertTrue($this->userService->hasPermission('root', 'view_private_messages'));
        $this->assertTrue($this->userService->hasPermission('root', 'manage_users'));
        $this->assertTrue($this->userService->hasPermission('root', 'create_root_users'));
    }

    public function testHasPermissionAdministrator(): void
    {
        $this->assertTrue($this->userService->hasPermission('administrator', 'view_private_messages'));
        $this->assertTrue($this->userService->hasPermission('administrator', 'manage_users'));
        $this->assertFalse($this->userService->hasPermission('administrator', 'create_root_users'));
    }

    public function testHasPermissionModerator(): void
    {
        $this->assertFalse($this->userService->hasPermission('moderator', 'view_private_messages'));
        $this->assertFalse($this->userService->hasPermission('moderator', 'manage_users'));
        $this->assertTrue($this->userService->hasPermission('moderator', 'view_messages'));
    }

    public function testHasPermissionSimpleUser(): void
    {
        $this->assertFalse($this->userService->hasPermission('simple_user', 'view_messages'));
        $this->assertFalse($this->userService->hasPermission('simple_user', 'manage_users'));
    }

    // ========================================================================
    // ROLE MANAGEMENT TESTS
    // ========================================================================

    public function testCanManageUserRoot(): void
    {
        $this->assertTrue($this->userService->canManageUser('root', 'root'));
        $this->assertTrue($this->userService->canManageUser('root', 'administrator'));
        $this->assertTrue($this->userService->canManageUser('root', 'moderator'));
    }

    public function testCanManageUserAdministrator(): void
    {
        $this->assertFalse($this->userService->canManageUser('administrator', 'root'));
        $this->assertTrue($this->userService->canManageUser('administrator', 'administrator'));
        $this->assertTrue($this->userService->canManageUser('administrator', 'moderator'));
    }

    public function testCanManageUserModerator(): void
    {
        $this->assertFalse($this->userService->canManageUser('moderator', 'root'));
        $this->assertFalse($this->userService->canManageUser('moderator', 'administrator'));
        $this->assertFalse($this->userService->canManageUser('moderator', 'moderator'));
    }

    public function testGetRoleLevel(): void
    {
        $this->assertEquals(3, $this->userService->getRoleLevel('root'));
        $this->assertEquals(2, $this->userService->getRoleLevel('administrator'));
        $this->assertEquals(1, $this->userService->getRoleLevel('moderator'));
        $this->assertEquals(0, $this->userService->getRoleLevel('simple_user'));
    }

    public function testGetAvailableRoles(): void
    {
        $roles = $this->userService->getAvailableRoles();

        $this->assertIsArray($roles);
        $this->assertContains('root', $roles);
        $this->assertContains('administrator', $roles);
        $this->assertContains('moderator', $roles);
        $this->assertContains('simple_user', $roles);
    }

    /**
     * authenticate() returns the sanitized user for the right password, and null
     * for a wrong password, an unknown identifier, and a deactivated account —
     * covering all four guard branches. The returned row never carries the hash.
     */
    public function testAuthenticateBranches(): void
    {
        $username = $this->uniqueUsername('auth');
        $password = 'Auth-Str0ng-Pass!';
        $this->userService->createUser($username, $password, 'moderator', $username . '@x.example');

        $ok = $this->userService->authenticate($username, $password);
        $this->assertIsArray($ok);
        $this->assertSame($username, $ok['username']);
        $this->assertArrayNotHasKey('password', $ok, 'the hash must never be returned');

        $this->assertNull($this->userService->authenticate($username, 'wrong'), 'wrong password fails');
        $this->assertNull($this->userService->authenticate('no_such_' . $this->suffix, $password), 'unknown user fails');

        // Deactivate and confirm the is_active guard rejects it.
        $id = (int) $this->userService->getUserByUsername($username)['userid'];
        $this->userService->updateUser($id, ['is_active' => false]);
        $this->assertNull($this->userService->authenticate($username, $password), 'inactive account fails');
    }
}
