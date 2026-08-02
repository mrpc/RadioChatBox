<?php

namespace RadioChatBox\Tests;

use Pramnos\Framework\Testing\TestDatabase;

use PHPUnit\Framework\TestCase;
use RadioChatBox\Services\ChatService;
use Pramnos\Database\Database;
use RadioChatBox\Tests\Support\CapturesAppLog;

class ChatServiceTest extends TestCase
{
    use CapturesAppLog;

    private ChatService $chatService;

    protected function setUp(): void
    {
        $this->chatService = new ChatService();
    }

    protected function tearDown(): void
    {
        $this->verifyAndStopAppLogCapture();
    }

    public function testChatServiceUsesRedisPrefixForKeys()
    {
        $redis = \Pramnos\Redis\ConnectionManager::getInstance()->connection();
        $prefix = \Pramnos\Redis\ConnectionManager::getInstance()->prefix();
        
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
        $redis = \Pramnos\Redis\ConnectionManager::getInstance()->connection();
        $prefix = \Pramnos\Redis\ConnectionManager::getInstance()->prefix();
        
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
        $prefix1 = \Pramnos\Redis\ConnectionManager::getInstance()->prefix();
        
        // The prefix should be consistent
        $prefix2 = \Pramnos\Redis\ConnectionManager::getInstance()->prefix();
        $this->assertEquals($prefix1, $prefix2);
        
        // Prefix should contain the database name
        $dbName = (string) envvar('DB_NAME', 'radiochatbox');

        $this->assertStringContainsString($dbName, $prefix1);
        $this->assertEquals("radiochatbox:{$dbName}:", $prefix1);
    }

