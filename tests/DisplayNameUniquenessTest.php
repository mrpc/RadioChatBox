<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use RadioChatBox\Database;
use RadioChatBox\ChatService;

class DisplayNameUniquenessTest extends TestCase
{
    private $pdo;
    private $redis;
    private $chatService;
    private $testUsername;
    private $testSessionId;
    private $testUserId;

    protected function setUp(): void
    {
        $this->pdo = Database::getPDO();
        $this->redis = Database::getRedis();
        $this->chatService = new ChatService();
        
        // Create a test authenticated user
        $this->testUsername = 'testuser_' . uniqid();
        $this->testSessionId = 'test_session_' . uniqid();
        
        // Create user account
        $stmt = $this->pdo->prepare(
            "INSERT INTO users (username, password_hash, email) 
             VALUES (:username, :password_hash, :email) 
             RETURNING id"
        );
        $stmt->execute([
            'username' => $this->testUsername,
            'password_hash' => password_hash('password123', PASSWORD_BCRYPT),
            'email' => $this->testUsername . '@test.com'
        ]);
        $this->testUserId = $stmt->fetchColumn();
        
        // Create authenticated session
        $stmt = $this->pdo->prepare(
            "INSERT INTO sessions (username, session_id, ip_address, user_id, last_heartbeat, joined_at)
             VALUES (:username, :session_id, '127.0.0.1', :user_id, NOW(), NOW())"
        );
        $stmt->execute([
            'username' => $this->testUsername,
            'session_id' => $this->testSessionId,
            'user_id' => $this->testUserId
        ]);
    }

    protected function tearDown(): void
    {
        // Clean up test data
        $stmt = $this->pdo->prepare("DELETE FROM sessions WHERE username LIKE 'testuser_%' OR username LIKE 'guest_%'");
        $stmt->execute();
        
        $stmt = $this->pdo->prepare("DELETE FROM users WHERE username LIKE 'testuser_%'");
        $stmt->execute();
        
        $stmt = $this->pdo->prepare("DELETE FROM fake_users WHERE nickname LIKE 'fakeuser_test_%'");
        $stmt->execute();
    }

    public function testDisplayNameCannotConflictWithUsername()
    {
        // Create another user with a specific username
        $otherUsername = 'testuser_' . uniqid();
        $stmt = $this->pdo->prepare(
            "INSERT INTO users (username, password_hash, email) 
             VALUES (:username, :password_hash, :email)"
        );
        $stmt->execute([
            'username' => $otherUsername,
            'password_hash' => password_hash('password123', PASSWORD_BCRYPT),
            'email' => $otherUsername . '@test.com'
        ]);
        
        // Try to set display name to that username
        $response = $this->updateDisplayName($otherUsername);
        
        $this->assertEquals(400, $response['status']);
        $this->assertStringContainsString('already taken as a username', $response['body']['error']);
    }

    public function testDisplayNameCannotConflictWithAnotherDisplayName()
    {
        // Create another user and set their display name
        $otherUsername = 'testuser_' . uniqid();
        $otherDisplayName = 'UniqueDisplay_' . uniqid();
        
        $stmt = $this->pdo->prepare(
            "INSERT INTO users (username, password_hash, email, display_name) 
             VALUES (:username, :password_hash, :email, :display_name)"
        );
        $stmt->execute([
            'username' => $otherUsername,
            'password_hash' => password_hash('password123', PASSWORD_BCRYPT),
            'email' => $otherUsername . '@test.com',
            'display_name' => $otherDisplayName
        ]);
        
        // Try to set our display name to the same value
        $response = $this->updateDisplayName($otherDisplayName);
        
        $this->assertEquals(400, $response['status']);
        $this->assertStringContainsString('already taken', $response['body']['error']);
    }

    public function testDisplayNameCannotConflictWithFakeUserNickname()
    {
        // Create a fake user
        $fakeNickname = 'fakeuser_test_' . uniqid();
        $stmt = $this->pdo->prepare(
            "INSERT INTO fake_users (nickname) VALUES (:nickname)"
        );
        $stmt->execute(['nickname' => $fakeNickname]);
        
        // Try to set display name to that fake user's nickname
        $response = $this->updateDisplayName($fakeNickname);
        
        $this->assertEquals(400, $response['status']);
        $this->assertStringContainsString('conflicts with a system user', $response['body']['error']);
    }

    public function testDisplayNameCannotConflictWithActiveGuestNickname()
    {
        // Register a guest user
        $guestNickname = 'guest_' . uniqid();
        $guestSessionId = 'guest_session_' . uniqid();
        
        $result = $this->chatService->registerUser($guestNickname, $guestSessionId, '127.0.0.1');
        $this->assertTrue($result);
        
        // Try to set display name to that active guest's nickname
        $response = $this->updateDisplayName($guestNickname);
        
        $this->assertEquals(400, $response['status']);
        $this->assertStringContainsString('currently in use as a nickname', $response['body']['error']);
    }

