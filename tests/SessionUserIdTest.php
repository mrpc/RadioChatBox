<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use RadioChatBox\ChatService;
use RadioChatBox\UserService;
use RadioChatBox\Database;

/**
 * Test that sessions are properly linked to user accounts
 */
class SessionUserIdTest extends TestCase
{
    private static $pdo;
    private static $redis;

    public static function setUpBeforeClass(): void
    {
        self::$pdo = Database::getPDO();
        self::$redis = Database::getRedis();
        
        // Clean up test data
        self::$pdo->exec("DELETE FROM sessions WHERE username LIKE 'testsession%'");
        self::$pdo->exec("DELETE FROM users WHERE username LIKE 'testsession%'");
        self::$pdo->exec("DELETE FROM messages WHERE username LIKE 'testsession%'");
        self::$pdo->exec("DELETE FROM user_activity WHERE username LIKE 'testsession%'");
    }

    public static function tearDownAfterClass(): void
    {
        // Clean up test data
        self::$pdo->exec("DELETE FROM sessions WHERE username LIKE 'testsession%'");
        self::$pdo->exec("DELETE FROM users WHERE username LIKE 'testsession%'");
        self::$pdo->exec("DELETE FROM messages WHERE username LIKE 'testsession%'");
        self::$pdo->exec("DELETE FROM user_activity WHERE username LIKE 'testsession%'");
    }

    public function testGuestSessionHasNullUserId()
    {
        $chatService = new ChatService();
        
        $username = 'testsessionguest' . time();
        $sessionId = 'session-' . uniqid();
        $ipAddress = '192.168.1.100';
        
        // Register as guest
        $result = $chatService->registerUser($username, $sessionId, $ipAddress);
        
        $this->assertTrue($result, 'Guest registration should succeed');
        
        // Check session has NULL user_id
        $stmt = self::$pdo->prepare('SELECT user_id FROM sessions WHERE username = :username AND session_id = :session_id');
        $stmt->execute(['username' => $username, 'session_id' => $sessionId]);
        $session = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        $this->assertNotFalse($session, 'Session should exist');
        $this->assertNull($session['user_id'], 'Guest session should have NULL user_id');
    }

    public function testAuthenticatedUserSessionHasUserId()
    {
        $userService = new UserService();
        $chatService = new ChatService();
        
        $username = 'testsessionauth' . time();
        $email = 'testsession' . time() . '@example.com';
        $password = 'TestPass123!';
        $sessionId = 'session-' . uniqid();
        $ipAddress = '192.168.1.101';
        
        // Register authenticated user
        $result = $userService->createUser($username, $password, 'simple_user', $email);
        $this->assertTrue($result['success'], 'User registration should succeed');
        $userId = $result['user']['id'];
        $this->assertIsInt($userId, 'User should have an ID');
        
        // Create session with user_id (simulating login)
        $stmt = self::$pdo->prepare(
            'INSERT INTO sessions (username, session_id, ip_address, user_id, last_heartbeat, joined_at)
             VALUES (:username, :session_id, :ip_address, :user_id, NOW(), NOW())'
        );
        $stmt->execute([
            'username' => $username,
            'session_id' => $sessionId,
            'ip_address' => $ipAddress,
            'user_id' => $userId
        ]);
        
        // Check session has the correct user_id
        $stmt = self::$pdo->prepare('SELECT user_id FROM sessions WHERE username = :username AND session_id = :session_id');
        $stmt->execute(['username' => $username, 'session_id' => $sessionId]);
        $session = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        $this->assertNotFalse($session, 'Session should exist');
        $this->assertEquals($userId, $session['user_id'], 'Authenticated user session should have correct user_id');
    }