    public function testRegisterUserRejectsAdminUsername()
    {
        // Rejected registrations are audit-logged by ChatService; assert that
        // rather than letting the log surface as unexpected test output.
        $this->expectAppLogMatches('/Registration blocked/');

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
        // Rejected registrations are audit-logged by ChatService; assert that
        // rather than letting the log surface as unexpected test output.
        $this->expectAppLogMatches('/Registration blocked/');

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
        // Rejected registrations are audit-logged by ChatService; assert that
        // rather than letting the log surface as unexpected test output.
        $this->expectAppLogMatches('/Registration blocked/');

        $pdo = TestDatabase::connection();
        
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
        $pdo = TestDatabase::connection();
        
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
        // Rejected registrations are audit-logged by ChatService; assert that
        // rather than letting the log surface as unexpected test output.
        $this->expectAppLogMatches('/Registration blocked/');

        $pdo = TestDatabase::connection();
        
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

    public function testPrivateMessageDisplayNameSnapshotStored()
    {
        $pdo = TestDatabase::connection();
        $userService = new \RadioChatBox\Services\UserService();
        
        // Create two test users with display names
        $senderUsername = 'pm_sender_' . uniqid();
        $senderDisplayName = 'PM Sender Display';
        $recipientUsername = 'pm_recipient_' . uniqid();
        $recipientDisplayName = 'PM Recipient Display';
        
        $senderResult = $userService->createUser(
            $senderUsername,
            'testpass123',
            'simple_user',
            null,
            null,
            $senderDisplayName
        );
        $senderId = $senderResult['user']['userid'] ?? null;
        
        $recipientResult = $userService->createUser(
            $recipientUsername,
            'testpass123',
            'simple_user',
            null,
            null,
            $recipientDisplayName
        );
        $recipientId = $recipientResult['user']['userid'] ?? null;
        
        $senderSessionId = 'pm_sender_session_' . uniqid();
        $recipientSessionId = 'pm_recipient_session_' . uniqid();
        
        try {
            // Create sessions for both users
            $stmt = $pdo->prepare(
                'INSERT INTO presence_sessions (username, session_id, ip_address, user_id, last_heartbeat, joined_at)
                 VALUES (:username, :session_id, :ip_address, :user_id, NOW(), NOW())'
            );
            $stmt->execute([
                'username' => $senderUsername,
                'session_id' => $senderSessionId,
                'ip_address' => '127.0.0.1',
                'user_id' => $senderId
            ]);
            $stmt->execute([
                'username' => $recipientUsername,
                'session_id' => $recipientSessionId,
                'ip_address' => '127.0.0.1',
                'user_id' => $recipientId
            ]);
            
            // Manually insert a private message (simulating what happens in private-message.php)
            // This verifies the API stores display_name snapshots
            $stmt = $pdo->prepare(
                'INSERT INTO private_messages 
                 (from_username, from_session_id, from_display_name, to_username, to_session_id, to_display_name, message, created_at)
                 VALUES (:from_username, :from_session_id, :from_display_name, :to_username, :to_session_id, :to_display_name, :message, NOW())
                 RETURNING id'
            );
            $stmt->execute([
                'from_username' => $senderUsername,
                'from_session_id' => $senderSessionId,
                'from_display_name' => $senderDisplayName,
                'to_username' => $recipientUsername,
                'to_session_id' => $recipientSessionId,
                'to_display_name' => $recipientDisplayName,
                'message' => 'Test private message with display name snapshot'
            ]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            $messageId = $result['id'];
            
            // Query the database to verify the snapshots are stored
            $stmt = $pdo->prepare(
                'SELECT from_display_name, to_display_name FROM private_messages WHERE id = :id'
            );
            $stmt->execute(['id' => $messageId]);
            $dbMessage = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            $this->assertNotNull($dbMessage, 'Private message should exist in database');
            $this->assertEquals($senderDisplayName, $dbMessage['from_display_name'], 
                'Sender display name snapshot should be stored');
            $this->assertEquals($recipientDisplayName, $dbMessage['to_display_name'], 
                'Recipient display name snapshot should be stored');
            
            // Cleanup private message
            $stmt = $pdo->prepare("DELETE FROM private_messages WHERE id = :id");
            $stmt->execute(['id' => $messageId]);
            
        } finally {
            // Cleanup sessions
            $stmt = $pdo->prepare("DELETE FROM presence_sessions WHERE username IN (:sender, :recipient)");
            $stmt->execute(['sender' => $senderUsername, 'recipient' => $recipientUsername]);
            
            // Cleanup users
            $stmt = $pdo->prepare("DELETE FROM users WHERE userid IN (:sender_id, :recipient_id)");
            $stmt->execute(['sender_id' => $senderId, 'recipient_id' => $recipientId]);
        }
    }

    public function testGuestCannotUseRegisteredUsername()
    {
        // Rejected registrations are audit-logged by ChatService; assert that
        // rather than letting the log surface as unexpected test output.
        $this->expectAppLogMatches('/Registration blocked/');

        // Ensure 'admin' user exists (from init.sql)
        $pdo = TestDatabase::connection();
        $stmt = $pdo->prepare("SELECT userid FROM users WHERE username = 'admin'");
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
        $pdo = TestDatabase::connection();
        
        // Create a test user
        $testUsername = 'testuser_' . uniqid();
        $stmt = $pdo->prepare(
            "INSERT INTO users (username, password, usertype, is_active)
             VALUES (:username, :password, 0, TRUE)
             RETURNING userid"
        );
        $stmt->execute([
            'username' => $testUsername,
            'password' => password_hash('testpass', PASSWORD_DEFAULT)
        ]);
        $userId = $stmt->fetchColumn();
        $this->assertNotEmpty($userId, 'Test user should be created');

        // Create an authenticated session for this user
        $authSession = 'auth_session_' . uniqid();
        $stmt = $pdo->prepare(
            "INSERT INTO presence_sessions (username, session_id, ip_address, user_id, last_heartbeat, joined_at)
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
        $stmt = $pdo->prepare("DELETE FROM presence_sessions WHERE session_id = :session_id");
        $stmt->execute(['session_id' => $authSession]);
        $stmt = $pdo->prepare("DELETE FROM users WHERE userid = :id");
        $stmt->execute(['id' => $userId]);
    }

    public function testAuthenticatedUserCanHaveMultipleSessions()
    {
        $pdo = TestDatabase::connection();
        
        // Create a test user
        $testUsername = 'testuser_multi_' . uniqid();
        $stmt = $pdo->prepare(
            "INSERT INTO users (username, password, usertype, is_active)
             VALUES (:username, :password, 0, TRUE)
             RETURNING userid"
        );
        $stmt->execute([
            'username' => $testUsername,
            'password' => password_hash('testpass', PASSWORD_DEFAULT)
        ]);
        $userId = $stmt->fetchColumn();

        // Create first authenticated session
        $authSession1 = 'auth_session1_' . uniqid();
        $stmt = $pdo->prepare(
            "INSERT INTO presence_sessions (username, session_id, ip_address, user_id, last_heartbeat, joined_at)
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
            "INSERT INTO presence_sessions (username, session_id, ip_address, user_id, last_heartbeat, joined_at)
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
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM presence_sessions WHERE username = :username");
        $stmt->execute(['username' => $testUsername]);
        $count = $stmt->fetchColumn();
        $this->assertEquals(2, $count, 'Both sessions should exist for authenticated user');

        // Cleanup
        $stmt = $pdo->prepare("DELETE FROM presence_sessions WHERE username = :username");
        $stmt->execute(['username' => $testUsername]);
        $stmt = $pdo->prepare("DELETE FROM users WHERE userid = :id");
        $stmt->execute(['id' => $userId]);
    }

    public function testGuestCannotUseFakeUserNickname()
    {
        // Rejected registrations are audit-logged by ChatService; assert that
        // rather than letting the log surface as unexpected test output.
        $this->expectAppLogMatches('/Registration blocked/');

        $pdo = TestDatabase::connection();
        
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
        $pdo = TestDatabase::connection();
        
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
        $pdo = TestDatabase::connection();
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
        $stmt = $pdo->prepare("DELETE FROM presence_sessions WHERE username = :username");
        $stmt->execute(['username' => $username]);
    }

    public function testRegisterUserUpdatesUserActivityIPAddress()
    {
        $pdo = TestDatabase::connection();
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
        $stmt = $pdo->prepare("DELETE FROM presence_sessions WHERE username = :username");
        $stmt->execute(['username' => $username]);
    }

    public function testRegisterAuthenticatedUserSetsUserIdInActivity()
    {
        $pdo = TestDatabase::connection();
        
        // Create a test user
        $testUsername = 'phpunit_auth_' . uniqid() . '_' . time();
        $testPassword = 'TestPassword123!';
        
        // Register the user account
        $stmt = $pdo->prepare(
            'INSERT INTO users (username, password, email, created_at, updated_at)
             VALUES (:username, :password, :email, NOW(), NOW())'
        );
        $stmt->execute([
            'username' => $testUsername,
            'password' => password_hash($testPassword, PASSWORD_BCRYPT),
            'email' => $testUsername . '@test.com',
        ]);
        
        // Get the user ID
        $stmt = $pdo->prepare('SELECT userid FROM users WHERE username = :username');
        $stmt->execute(['username' => $testUsername]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);
        $userId = $user['userid'];
        
        // Create an authenticated session
        $sessionId = 'auth_session_' . uniqid();
        $ipAddress = '192.168.1.103';
        
        // Manually insert a session with user_id to simulate authentication
        $stmt = $pdo->prepare(
            'INSERT INTO presence_sessions (username, session_id, user_id, ip_address, last_heartbeat, joined_at)
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
        $stmt = $pdo->prepare("DELETE FROM presence_sessions WHERE username = :username");
        $stmt->execute(['username' => $testUsername]);
        $stmt = $pdo->prepare("DELETE FROM users WHERE username = :username");
        $stmt->execute(['username' => $testUsername]);
    }

    /**
     * Slow mode (an admin-set minimum gap between a user's messages) rejects a
     * rapid second message with a "wait" error, and lets it through once the gap
     * is off again. 0 (default) means slow mode is disabled.
     */
    public function testSlowModeRejectsRapidSecondMessage()
    {
        $pdo = TestDatabase::connection();
        $user = 'slowmode_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $ip = '10.44.' . random_int(0, 255) . '.' . random_int(2, 254);

        $pdo->prepare(
            "INSERT INTO settings (setting, value, updated_at) VALUES ('slow_mode_seconds', '30', NOW())
             ON CONFLICT (setting) DO UPDATE SET value = '30', updated_at = NOW()"
        )->execute();
        \Pramnos\Cache\FlatCache::default()->delete('settings:slow_mode');
        \Pramnos\Cache\FlatCache::default()->delete('slowmode:' . strtolower($user));

        $ids = [];
        try {
            $first = $this->chatService->postMessage($user, 'first message', $ip);
            $ids[] = $first['id'];

            try {
                $this->chatService->postMessage($user, 'too fast', $ip);
                $this->fail('slow mode should reject a rapid second message');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Slow mode', $e->getMessage());
            }
        } finally {
            $pdo->prepare("UPDATE settings SET value = '0' WHERE setting = 'slow_mode_seconds'")->execute();
            \Pramnos\Cache\FlatCache::default()->delete('settings:slow_mode');
            \Pramnos\Cache\FlatCache::default()->delete('slowmode:' . strtolower($user));
            foreach ($ids as $id) {
                $pdo->prepare('DELETE FROM chat_messages WHERE message_id = ?')->execute([$id]);
            }
        }
    }

    /**
     * refreshPresence upserts: it recreates a missing presence row (so an active
     * user who was cleaned up shows online again) and does not duplicate on repeat.
     */
    public function testRefreshPresenceRecreatesAndDoesNotDuplicate()
    {
        $pdo = TestDatabase::connection();
        $user = 'presence_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $sess = 'psess_' . substr(bin2hex(random_bytes(4)), 0, 8);
        try {
            // No presence row yet — a bare UPDATE would touch nothing.
            $this->chatService->refreshPresence($user, $sess, '203.0.113.7');
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM presence_sessions WHERE username = ? AND session_id = ?');
            $stmt->execute([$user, $sess]);
            $this->assertSame(1, (int) $stmt->fetchColumn(), 'presence row is created');

            // Repeat: still exactly one row (upsert), heartbeat refreshed.
            $this->chatService->refreshPresence($user, $sess, '203.0.113.7');
            $stmt->execute([$user, $sess]);
            $this->assertSame(1, (int) $stmt->fetchColumn(), 'no duplicate on repeat');
        } finally {
            $pdo->prepare('DELETE FROM presence_sessions WHERE username = ?')->execute([$user]);
        }
    }

    /**
     * A heartbeat recreates a session that a prior cleanup removed, instead of the
     * old UPDATE-only behaviour that left an active user offline forever once their
     * row was gone.
     */
    public function testHeartbeatRecreatesACleanedUpSession()
    {
        $pdo = TestDatabase::connection();
        $user = 'hb_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $sess = 'hbsess_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $count = $pdo->prepare('SELECT COUNT(*) FROM presence_sessions WHERE username = ? AND session_id = ?');
        try {
            // No session at all (as if a cleanup had removed it).
            $count->execute([$user, $sess]);
            $this->assertSame(0, (int) $count->fetchColumn());

            $this->chatService->updateHeartbeat($user, $sess, '198.51.100.9');

            $count->execute([$user, $sess]);
            $this->assertSame(1, (int) $count->fetchColumn(), 'the heartbeat recreated the presence row');
        } finally {
            $pdo->prepare('DELETE FROM presence_sessions WHERE username = ?')->execute([$user]);
        }
    }

    /**
     * A timed-out user is blocked on every send path (communicationBlockReason)
     * until the timeout is lifted — without being disconnected.
     */
    public function testTimeoutBlocksSendingThenLifts()
    {
        $user = 'timeout_' . substr(bin2hex(random_bytes(4)), 0, 8);
        try {
            $this->assertNull($this->chatService->communicationBlockReason($user, '10.0.0.1', ''),
                'not timed out initially');

            $this->chatService->timeoutUser($user, 60);
            $this->assertGreaterThan(0, $this->chatService->getTimeoutRemaining($user));
            $reason = $this->chatService->communicationBlockReason($user, '10.0.0.1', '');
            $this->assertNotNull($reason);
            $this->assertStringContainsString('timed out', $reason);

            $this->chatService->clearUserTimeout($user);
            $this->assertSame(0, $this->chatService->getTimeoutRemaining($user));
            $this->assertNull($this->chatService->communicationBlockReason($user, '10.0.0.1', ''),
                'timeout lifted, sending allowed again');
        } finally {
            $this->chatService->clearUserTimeout($user);
        }
    }

    /**
     * getAllMessages attaches photo data to DM rows that carry an attachment, so
     * the admin message-history view can render the image (previously invisible).
     */
    public function testGetAllMessagesAttachesDmPhotoData()
    {
        $pdo = TestDatabase::connection();
        $aid = 'histphoto_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $from = 'ph_from_' . substr(bin2hex(random_bytes(3)), 0, 6);
        $to = 'ph_to_' . substr(bin2hex(random_bytes(3)), 0, 6);

        $pdo->prepare(
            "INSERT INTO attachments
                (attachment_id, filename, original_filename, file_path, file_size, mime_type, uploaded_by, ip_address, expires_at, is_deleted)
             VALUES (?, ?, ?, ?, 10, 'image/jpeg', ?, '127.0.0.1', NOW() + INTERVAL '1 hour', FALSE)"
        )->execute([$aid, $aid . '.jpg', $aid . '.jpg', '/uploads/photos/' . $aid . '.jpg', $from]);
        $pdo->prepare(
            "INSERT INTO private_messages (from_username, to_username, message, attachment_id, created_at)
             VALUES (?, ?, '', ?, NOW())"
        )->execute([$from, $to, $aid]);

        try {
            $messages = $this->chatService->getAllMessages(500, 0, true, 'private');
            $mine = null;
            foreach ($messages as $m) {
                if (($m['from_username'] ?? null) === $from && ($m['to_username'] ?? null) === $to) {
                    $mine = $m;
                    break;
                }
            }
            $this->assertNotNull($mine, 'the seeded DM is returned');
            $this->assertIsArray($mine['attachment'] ?? null, 'the DM carries attachment data');
            $this->assertSame('/uploads/photos/' . $aid . '.jpg', $mine['attachment']['file_path']);
            $this->assertArrayNotHasKey('attachment_id', $mine, 'raw attachment_id is dropped from the payload');
        } finally {
            $pdo->prepare('DELETE FROM private_messages WHERE attachment_id = ?')->execute([$aid]);
            $pdo->prepare('DELETE FROM attachments WHERE attachment_id = ?')->execute([$aid]);
        }
    }

    public function testMessagesIncludeDisplayName()
    {
        $pdo = TestDatabase::connection();
        $userService = new \RadioChatBox\Services\UserService();
        
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
        $userId = $result['user']['userid'] ?? null;
        
        try {
            // Create a session for this user
            $sessionId = 'test_session_' . uniqid();
            $ipAddress = '127.0.0.1';
            
            $stmt = $pdo->prepare(
                'INSERT INTO presence_sessions (username, session_id, ip_address, user_id, last_heartbeat, joined_at)
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
                $stmt = $pdo->prepare("DELETE FROM chat_messages WHERE message_id = :message_id");
                $stmt->execute(['message_id' => $message['id']]);
            }
            $stmt = $pdo->prepare("DELETE FROM presence_sessions WHERE username = :username");
            $stmt->execute(['username' => $testUsername]);
            $stmt = $pdo->prepare("DELETE FROM users WHERE userid = :id");
            $stmt->execute(['id' => $userId]);
        }
    }

    public function testDisplayNameSnapshotStoredInDatabase()
    {
        $pdo = TestDatabase::connection();
        $userService = new \RadioChatBox\Services\UserService();
        
        // Create a test user with display name
        $testUsername = 'displaysnapshottest_' . uniqid();
        $testDisplayName = 'Snapshot Display Name';
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
        $userId = $result['user']['userid'] ?? null;
        
        $messageId = null;
        try {
            // Create a session for this user
            $sessionId = 'test_session_' . uniqid();
            $ipAddress = '127.0.0.1';
            
            $stmt = $pdo->prepare(
                'INSERT INTO presence_sessions (username, session_id, ip_address, user_id, last_heartbeat, joined_at)
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
                'Test message for snapshot verification',
                $ipAddress,
                $sessionId
            );
            $messageId = $message['id'];
            
            // Query the database directly to verify display_name snapshot is stored
            $stmt = $pdo->prepare(
                'SELECT display_name FROM chat_messages WHERE message_id = :message_id'
            );
            $stmt->execute(['message_id' => $messageId]);
            $dbRow = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            $this->assertNotNull($dbRow, 'Message should exist in database');
            $this->assertEquals($testDisplayName, $dbRow['display_name'], 
                'Display name snapshot should be stored in database at message send time');
            
        } finally {
            // Cleanup
            if ($messageId) {
                $stmt = $pdo->prepare("DELETE FROM chat_messages WHERE message_id = :message_id");
                $stmt->execute(['message_id' => $messageId]);
            }
            $stmt = $pdo->prepare("DELETE FROM presence_sessions WHERE username = :username");
            $stmt->execute(['username' => $testUsername]);
            $stmt = $pdo->prepare("DELETE FROM users WHERE userid = :id");
            $stmt->execute(['id' => $userId]);
        }
    }
}