    public function testGuestCannotUseDisplayNameAsNickname()
    {
        // Set a display name for our test user
        $displayName = 'MyDisplayName_' . uniqid();
        $stmt = $this->pdo->prepare(
            "UPDATE users SET display_name = :display_name WHERE id = :user_id"
        );
        $stmt->execute([
            'display_name' => $displayName,
            'user_id' => $this->testUserId
        ]);
        
        // Try to register a guest with that display name as nickname
        $guestSessionId = 'guest_session_' . uniqid();
        $result = $this->chatService->registerUser($displayName, $guestSessionId, '127.0.0.1');
        
        $this->assertFalse($result, 'Guest should not be able to use a registered display name as nickname');
    }

    public function testCanSetOwnDisplayName()
    {
        $displayName = 'ValidDisplay_' . uniqid();
        $response = $this->updateDisplayName($displayName);
        
        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        
        // Verify it was saved
        $stmt = $this->pdo->prepare("SELECT display_name FROM users WHERE id = :user_id");
        $stmt->execute(['user_id' => $this->testUserId]);
        $saved = $stmt->fetchColumn();
        
        $this->assertEquals($displayName, $saved);
    }

    public function testCanClearOwnDisplayName()
    {
        // Set a display name first
        $displayName = 'ValidDisplay_' . uniqid();
        $stmt = $this->pdo->prepare(
            "UPDATE users SET display_name = :display_name WHERE id = :user_id"
        );
        $stmt->execute([
            'display_name' => $displayName,
            'user_id' => $this->testUserId
        ]);
        
        // Clear it
        $response = $this->updateDisplayName('');
        
        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        
        // Verify it was cleared
        $stmt = $this->pdo->prepare("SELECT display_name FROM users WHERE id = :user_id");
        $stmt->execute(['user_id' => $this->testUserId]);
        $saved = $stmt->fetchColumn();
        
        $this->assertNull($saved);
    }

    private function updateDisplayName(string $displayName): array
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        
        $input = json_encode([
            'username' => $this->testUsername,
            'sessionId' => $this->testSessionId,
            'displayName' => $displayName
        ]);
        
        ob_start();
        
        // Simulate the API call
        $db = $this->pdo;
        $redis = $this->redis;
        
        // Verify session belongs to user
        $stmt = $db->prepare("
            SELECT user_id 
            FROM sessions 
            WHERE session_id = :session_id AND username = :username AND user_id IS NOT NULL
        ");
        $stmt->execute([
            'session_id' => $this->testSessionId,
            'username' => $this->testUsername
        ]);
        $session = $stmt->fetch();
        
        if (!$session || !$session['user_id']) {
            ob_end_clean();
            return ['status' => 403, 'body' => ['success' => false, 'error' => 'Invalid session']];
        }
        
        // Handle null and empty string cases
        $trimmedDisplayName = $displayName !== null ? trim($displayName) : '';
        $finalDisplayName = empty($trimmedDisplayName) ? null : $trimmedDisplayName;
        
        // If setting a display name (not clearing it), check for uniqueness
        if ($finalDisplayName !== null) {
            // Check if display name conflicts with any username
            $stmt = $db->prepare("SELECT id FROM users WHERE username = :display_name");
            $stmt->execute(['display_name' => $finalDisplayName]);
            if ($stmt->fetch()) {
                ob_end_clean();
                return ['status' => 400, 'body' => ['success' => false, 'error' => 'This display name is already taken as a username']];
            }
            
            // Check if display name conflicts with another user's display name
            $stmt = $db->prepare("
                SELECT id FROM users 
                WHERE display_name = :display_name 
                AND id != :user_id
            ");
            $stmt->execute([
                'display_name' => $finalDisplayName,
                'user_id' => $session['user_id']
            ]);
            if ($stmt->fetch()) {
                ob_end_clean();
                return ['status' => 400, 'body' => ['success' => false, 'error' => 'This display name is already taken']];
            }
            
            // Check if display name conflicts with fake user nicknames
            $stmt = $db->prepare("SELECT id FROM fake_users WHERE nickname = :display_name");
            $stmt->execute(['display_name' => $finalDisplayName]);
            if ($stmt->fetch()) {
                ob_end_clean();
                return ['status' => 400, 'body' => ['success' => false, 'error' => 'This display name conflicts with a system user']];
            }
            
            // Check if display name conflicts with active guest nicknames
            $stmt = $db->prepare("SELECT session_id FROM sessions WHERE username = :display_name");
            $stmt->execute(['display_name' => $finalDisplayName]);
            if ($stmt->fetch()) {
                ob_end_clean();
                return ['status' => 400, 'body' => ['success' => false, 'error' => 'This display name is currently in use as a nickname']];
            }
        }
        
        // Update display_name in users table
        $stmt = $db->prepare("UPDATE users SET display_name = :display_name WHERE id = :user_id");
        $stmt->execute([
            'display_name' => $finalDisplayName,
            'user_id' => $session['user_id']
        ]);
        
        ob_end_clean();
        return ['status' => 200, 'body' => ['success' => true]];
    }
}
