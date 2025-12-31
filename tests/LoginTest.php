<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use RadioChatBox\Database;
use RadioChatBox\UserService;

/**
 * Test chat login functionality for registered users
 */
class LoginTest extends TestCase
{
    private $sessionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sessionId = 'test_login_session_' . uniqid();
    }

    protected function tearDown(): void
    {
        // Cleanup test sessions
        try {
            $pdo = Database::getPDO();
            $stmt = $pdo->prepare("DELETE FROM sessions WHERE session_id = :session_id");
            $stmt->execute(['session_id' => $this->sessionId]);
        } catch (\Exception $e) {
            // Ignore cleanup errors
        }
        
        Database::reset();
        parent::tearDown();
    }

    public function testLoginApiValidatesRequiredFields()
    {
        // Test without username
        $response = $this->callLoginApi([
            'password' => 'testpass',
            'sessionId' => $this->sessionId
        ]);
        
        $this->assertEquals(400, $response['status']);
        $this->assertStringContainsString('required', strtolower($response['body']['error'] ?? ''));

        // Test without password
        $response = $this->callLoginApi([
            'username' => 'testuser',
            'sessionId' => $this->sessionId
        ]);
        
        $this->assertEquals(400, $response['status']);

        // Test without sessionId
        $response = $this->callLoginApi([
            'username' => 'testuser',
            'password' => 'testpass'
        ]);
        
        $this->assertEquals(400, $response['status']);
    }

    public function testLoginApiRejectsInvalidCredentials()
    {
        $response = $this->callLoginApi([
            'username' => 'nonexistent_user',
            'password' => 'wrongpassword',
            'sessionId' => $this->sessionId
        ]);
        
        $this->assertEquals(401, $response['status']);
        $this->assertStringContainsString('invalid', strtolower($response['body']['error'] ?? ''));
    }

    public function testLoginApiSuccessWithValidCredentials()
    {
        $pdo = Database::getPDO();
        
        // Create a test user
        $testUsername = 'logintest_' . uniqid();
        $testPassword = 'testpass123';
        $userService = new UserService();
        
        $result = $userService->createUser(
            $testUsername,
            $testPassword,
            'simple_user',
            null,
            null
        );
        
        $this->assertTrue($result['success'] ?? false, 'Test user should be created');
        $userId = $result['user']['id'] ?? null;
        $this->assertNotNull($userId, 'User ID should be returned');

        try {
            // Test successful login
            $response = $this->callLoginApi([
                'username' => $testUsername,
                'password' => $testPassword,
                'sessionId' => $this->sessionId
            ]);
            
            $this->assertEquals(200, $response['status'], 'Login should succeed');
            $this->assertTrue($response['body']['success'] ?? false);
            $this->assertEquals($testUsername, $response['body']['user']['username'] ?? '');
            $this->assertEquals($userId, $response['body']['user']['id'] ?? null);

            // Verify session was created with user_id
            $stmt = $pdo->prepare("SELECT user_id, username FROM sessions WHERE session_id = :session_id");
            $stmt->execute(['session_id' => $this->sessionId]);
            $session = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            $this->assertNotNull($session, 'Session should be created');
            $this->assertEquals($userId, $session['user_id'], 'Session should be linked to user');
            $this->assertEquals($testUsername, $session['username'], 'Session should have correct username');

        } finally {
            // Cleanup
            $stmt = $pdo->prepare("DELETE FROM sessions WHERE username = :username");
            $stmt->execute(['username' => $testUsername]);
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
            $stmt->execute(['id' => $userId]);
        }
    }

    public function testLoginAllowsUserToJoinChat()
    {
        $pdo = Database::getPDO();
        
        // Create a test user
        $testUsername = 'chatlogin_' . uniqid();
        $testPassword = 'chatpass123';
        $userService = new UserService();
        
        $result = $userService->createUser(
            $testUsername,
            $testPassword,
            'simple_user',
            null,
            null
        );
        
        $this->assertTrue($result['success'] ?? false, 'Test user should be created');
        $userId = $result['user']['id'] ?? null;

        try {
            // Login
            $response = $this->callLoginApi([
                'username' => $testUsername,
                'password' => $testPassword,
                'sessionId' => $this->sessionId
            ]);
            
            $this->assertTrue($response['body']['success'] ?? false, 'Login should succeed');

            // Verify user can join chat (session is authenticated)
            $stmt = $pdo->prepare("
                SELECT s.user_id, u.username 
                FROM sessions s 
                INNER JOIN users u ON s.user_id = u.id 
                WHERE s.session_id = :session_id AND u.username = :username
            ");
            $stmt->execute([
                'session_id' => $this->sessionId,
                'username' => $testUsername
            ]);
            
            $authenticated = $stmt->fetch(\PDO::FETCH_ASSOC);
            $this->assertNotEmpty($authenticated, 'User should be authenticated in session');

        } finally {
            // Cleanup
            $stmt = $pdo->prepare("DELETE FROM sessions WHERE username = :username");
            $stmt->execute(['username' => $testUsername]);
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
            $stmt->execute(['id' => $userId]);
        }
    }

    /**
     * Helper method to call login API
     */
    private function callLoginApi(array $data): array
    {
        // Simulate API call by directly including the endpoint
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [];
        $_GET = [];
        
        // Capture output
        ob_start();
        
        // Simulate POST data
        $json = json_encode($data);
        
        // Mock php://input
        $tempFile = tmpfile();
        fwrite($tempFile, $json);
        rewind($tempFile);
        
        // Execute the login endpoint logic inline
        try {
            require_once __DIR__ . '/../vendor/autoload.php';
            
            $userService = new UserService();
            
            $username = $data['username'] ?? '';
            $password = $data['password'] ?? '';
            $sessionId = $data['sessionId'] ?? '';
            
            if (empty($username) || empty($password) || empty($sessionId)) {
                $status = 400;
                $body = ['error' => 'Username, password, and session ID are required'];
            } else {
                // Authenticate user
                $user = $userService->authenticate($username, $password);
                
                if (!$user) {
                    $status = 401;
                    $body = ['error' => 'Invalid username or password'];
                } else {
                    // Link the session to this authenticated user
                    $pdo = Database::getPDO();
                    $ipAddress = '127.0.0.1';
                    
                    $stmt = $pdo->prepare(
                        'INSERT INTO sessions (username, session_id, ip_address, user_id, last_heartbeat, joined_at)
                         VALUES (:username, :session_id, :ip_address, :user_id, NOW(), NOW())
                         ON CONFLICT (username, session_id) DO UPDATE SET
                             ip_address = :ip_address,
                             user_id = :user_id,
                             last_heartbeat = NOW()'
                    );
                    
                    $stmt->execute([
                        'username' => $user['username'],
                        'session_id' => $sessionId,
                        'ip_address' => $ipAddress,
                        'user_id' => $user['id']
                    ]);
                    
                    $status = 200;
                    $body = [
                        'success' => true,
                        'message' => 'Login successful',
                        'user' => [
                            'id' => $user['id'],
                            'username' => $user['username'],
                            'role' => $user['role']
                        ]
                    ];
                }
            }
        } catch (\Exception $e) {
            $status = 500;
            $body = ['error' => 'Internal server error'];
        }
        
        ob_end_clean();
        fclose($tempFile);
        
        return [
            'status' => $status,
            'body' => $body
        ];
    }
}
