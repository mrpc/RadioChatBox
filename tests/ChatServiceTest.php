<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use RadioChatBox\ChatService;
use RadioChatBox\Database;

class ChatServiceTest extends TestCase
{
    private ChatService $chatService;
    
    protected function setUp(): void
    {
        $this->chatService = new ChatService();
    }

    public function testChatServiceUsesRedisPrefixForKeys()
    {
        $redis = Database::getRedis();
        $prefix = Database::getRedisPrefix();
        
        // Verify prefix format
        $this->assertStringStartsWith('radiochatbox:', $prefix);
        $this->assertStringEndsWith(':', $prefix);
        
        // Get active user count (this triggers Redis operations)
        $count = $this->chatService->getActiveUserCount();
        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function testGetActiveUserCountReturnsInteger()
    {
        $count = $this->chatService->getActiveUserCount();
        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function testGetActiveUsersReturnsArray()
    {
        $users = $this->chatService->getActiveUsers();
        $this->assertIsArray($users);
    }

    public function testGetHistoryReturnsArray()
    {
        $history = $this->chatService->getHistory(10);
        $this->assertIsArray($history);
        
        // Each message should have required fields
        foreach ($history as $msg) {
            $this->assertArrayHasKey('username', $msg);
            $this->assertArrayHasKey('message', $msg);
            $this->assertArrayHasKey('timestamp', $msg);
        }
    }

    public function testGetSettingReturnsValue()
    {
        // Test getting a setting - should use prefixed Redis keys
        $chatMode = $this->chatService->getSetting('chat_mode', 'public');
        $this->assertIsString($chatMode);
        $this->assertContains($chatMode, ['public', 'private', 'both']);
    }

    public function testRedisPrefixIsolation()
    {
        $redis = Database::getRedis();
        $prefix = Database::getRedisPrefix();
        
        // Create a test key with prefix
        $testKey = $prefix . 'test:isolation';
        $testValue = 'test_' . time();
        
        $redis->setex($testKey, 60, $testValue);
        
        // Verify it was set with the prefix
        $retrieved = $redis->get($testKey);
        $this->assertEquals($testValue, $retrieved);
        
        // Verify unprefixed key doesn't exist
        $unprefixed = $redis->get('test:isolation');
        $this->assertFalse($unprefixed);
        
        // Cleanup
        $redis->del($testKey);
    }

    public function testMultipleInstancesHaveDifferentPrefixes()
    {
        // This test verifies that the prefix is based on database name
        $prefix1 = Database::getRedisPrefix();
        
        // The prefix should be consistent
        $prefix2 = Database::getRedisPrefix();
        $this->assertEquals($prefix1, $prefix2);
        
        // Prefix should contain the database name
        $dbConfig = \RadioChatBox\Config::get('database');
        $dbName = $dbConfig['name'];
        
        $this->assertStringContainsString($dbName, $prefix1);
        $this->assertEquals("radiochatbox:{$dbName}:", $prefix1);
    }

    public function testRegisterUserRejectsAdminUsername()
    {
        // Try to register with an admin username
        $result = $this->chatService->registerUser(
            'admin',
            'test_session_' . time(),
            '127.0.0.1'
        );
        
        $this->assertFalse($result, 'Should reject registration with admin username');
    }

    public function testRegisterUserRejectsDuplicateActiveUsername()
    {
        $username = 'phpunit_test_' . uniqid() . '_' . time();
        $sessionId1 = 'session1_' . uniqid();
        $sessionId2 = 'session2_' . uniqid();
        
        // Register first user
        $result1 = $this->chatService->registerUser($username, $sessionId1, '127.0.0.1');
        $this->assertTrue($result1, 'First registration should succeed');
        
        // Try to register with same username but different session
        $result2 = $this->chatService->registerUser($username, $sessionId2, '127.0.0.2');
        $this->assertFalse($result2, 'Should reject duplicate username from different session');
    }

    public function testRegisterUserAllowsSameSessionToReregister()
    {
        $username = 'phpunit_test_' . uniqid() . '_' . time();
        $sessionId = 'session_' . uniqid();
        
        // Register user
        $result1 = $this->chatService->registerUser($username, $sessionId, '127.0.0.1');
        $this->assertTrue($result1, 'First registration should succeed');
        
        // Same session can re-register (e.g., after page refresh)
        $result2 = $this->chatService->registerUser($username, $sessionId, '127.0.0.1');
        $this->assertTrue($result2, 'Same session should be able to re-register');
    }

    public function testPrivateMessageSessionIsolation()
    {
        $pdo = Database::getPDO();
        
        // Create two different sessions with same username
        $username = 'phpunit_pm_test_' . uniqid();
        $session1 = 'session1_' . uniqid();
        $session2 = 'session2_' . uniqid();
        $recipientUsername = 'recipient_' . uniqid();
        $recipientSession = 'recipient_session_' . uniqid();
        
        // Register both users (session1 and recipient)
        $this->chatService->registerUser($username, $session1, '127.0.0.1');
        $this->chatService->registerUser($recipientUsername, $recipientSession, '127.0.0.2');
        
        // Session 1 sends a private message
        $stmt = $pdo->prepare("
            INSERT INTO private_messages (from_username, from_session_id, to_username, to_session_id, message, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$username, $session1, $recipientUsername, $recipientSession, 'Secret message from session1']);
        
        // Session 2 logs in with same username (different person)
        $this->chatService->registerUser($username, $session2, '127.0.0.3');
        
        // Session 2 queries for messages - should NOT see session 1's messages
        $stmt = $pdo->prepare("
            SELECT * FROM private_messages 
            WHERE (from_username = ? AND from_session_id = ?) 
               OR (to_username = ? AND to_session_id = ?)
        ");
        $stmt->execute([$username, $session2, $username, $session2]);
        $session2Messages = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $this->assertEmpty($session2Messages, 'Session 2 should not see session 1 private messages');
        
        // Session 1 should still be able to see its own messages
        $stmt->execute([$username, $session1, $username, $session1]);
        $session1Messages = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $this->assertCount(1, $session1Messages, 'Session 1 should see its own message');
        $this->assertEquals('Secret message from session1', $session1Messages[0]['message']);
    }

    public function testPrivateMessageConversationSessionScoped()
    {
        $pdo = Database::getPDO();
        
        $user1 = 'user1_' . uniqid();
        $user2 = 'user2_' . uniqid();
        $session1 = 'session1_' . uniqid();
        $session2 = 'session2_' . uniqid();
        
        // Register both users
        $this->chatService->registerUser($user1, $session1, '127.0.0.1');
        $this->chatService->registerUser($user2, $session2, '127.0.0.2');
        
        // Create conversation
        $stmt = $pdo->prepare("
            INSERT INTO private_messages (from_username, from_session_id, to_username, to_session_id, message, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$user1, $session1, $user2, $session2, 'Hello from user1']);
        $stmt->execute([$user2, $session2, $user1, $session1, 'Hi user1!']);
        
        // Query conversation for user1's session - should see both messages
        $stmt = $pdo->prepare("
            SELECT * FROM private_messages
            WHERE (from_username = ? AND from_session_id = ? AND to_username = ?)
               OR (from_username = ? AND to_username = ? AND to_session_id = ?)
            ORDER BY created_at ASC
        ");
        $stmt->execute([$user1, $session1, $user2, $user2, $user1, $session1]);
        $messages = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $this->assertCount(2, $messages, 'Should see full conversation');
        $this->assertEquals('Hello from user1', $messages[0]['message']);
        $this->assertEquals('Hi user1!', $messages[1]['message']);
    }

    public function testPrivateMessageOldMessagesNotVisibleToNewSessions()
    {
        $pdo = Database::getPDO();
        
        $username = 'legacy_user_' . uniqid();
        $oldSession = 'old_session_' . uniqid();
        $newSession = 'new_session_' . uniqid();
        $recipient = 'recipient_' . uniqid();
        $recipientSession = 'recipient_session_' . uniqid();
        
        // Register users with old session
        $this->chatService->registerUser($username, $oldSession, '127.0.0.1');
        $this->chatService->registerUser($recipient, $recipientSession, '127.0.0.2');
        
        // Old session sends messages
        $stmt = $pdo->prepare("
            INSERT INTO private_messages (from_username, from_session_id, to_username, to_session_id, message, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$username, $oldSession, $recipient, $recipientSession, 'Message 1']);
        $stmt->execute([$username, $oldSession, $recipient, $recipientSession, 'Message 2']);
        $stmt->execute([$username, $oldSession, $recipient, $recipientSession, 'Message 3']);
        
        // User logs out and different person logs in with same username (new session)
        $this->chatService->registerUser($username, $newSession, '127.0.0.4');
        
        // New session queries for messages
        $stmt = $pdo->prepare("
            SELECT * FROM private_messages
            WHERE (from_username = ? AND from_session_id = ?)
               OR (to_username = ? AND to_session_id = ?)
        ");
        $stmt->execute([$username, $newSession, $username, $newSession]);
        $newSessionMessages = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $this->assertEmpty($newSessionMessages, 'New session should have no access to old messages');
        
        // Old session should still see its messages if queried
        $stmt->execute([$username, $oldSession, $username, $oldSession]);
        $oldSessionMessages = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $this->assertCount(3, $oldSessionMessages, 'Old session data should still exist');
    }

    public function testGuestCannotUseRegisteredUsername()
    {
        // Ensure 'admin' user exists (from init.sql)
        $pdo = Database::getPDO();
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = 'admin'");
        $stmt->execute();
        $adminUser = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotEmpty($adminUser, 'Admin user should exist in database');

        // Try to register as guest with 'admin' username (without authentication)
        $guestSession = 'guest_session_' . uniqid();
        $result = $this->chatService->registerUser('admin', $guestSession, '127.0.0.1');
        
        $this->assertFalse($result, 'Guest should not be able to use registered username');
    }

    public function testGuestCannotCheckAvailabilityOfRegisteredUsername()
    {
        // Check if 'admin' username is available as guest
        $guestSession = 'guest_session_' . uniqid();
        $available = $this->chatService->isNicknameAvailable('admin', $guestSession);
        
        $this->assertFalse($available, 'Registered username should not be available to guests');
    }

    public function testAuthenticatedUserCanUseTheirRegisteredUsername()
    {
        $pdo = Database::getPDO();
        
        // Create a test user
        $testUsername = 'testuser_' . uniqid();
        $stmt = $pdo->prepare(
            "INSERT INTO users (username, password_hash, role, is_active) 
             VALUES (:username, :password_hash, 'simple_user', TRUE) 
             RETURNING id"
        );
        $stmt->execute([
            'username' => $testUsername,
            'password_hash' => password_hash('testpass', PASSWORD_DEFAULT)
        ]);
        $userId = $stmt->fetchColumn();
        $this->assertNotEmpty($userId, 'Test user should be created');

        // Create an authenticated session for this user
        $authSession = 'auth_session_' . uniqid();
        $stmt = $pdo->prepare(
            "INSERT INTO sessions (username, session_id, ip_address, user_id, last_heartbeat, joined_at)
             VALUES (:username, :session_id, '127.0.0.1', :user_id, NOW(), NOW())"
        );
        $stmt->execute([
            'username' => $testUsername,
            'session_id' => $authSession,
            'user_id' => $userId
        ]);

        // Now test that this authenticated session can use the registered username
        $available = $this->chatService->isNicknameAvailable($testUsername, $authSession);
        $this->assertTrue($available, 'Authenticated user should be able to use their own username');

        // Test that registration works for authenticated user
        $result = $this->chatService->registerUser($testUsername, $authSession, '127.0.0.1');
        $this->assertTrue($result, 'Authenticated user should be able to register with their username');

        // Cleanup
        $stmt = $pdo->prepare("DELETE FROM sessions WHERE session_id = :session_id");
        $stmt->execute(['session_id' => $authSession]);
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
        $stmt->execute(['id' => $userId]);
    }

    public function testAuthenticatedUserCanHaveMultipleSessions()
    {
        $pdo = Database::getPDO();
        
        // Create a test user
        $testUsername = 'testuser_multi_' . uniqid();
        $stmt = $pdo->prepare(
            "INSERT INTO users (username, password_hash, role, is_active) 
             VALUES (:username, :password_hash, 'simple_user', TRUE) 
             RETURNING id"
        );
        $stmt->execute([
            'username' => $testUsername,
            'password_hash' => password_hash('testpass', PASSWORD_DEFAULT)
        ]);
        $userId = $stmt->fetchColumn();

        // Create first authenticated session
        $authSession1 = 'auth_session1_' . uniqid();
        $stmt = $pdo->prepare(
            "INSERT INTO sessions (username, session_id, ip_address, user_id, last_heartbeat, joined_at)
             VALUES (:username, :session_id, '127.0.0.1', :user_id, NOW(), NOW())"
        );
        $stmt->execute([
            'username' => $testUsername,
            'session_id' => $authSession1,
            'user_id' => $userId
        ]);

        // Register from first session
        $result1 = $this->chatService->registerUser($testUsername, $authSession1, '127.0.0.1');
        $this->assertTrue($result1, 'First session should register successfully');

        // Create second authenticated session (simulating different device)
        $authSession2 = 'auth_session2_' . uniqid();
        $stmt = $pdo->prepare(
            "INSERT INTO sessions (username, session_id, ip_address, user_id, last_heartbeat, joined_at)
             VALUES (:username, :session_id, '127.0.0.2', :user_id, NOW(), NOW())"
        );
        $stmt->execute([
            'username' => $testUsername,
            'session_id' => $authSession2,
            'user_id' => $userId
        ]);

        // Register from second session - should succeed for authenticated user
        $result2 = $this->chatService->registerUser($testUsername, $authSession2, '127.0.0.2');
        $this->assertTrue($result2, 'Authenticated user should be able to have multiple sessions');

        // Verify both sessions exist
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM sessions WHERE username = :username");
        $stmt->execute(['username' => $testUsername]);
        $count = $stmt->fetchColumn();
        $this->assertEquals(2, $count, 'Both sessions should exist for authenticated user');

        // Cleanup
        $stmt = $pdo->prepare("DELETE FROM sessions WHERE username = :username");
        $stmt->execute(['username' => $testUsername]);
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
        $stmt->execute(['id' => $userId]);
    }

    public function testGuestCannotUseFakeUserNickname()
    {
        $pdo = Database::getPDO();
        
        // Create a test fake user
        $fakeNickname = 'fakeuser_' . uniqid();
        $stmt = $pdo->prepare(
            "INSERT INTO fake_users (nickname, age, sex, location, is_active) 
             VALUES (:nickname, 25, 'male', 'Virtual City', FALSE)"
        );
        $stmt->execute(['nickname' => $fakeNickname]);

        // Try to register as guest with this fake user nickname
        $guestSession = 'guest_session_' . uniqid();
        $result = $this->chatService->registerUser($fakeNickname, $guestSession, '127.0.0.1');
        
        $this->assertFalse($result, 'Guest should not be able to use fake user nickname');

        // Cleanup
        $stmt = $pdo->prepare("DELETE FROM fake_users WHERE nickname = :nickname");
        $stmt->execute(['nickname' => $fakeNickname]);
    }

    public function testGuestCannotCheckAvailabilityOfFakeUserNickname()
    {
        $pdo = Database::getPDO();
        
        // Create a test fake user
        $fakeNickname = 'fakeuser_avail_' . uniqid();
        $stmt = $pdo->prepare(
            "INSERT INTO fake_users (nickname, age, sex, location, is_active) 
             VALUES (:nickname, 30, 'female', 'Virtual Town', TRUE)"
        );
        $stmt->execute(['nickname' => $fakeNickname]);

        // Check if fake user nickname is available as guest
        $guestSession = 'guest_session_' . uniqid();
        $available = $this->chatService->isNicknameAvailable($fakeNickname, $guestSession);
        
        $this->assertFalse($available, 'Fake user nickname should not be available to guests');

        // Cleanup
        $stmt = $pdo->prepare("DELETE FROM fake_users WHERE nickname = :nickname");
        $stmt->execute(['nickname' => $fakeNickname]);
    }

    public function testRegisterUserPopulatesUserActivityTable()
    {
        $pdo = Database::getPDO();
        $username = 'phpunit_activity_' . uniqid() . '_' . time();
        $sessionId = 'session_activity_' . uniqid();
        $ipAddress = '192.168.1.100';
        
        // Register a guest user
        $result = $this->chatService->registerUser($username, $sessionId, $ipAddress);
        $this->assertTrue($result, 'User registration should succeed');
        
        // Verify user_activity record was created
        $stmt = $pdo->prepare('SELECT * FROM user_activity WHERE username = :username');
        $stmt->execute(['username' => $username]);
        $activity = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        $this->assertNotNull($activity, 'user_activity record should exist for registered user');
        $this->assertEquals($username, $activity['username']);
        $this->assertEquals($ipAddress, $activity['ip_address']);
        $this->assertNull($activity['user_id'], 'user_id should be NULL for guest users');
        $this->assertEquals(0, $activity['message_count'], 'message_count should be 0 on registration');
        
        // Cleanup
        $stmt = $pdo->prepare("DELETE FROM user_activity WHERE username = :username");
        $stmt->execute(['username' => $username]);
        $stmt = $pdo->prepare("DELETE FROM sessions WHERE username = :username");
        $stmt->execute(['username' => $username]);
    }

    public function testRegisterUserUpdatesUserActivityIPAddress()
    {
        $pdo = Database::getPDO();
        $username = 'phpunit_ip_update_' . uniqid() . '_' . time();
        $sessionId = 'session_ip_' . uniqid();
        $ipAddress1 = '192.168.1.101';
        $ipAddress2 = '192.168.1.102';
        
        // Register with first IP
        $result1 = $this->chatService->registerUser($username, $sessionId, $ipAddress1);
        $this->assertTrue($result1, 'First registration should succeed');
        
        // Re-register with different IP (same session)
        $result2 = $this->chatService->registerUser($username, $sessionId, $ipAddress2);
        $this->assertTrue($result2, 'Re-registration should succeed');
        
        // Verify IP was updated in user_activity
        $stmt = $pdo->prepare('SELECT ip_address FROM user_activity WHERE username = :username');
        $stmt->execute(['username' => $username]);
        $activity = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        $this->assertNotNull($activity);
        $this->assertEquals($ipAddress2, $activity['ip_address'], 'IP address should be updated on re-registration');
        
        // Cleanup
        $stmt = $pdo->prepare("DELETE FROM user_activity WHERE username = :username");
        $stmt->execute(['username' => $username]);
        $stmt = $pdo->prepare("DELETE FROM sessions WHERE username = :username");
        $stmt->execute(['username' => $username]);
    }

    public function testRegisterAuthenticatedUserSetsUserIdInActivity()
    {
        $pdo = Database::getPDO();
        
        // Create a test user
        $testUsername = 'phpunit_auth_' . uniqid() . '_' . time();
        $testPassword = 'TestPassword123!';
        
        // Register the user account
        $stmt = $pdo->prepare(
            'INSERT INTO users (username, password_hash, email, created_at, updated_at) 
             VALUES (:username, :password, :email, NOW(), NOW())'
        );
        $stmt->execute([
            'username' => $testUsername,
            'password' => password_hash($testPassword, PASSWORD_BCRYPT),
            'email' => $testUsername . '@test.com',
        ]);
        
        // Get the user ID
        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :username');
        $stmt->execute(['username' => $testUsername]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);
        $userId = $user['id'];
        
        // Create an authenticated session
        $sessionId = 'auth_session_' . uniqid();
        $ipAddress = '192.168.1.103';
        
        // Manually insert a session with user_id to simulate authentication
        $stmt = $pdo->prepare(
            'INSERT INTO sessions (username, session_id, user_id, ip_address, last_heartbeat, joined_at)
             VALUES (:username, :session_id, :user_id, :ip_address, NOW(), NOW())'
        );
        $stmt->execute([
            'username' => $testUsername,
            'session_id' => $sessionId,
            'user_id' => $userId,
            'ip_address' => $ipAddress,
        ]);
        
        // Now call registerUser (it will update the session)
        $result = $this->chatService->registerUser($testUsername, $sessionId, $ipAddress);
        $this->assertTrue($result, 'Authenticated user registration should succeed');
        
        // Verify user_activity has the user_id set
        $stmt = $pdo->prepare('SELECT user_id FROM user_activity WHERE username = :username');
        $stmt->execute(['username' => $testUsername]);
        $activity = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        $this->assertNotNull($activity);
        $this->assertNotNull($activity['user_id'], 'user_id should be set for authenticated users');
        $this->assertEquals($userId, $activity['user_id']);
        
        // Cleanup
        $stmt = $pdo->prepare("DELETE FROM user_activity WHERE username = :username");
        $stmt->execute(['username' => $testUsername]);
        $stmt = $pdo->prepare("DELETE FROM sessions WHERE username = :username");
        $stmt->execute(['username' => $testUsername]);
        $stmt = $pdo->prepare("DELETE FROM users WHERE username = :username");
        $stmt->execute(['username' => $testUsername]);
    }

    public function testMessagesIncludeDisplayName()
    {
        $pdo = Database::getPDO();
        $userService = new \RadioChatBox\UserService();
        
        // Create a test user with display name
        $testUsername = 'displaynametest_' . uniqid();
        $testDisplayName = 'Test Display Name';
        $testPassword = 'testpass123';
        
        $result = $userService->createUser(
            $testUsername,
            $testPassword,
            'simple_user',
            null,
            null,
            $testDisplayName
        );
        
        $this->assertTrue($result['success'] ?? false, 'Test user should be created');
        $userId = $result['user']['id'] ?? null;
        
        try {
            // Create a session for this user
            $sessionId = 'test_session_' . uniqid();
            $ipAddress = '127.0.0.1';
            
            $stmt = $pdo->prepare(
                'INSERT INTO sessions (username, session_id, ip_address, user_id, last_heartbeat, joined_at)
                 VALUES (:username, :session_id, :ip_address, :user_id, NOW(), NOW())'
            );
            $stmt->execute([
                'username' => $testUsername,
                'session_id' => $sessionId,
                'ip_address' => $ipAddress,
                'user_id' => $userId
            ]);
            
            // Post a message
            $message = $this->chatService->postMessage(
                $testUsername,
                'Test message with display name',
                $ipAddress,
                $sessionId
            );
            
            $this->assertArrayHasKey('display_name', $message, 'Message should have display_name field');
            $this->assertEquals($testDisplayName, $message['display_name'], 'Display name should match');
            
            // Verify message in history includes display_name
            $history = $this->chatService->getHistory(10);
            $foundMessage = null;
            foreach ($history as $msg) {
                if ($msg['id'] === $message['id']) {
                    $foundMessage = $msg;
                    break;
                }
            }
            
            $this->assertNotNull($foundMessage, 'Message should be in history');
            $this->assertEquals($testDisplayName, $foundMessage['display_name'] ?? null, 'Display name should be in history');
            
        } finally {
            // Cleanup
            if (isset($message['id'])) {
                $stmt = $pdo->prepare("DELETE FROM messages WHERE message_id = :message_id");
                $stmt->execute(['message_id' => $message['id']]);
            }
            $stmt = $pdo->prepare("DELETE FROM sessions WHERE username = :username");
            $stmt->execute(['username' => $testUsername]);
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
            $stmt->execute(['id' => $userId]);
        }
    }
}