    public function testLoginUpdatesSessionWithUserId()
    {
        $userService = new UserService();
        
        $username = 'testsessionlogin' . time();
        $email = 'testsessionlogin' . time() . '@example.com';
        $password = 'TestPass123!';
        $sessionId = 'session-' . uniqid();
        $ipAddress = '192.168.1.102';
        
        // Register authenticated user
        $result = $userService->createUser($username, $password, 'simple_user', $email);
        $this->assertTrue($result['success'], 'User registration should succeed');
        $userId = $result['user']['id'];
        $this->assertIsInt($userId, 'User should have an ID');
        
        // Create a guest session first
        $stmt = self::$pdo->prepare(
            'INSERT INTO sessions (username, session_id, ip_address, last_heartbeat, joined_at)
             VALUES (:username, :session_id, :ip_address, NOW(), NOW())'
        );
        $stmt->execute([
            'username' => $username,
            'session_id' => $sessionId,
            'ip_address' => $ipAddress,
        ]);
        
        // Verify guest session has NULL user_id
        $stmt = self::$pdo->prepare('SELECT user_id FROM sessions WHERE username = :username AND session_id = :session_id');
        $stmt->execute(['username' => $username, 'session_id' => $sessionId]);
        $session = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertNull($session['user_id'], 'Initial guest session should have NULL user_id');
        
        // Simulate login (update session with user_id)
        $stmt = self::$pdo->prepare(
            'INSERT INTO sessions (username, session_id, ip_address, user_id, last_heartbeat, joined_at)
             VALUES (:username, :session_id, :ip_address, :user_id, NOW(), NOW())
             ON CONFLICT (username, session_id) DO UPDATE SET
                 ip_address = :ip_address,
                 user_id = :user_id,
                 last_heartbeat = NOW()'
        );
        
        $stmt->execute([
            'username' => $username,
            'session_id' => $sessionId,
            'ip_address' => $ipAddress,
            'user_id' => $userId
        ]);
        
        // Verify session now has user_id
        $stmt = self::$pdo->prepare('SELECT user_id FROM sessions WHERE username = :username AND session_id = :session_id');
        $stmt->execute(['username' => $username, 'session_id' => $sessionId]);
        $session = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        $this->assertEquals($userId, $session['user_id'], 'Session should be updated with user_id after login');
    }

    public function testMessageFromAuthenticatedUserHasUserId()
    {
        $userService = new UserService();
        $chatService = new ChatService();
        
        $username = 'testsessionmsg' . time();
        $email = 'testsessionmsg' . time() . '@example.com';
        $password = 'TestPass123!';
        $displayName = 'Test Display Name ' . time();
        $sessionId = 'session-' . uniqid();
        $ipAddress = '192.168.1.103';
        
        // Register authenticated user with display name
        $result = $userService->createUser($username, $password, 'simple_user', $email, null, $displayName);
        $this->assertTrue($result['success'], 'User registration should succeed');
        $userId = $result['user']['id'];
        $this->assertIsInt($userId, 'User should have an ID');
        
        // Create session with user_id and register via registerUser (simulating authenticated user joining chat)
        $stmt = self::$pdo->prepare(
            'INSERT INTO sessions (username, session_id, ip_address, user_id, last_heartbeat, joined_at)
             VALUES (:username, :session_id, :ip_address, :user_id, NOW(), NOW())'
        );
        $stmt->execute([
            'username' => $username,
            'session_id' => $sessionId,
            'ip_address' => $ipAddress,
            'user_id' => $userId
        ]);
        
        // Send a message
        $messageId = uniqid();
        $messageText = 'Test message from authenticated user';
        
        $messageData = $chatService->postMessage($username, $messageText, $ipAddress, $sessionId);
        $this->assertIsArray($messageData, 'Message post should return array');
        $this->assertEquals($username, $messageData['username'], 'Message should have correct username');
        
        // Check message has user_id
        $stmt = self::$pdo->prepare('SELECT user_id, username FROM messages WHERE username = :username AND message = :message ORDER BY created_at DESC LIMIT 1');
        $stmt->execute(['username' => $username, 'message' => $messageText]);
        $message = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        $this->assertNotFalse($message, 'Message should exist');
        $this->assertEquals($userId, $message['user_id'], 'Message should have correct user_id');
        $this->assertEquals($username, $message['username'], 'Message should have correct username');
    }
}
