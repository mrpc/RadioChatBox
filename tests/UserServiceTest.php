<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use RadioChatBox\UserService;
use RadioChatBox\Database;
use Mockery;

/**
 * Unit tests for UserService
 */
class UserServiceTest extends TestCase
{
    private $userService;
    private $mockPdo;
    private $mockRedis;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Mock PDO
        $this->mockPdo = Mockery::mock(\PDO::class);
        
        // Mock Redis (use stdClass to avoid Redis extension requirement in tests)
        $this->mockRedis = Mockery::mock('Redis');
        
        // Inject mocks into Database singleton
        Database::setPDO($this->mockPdo);
        Database::setRedis($this->mockRedis);
        
        $this->userService = new UserService();
    }

    protected function tearDown(): void
    {
        // Reset Database singletons
        Database::reset();
        Mockery::close();
        parent::tearDown();
    }

    // ========================================================================
    // CREATE USER TESTS
    // ========================================================================

    public function testCreateUserSuccess()
    {
        $mockStmt = Mockery::mock(\PDOStatement::class);
        $mockStmt->shouldReceive('execute')
            ->once()
            ->withArgs(function($args) {
                // Verify all parameters except password_hash (which is dynamic)
                return $args['username'] === 'testuser'
                    && isset($args['password_hash'])
                    && str_starts_with($args['password_hash'], '$2y$')
                    && $args['role'] === 'administrator'
                    && $args['email'] === 'test@example.com'
                    && $args['created_by'] === 1;
            })
            ->andReturn(true);
        
        $mockStmt->shouldReceive('fetch')
            ->once()
            ->with(\PDO::FETCH_ASSOC)
            ->andReturn([
                'id' => 2,
                'username' => 'testuser',
                'role' => 'administrator',
                'email' => 'test@example.com',
                'created_at' => '2025-11-12 10:00:00'
            ]);

        $this->mockPdo->shouldReceive('prepare')
            ->once()
            ->andReturn($mockStmt);
        
        // Mock Redis cache clear (matches any key ending with these patterns)
        $this->mockRedis->shouldReceive('del')
            ->with(Mockery::pattern('/admin_users:list:active$/'))
            ->andReturn(1);
        $this->mockRedis->shouldReceive('del')
            ->with(Mockery::pattern('/admin_users:list:all$/'))
            ->andReturn(1);

        $result = $this->userService->createUser(
            'testuser',
            'password123',
            'administrator',
            'test@example.com',
            1
        );

        $this->assertTrue($result['success']);
        $this->assertEquals('testuser', $result['user']['username']);
        $this->assertEquals('administrator', $result['user']['role']);
        $this->assertArrayNotHasKey('password_hash', $result['user']);
    }

    public function testCreateUserWithShortUsername()
    {
        $result = $this->userService->createUser('ab', 'password123', 'administrator');
        
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('3-50 characters', $result['error']);
    }

    public function testCreateUserWithShortPassword()
    {
        $result = $this->userService->createUser('testuser', 'pass', 'administrator');
        
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('at least 8 characters', $result['error']);
    }

    public function testCreateUserWithInvalidRole()
    {
        $result = $this->userService->createUser('testuser', 'password123', 'invalid_role');
        
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Invalid role', $result['error']);
    }

    public function testCreateUserDatabaseError()
    {
        $mockStmt = Mockery::mock(\PDOStatement::class);
        $mockStmt->shouldReceive('execute')
            ->once()
            ->andThrow(new \PDOException('Connection error'));

        $this->mockPdo->shouldReceive('prepare')
            ->once()
            ->andReturn($mockStmt);

        $result = $this->userService->createUser('testuser', 'password123', 'moderator');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Database error', $result['error']);
    }

    // ========================================================================
    // UPDATE USER TESTS
    // ========================================================================

    public function testUpdateUserSuccess()
    {
        $mockStmt = Mockery::mock(\PDOStatement::class);
        $mockStmt->shouldReceive('execute')
            ->once()
            ->andReturn(true);
        
        $mockStmt->shouldReceive('fetch')
            ->once()
            ->with(\PDO::FETCH_ASSOC)
            ->andReturn([
                'id' => 2,
                'username' => 'testuser',
                'role' => 'moderator',
                'email' => 'newemail@example.com',
                'is_active' => true,
                'updated_at' => '2025-11-12 11:00:00'
            ]);

        $this->mockPdo->shouldReceive('prepare')
            ->once()
            ->andReturn($mockStmt);
        
        // Mock Redis cache clear (matches any key ending with these patterns)
        $this->mockRedis->shouldReceive('del')
            ->with(Mockery::pattern('/admin_users:list:active$/'))
            ->andReturn(1);
        $this->mockRedis->shouldReceive('del')
            ->with(Mockery::pattern('/admin_users:list:all$/'))
            ->andReturn(1);
        $this->mockRedis->shouldReceive('del')
            ->with(Mockery::pattern('/admin_session:testuser$/'))
            ->andReturn(1);

        $result = $this->userService->updateUser(2, [
            'email' => 'newemail@example.com',
            'role' => 'moderator'
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('moderator', $result['user']['role']);
    }

    public function testUpdateUserWithNoFields()
    {
        $result = $this->userService->updateUser(2, []);
        
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('No valid fields', $result['error']);
    }

    // ========================================================================
    // DELETE USER TESTS
    // ========================================================================

    public function testDeleteUserSuccess()
    {
        $mockStmt1 = Mockery::mock(\PDOStatement::class);
        $mockStmt1->shouldReceive('execute')
            ->once()
            ->with(['id' => 2])
            ->andReturn(true);
        $mockStmt1->shouldReceive('fetch')
            ->once()
            ->with(\PDO::FETCH_ASSOC)
            ->andReturn(['username' => 'testuser']);

        $mockStmt2 = Mockery::mock(\PDOStatement::class);
        $mockStmt2->shouldReceive('execute')
            ->once()
            ->with(['id' => 2])
            ->andReturn(true);

        $this->mockPdo->shouldReceive('prepare')
            ->twice()
            ->andReturn($mockStmt1, $mockStmt2);
        
        // Mock Redis cache clear (matches any key ending with these patterns)
        $this->mockRedis->shouldReceive('del')
            ->with(Mockery::pattern('/admin_users:list:active$/'))
            ->andReturn(1);
        $this->mockRedis->shouldReceive('del')
            ->with(Mockery::pattern('/admin_users:list:all$/'))
            ->andReturn(1);
        $this->mockRedis->shouldReceive('del')
            ->with(Mockery::pattern('/admin_session:testuser$/'))
            ->andReturn(1);

        $result = $this->userService->deleteUser(2);

        $this->assertTrue($result['success']);
    }

    public function testDeleteUserNotFound()
    {
        $mockStmt = Mockery::mock(\PDOStatement::class);
        $mockStmt->shouldReceive('execute')
            ->once()
            ->andReturn(true);
        $mockStmt->shouldReceive('fetch')
            ->once()
            ->andReturn(false);

        $this->mockPdo->shouldReceive('prepare')
            ->once()
            ->andReturn($mockStmt);

        $result = $this->userService->deleteUser(999);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not found', $result['error']);
    }

    // ========================================================================
    // GET USER TESTS
    // ========================================================================

    public function testGetUserByIdSuccess()
    {
        $mockStmt = Mockery::mock(\PDOStatement::class);
        $mockStmt->shouldReceive('execute')
            ->once()
            ->with(['id' => 1])
            ->andReturn(true);
        $mockStmt->shouldReceive('fetch')
            ->once()
            ->with(\PDO::FETCH_ASSOC)
            ->andReturn([
                'id' => 1,
                'username' => 'admin',
                'role' => 'root',
                'email' => 'admin@example.com',
                'is_active' => true,
                'created_at' => '2025-11-12 10:00:00',
                'updated_at' => '2025-11-12 10:00:00',
                'last_login' => '2025-11-12 11:00:00'
            ]);

        $this->mockPdo->shouldReceive('prepare')
            ->once()
            ->andReturn($mockStmt);

        $user = $this->userService->getUserById(1);

        $this->assertIsArray($user);
        $this->assertEquals('admin', $user['username']);
        $this->assertEquals('root', $user['role']);
    }

    public function testGetUserByUsernameSuccess()
    {
        $mockStmt = Mockery::mock(\PDOStatement::class);
        $mockStmt->shouldReceive('execute')
            ->once()
            ->with(['username' => 'testuser'])
            ->andReturn(true);
        $mockStmt->shouldReceive('fetch')
            ->once()
            ->with(\PDO::FETCH_ASSOC)
            ->andReturn([
                'id' => 2,
                'username' => 'testuser',
                'role' => 'administrator',
                'email' => null,
                'is_active' => true,
                'created_at' => '2025-11-12 10:00:00',
                'updated_at' => '2025-11-12 10:00:00',
                'last_login' => null
            ]);

        $this->mockPdo->shouldReceive('prepare')
            ->once()
            ->andReturn($mockStmt);

        $user = $this->userService->getUserByUsername('testuser');

        $this->assertIsArray($user);
        $this->assertEquals('testuser', $user['username']);
    }

    // ========================================================================
    // AUTHENTICATION TESTS
    // ========================================================================

    public function testAuthenticateSuccess()
    {
        $passwordHash = password_hash('password123', PASSWORD_DEFAULT);
        
        $mockStmt1 = Mockery::mock(\PDOStatement::class);
        $mockStmt1->shouldReceive('execute')
            ->once()
            ->with(['username' => 'testuser'])
            ->andReturn(true);
        $mockStmt1->shouldReceive('fetch')
            ->once()
            ->with(\PDO::FETCH_ASSOC)
            ->andReturn([
                'id' => 2,
                'username' => 'testuser',
                'password_hash' => $passwordHash,
                'role' => 'administrator',
                'email' => 'test@example.com',
                'is_active' => true
            ]);

        $mockStmt2 = Mockery::mock(\PDOStatement::class);
        $mockStmt2->shouldReceive('execute')
            ->once()
            ->andReturn(true);

        $this->mockPdo->shouldReceive('prepare')
            ->twice()
            ->andReturn($mockStmt1, $mockStmt2);

        $user = $this->userService->authenticate('testuser', 'password123');

        $this->assertIsArray($user);
        $this->assertEquals('testuser', $user['username']);
        $this->assertEquals('administrator', $user['role']);
        $this->assertArrayNotHasKey('password_hash', $user);
    }

    public function testAuthenticateWrongPassword()
    {
        $passwordHash = password_hash('password123', PASSWORD_DEFAULT);
        
        $mockStmt = Mockery::mock(\PDOStatement::class);
        $mockStmt->shouldReceive('execute')
            ->once()
            ->andReturn(true);
        $mockStmt->shouldReceive('fetch')
            ->once()
            ->andReturn([
                'id' => 2,
                'username' => 'testuser',
                'password_hash' => $passwordHash,
                'role' => 'administrator',
                'email' => 'test@example.com',
                'is_active' => true
            ]);

        $this->mockPdo->shouldReceive('prepare')
            ->once()
            ->andReturn($mockStmt);

        $user = $this->userService->authenticate('testuser', 'wrongpassword');

        $this->assertNull($user);
    }

    public function testAuthenticateInactiveUser()
    {
        $passwordHash = password_hash('password123', PASSWORD_DEFAULT);
        
        $mockStmt = Mockery::mock(\PDOStatement::class);
        $mockStmt->shouldReceive('execute')
            ->once()
            ->andReturn(true);
        $mockStmt->shouldReceive('fetch')
            ->once()
            ->andReturn([
                'id' => 2,
                'username' => 'testuser',
                'password_hash' => $passwordHash,
                'role' => 'administrator',
                'email' => 'test@example.com',
                'is_active' => false
            ]);

        $this->mockPdo->shouldReceive('prepare')
            ->once()
            ->andReturn($mockStmt);

        $user = $this->userService->authenticate('testuser', 'password123');

        $this->assertNull($user);
    }

    // ========================================================================
    // PERMISSION TESTS
    // ========================================================================

    public function testHasPermissionRoot()
    {
        $this->assertTrue($this->userService->hasPermission('root', 'view_private_messages'));
        $this->assertTrue($this->userService->hasPermission('root', 'manage_users'));
        $this->assertTrue($this->userService->hasPermission('root', 'create_root_users'));
    }

    public function testHasPermissionAdministrator()
    {
        $this->assertTrue($this->userService->hasPermission('administrator', 'view_private_messages'));
        $this->assertTrue($this->userService->hasPermission('administrator', 'manage_users'));
        $this->assertFalse($this->userService->hasPermission('administrator', 'create_root_users'));
    }

    public function testHasPermissionModerator()
    {
        $this->assertFalse($this->userService->hasPermission('moderator', 'view_private_messages'));
        $this->assertFalse($this->userService->hasPermission('moderator', 'manage_users'));
        $this->assertTrue($this->userService->hasPermission('moderator', 'view_messages'));
    }

    public function testHasPermissionSimpleUser()
    {
        $this->assertFalse($this->userService->hasPermission('simple_user', 'view_messages'));
        $this->assertFalse($this->userService->hasPermission('simple_user', 'manage_users'));
    }

    // ========================================================================
    // ROLE MANAGEMENT TESTS
    // ========================================================================

    public function testCanManageUserRoot()
    {
        $this->assertTrue($this->userService->canManageUser('root', 'root'));
        $this->assertTrue($this->userService->canManageUser('root', 'administrator'));
        $this->assertTrue($this->userService->canManageUser('root', 'moderator'));
    }

    public function testCanManageUserAdministrator()
    {
        $this->assertFalse($this->userService->canManageUser('administrator', 'root'));
        $this->assertTrue($this->userService->canManageUser('administrator', 'administrator'));
        $this->assertTrue($this->userService->canManageUser('administrator', 'moderator'));
    }

    public function testCanManageUserModerator()
    {
        $this->assertFalse($this->userService->canManageUser('moderator', 'root'));
        $this->assertFalse($this->userService->canManageUser('moderator', 'administrator'));
        $this->assertFalse($this->userService->canManageUser('moderator', 'moderator'));
    }

    public function testGetRoleLevel()
    {
        $this->assertEquals(3, $this->userService->getRoleLevel('root'));
        $this->assertEquals(2, $this->userService->getRoleLevel('administrator'));
        $this->assertEquals(1, $this->userService->getRoleLevel('moderator'));
        $this->assertEquals(0, $this->userService->getRoleLevel('simple_user'));
    }

    public function testGetAvailableRoles()
    {
        $roles = $this->userService->getAvailableRoles();
        
        $this->assertIsArray($roles);
        $this->assertContains('root', $roles);
        $this->assertContains('administrator', $roles);
        $this->assertContains('moderator', $roles);
        $this->assertContains('simple_user', $roles);
    }
}
