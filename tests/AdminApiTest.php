<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use RadioChatBox\Database;

/**
 * Test admin API endpoints for correct database table references
 * 
 * This test verifies that admin endpoints query the correct tables
 * for user information, particularly ensuring ip_address is fetched
 * from user_activity (not users table which doesn't have that column).
 */
class AdminApiTest extends TestCase
{
    private \PDO $db;
    
    protected function setUp(): void
    {
        $this->db = Database::getPDO();
    }

    /**
     * Test that user_activity table has ip_address column
     */
    public function testUserActivityTableHasIpAddressColumn()
    {
        $stmt = $this->db->query("
            SELECT column_name 
            FROM information_schema.columns 
            WHERE table_name = 'user_activity' 
            AND column_name = 'ip_address'
        ");
        
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotEmpty($result, 'user_activity table should have ip_address column');
    }

    /**
     * Test that users table does NOT have ip_address column
     */
    public function testUsersTableDoesNotHaveIpAddressColumn()
    {
        $stmt = $this->db->query("
            SELECT column_name 
            FROM information_schema.columns 
            WHERE table_name = 'users' 
            AND column_name = 'ip_address'
        ");
        
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertEmpty($result, 'users table should NOT have ip_address column');
    }

    /**
     * Test that user_activity table has first_seen column
     */
    public function testUserActivityTableHasFirstSeenColumn()
    {
        $stmt = $this->db->query("
            SELECT column_name 
            FROM information_schema.columns 
            WHERE table_name = 'user_activity' 
            AND column_name = 'first_seen'
        ");
        
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotEmpty($result, 'user_activity table should have first_seen column');
    }

    /**
     * Test querying IP addresses from user_activity table
     * This simulates the query used in user-details.php
     */
    public function testQueryIpAddressesFromUserActivity()
    {
        // Insert test data
        $testUsername = 'test_user_' . uniqid();
        $testIp = '127.0.0.1';
        
        $stmt = $this->db->prepare("
            INSERT INTO user_activity (username, ip_address, first_seen, last_seen)
            VALUES (:username, :ip_address, NOW(), NOW())
            ON CONFLICT (username) DO NOTHING
        ");
        $stmt->execute([
            'username' => $testUsername,
            'ip_address' => $testIp
        ]);
        
        // Test the query that was fixed (should query user_activity, not users)
        $stmt = $this->db->prepare("
            SELECT DISTINCT ip_address, first_seen
            FROM user_activity 
            WHERE username = ?
            ORDER BY first_seen DESC
        ");
        $stmt->execute([$testUsername]);
        $ipAddresses = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $this->assertIsArray($ipAddresses);
        $this->assertNotEmpty($ipAddresses, 'Should find IP address for test user');
        $this->assertEquals($testIp, $ipAddresses[0]['ip_address']);
        $this->assertArrayHasKey('first_seen', $ipAddresses[0]);
        
        // Cleanup
        $stmt = $this->db->prepare("DELETE FROM user_activity WHERE username = ?");
        $stmt->execute([$testUsername]);
    }

    /**
     * Test that querying ip_address from users table would fail
     * This verifies the bug we fixed - users table doesn't have ip_address
     */
    public function testQueryIpAddressFromUsersTableFails()
    {
        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('column "ip_address" does not exist');
        
        // This query should fail because users table doesn't have ip_address
        $stmt = $this->db->prepare("
            SELECT DISTINCT ip_address, first_seen
            FROM users 
            WHERE username = ?
        ");
        $stmt->execute(['any_username']);
    }

    /**
     * Test user_activity table structure matches expected schema
     */
    public function testUserActivityTableStructure()
    {
        $requiredColumns = [
            'id',
            'username',
            'session_id',
            'ip_address',
            'first_seen',
            'last_seen',
            'message_count',
            'is_banned',
            'is_moderator',
            'user_id'
        ];
        
        foreach ($requiredColumns as $column) {
            $stmt = $this->db->prepare("
                SELECT column_name 
                FROM information_schema.columns 
                WHERE table_name = 'user_activity' 
                AND column_name = :column
            ");
            $stmt->execute(['column' => $column]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            $this->assertNotEmpty(
                $result, 
                "user_activity table should have {$column} column"
            );
        }
    }

    /**
     * Test users table structure - ensure it has authentication-related fields
     * but NOT tracking fields like ip_address, first_seen, last_seen
     */
    public function testUsersTableStructure()
    {
        // Columns that SHOULD exist in users table
        $shouldHaveColumns = [
            'id',
            'username',
            'password_hash',
            'role',
            'email',
            'is_active',
            'created_at',
            'updated_at'
        ];
        
        foreach ($shouldHaveColumns as $column) {
            $stmt = $this->db->prepare("
                SELECT column_name 
                FROM information_schema.columns 
                WHERE table_name = 'users' 
                AND column_name = :column
            ");
            $stmt->execute(['column' => $column]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            $this->assertNotEmpty(
                $result, 
                "users table should have {$column} column"
            );
        }
        
        // Columns that SHOULD NOT exist in users table (they belong in user_activity)
        $shouldNotHaveColumns = [
            'ip_address',
            'first_seen',
            'last_seen',
            'message_count'
        ];
        
        foreach ($shouldNotHaveColumns as $column) {
            $stmt = $this->db->prepare("
                SELECT column_name 
                FROM information_schema.columns 
                WHERE table_name = 'users' 
                AND column_name = :column
            ");
            $stmt->execute(['column' => $column]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            $this->assertEmpty(
                $result, 
                "users table should NOT have {$column} column (belongs in user_activity)"
            );
        }
    }
    
    /**
     * Test user details pagination functionality
     */
    public function testUserDetailsPaginationQuery()
    {
        // Insert test user and messages
        $testUsername = 'pagination_test_' . uniqid();
        $testIp = '192.168.1.100';
        
        // Create user activity
        $stmt = $this->db->prepare("
            INSERT INTO user_activity (username, ip_address, first_seen, last_seen)
            VALUES (:username, :ip_address, NOW(), NOW())
            ON CONFLICT (username) DO NOTHING
        ");
        $stmt->execute([
            'username' => $testUsername,
            'ip_address' => $testIp
        ]);
        
        // Insert 25 test messages
        for ($i = 1; $i <= 25; $i++) {
            $stmt = $this->db->prepare("
                INSERT INTO messages (message_id, username, message, ip_address, created_at)
                VALUES (:message_id, :username, :message, :ip_address, NOW())
            ");
            $stmt->execute([
                'message_id' => uniqid() . '_' . $i,
                'username' => $testUsername,
                'message' => "Test message number $i",
                'ip_address' => $testIp
            ]);
        }
        
        // Test pagination - page 1 with limit 10
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as total
            FROM messages 
            WHERE username = ?
        ");
        $stmt->execute([$testUsername]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertEquals(25, $result['total'], 'Should have 25 messages');
        
        // Test paginated query
        $limit = 10;
        $offset = 0;
        $stmt = $this->db->prepare("
            SELECT m.*, u.ip_address 
            FROM messages m
            LEFT JOIN user_activity u ON m.username = u.username
            WHERE m.username = :username
            ORDER BY m.created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':username', $testUsername, \PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $messages = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $this->assertCount(10, $messages, 'Should return 10 messages on page 1');
        
        // Test page 2
        $offset = 10;
        $stmt = $this->db->prepare("
            SELECT m.*, u.ip_address 
            FROM messages m
            LEFT JOIN user_activity u ON m.username = u.username
            WHERE m.username = :username
            ORDER BY m.created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':username', $testUsername, \PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $messages = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $this->assertCount(10, $messages, 'Should return 10 messages on page 2');
        
        // Test page 3 (should have 5 remaining)
        $offset = 20;
        $stmt = $this->db->prepare("
            SELECT m.*, u.ip_address 
            FROM messages m
            LEFT JOIN user_activity u ON m.username = u.username
            WHERE m.username = :username
            ORDER BY m.created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':username', $testUsername, \PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $messages = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $this->assertCount(5, $messages, 'Should return 5 messages on page 3');
        
        // Cleanup
        $stmt = $this->db->prepare("DELETE FROM messages WHERE username = ?");
        $stmt->execute([$testUsername]);
        $stmt = $this->db->prepare("DELETE FROM user_activity WHERE username = ?");
        $stmt->execute([$testUsername]);
    }
    
    /**
     * Test user details search functionality
     */
    public function testUserDetailsSearchQuery()
    {
        // Insert test user and messages
        $testUsername = 'search_test_' . uniqid();
        $testIp = '192.168.1.101';
        
        // Create user activity
        $stmt = $this->db->prepare("
            INSERT INTO user_activity (username, ip_address, first_seen, last_seen)
            VALUES (:username, :ip_address, NOW(), NOW())
            ON CONFLICT (username) DO NOTHING
        ");
        $stmt->execute([
            'username' => $testUsername,
            'ip_address' => $testIp
        ]);
        
        // Insert test messages with specific content
        $messages = [
            'Hello world from radio show',
            'Good morning everyone',
            'Playing your favorite music',
            'Hello again listeners',
            'Thanks for tuning in'
        ];
        
        foreach ($messages as $msg) {
            $stmt = $this->db->prepare("
                INSERT INTO messages (message_id, username, message, ip_address, created_at)
                VALUES (:message_id, :username, :message, :ip_address, NOW())
            ");
            $stmt->execute([
                'message_id' => uniqid(),
                'username' => $testUsername,
                'message' => $msg,
                'ip_address' => $testIp
            ]);
        }
        
        // Test search for "hello"
        $search = 'hello';
        $stmt = $this->db->prepare("
            SELECT COUNT(*) 
            FROM messages 
            WHERE username = ? AND message ILIKE ?
        ");
        $stmt->execute([$testUsername, '%' . $search . '%']);
        $count = (int)$stmt->fetchColumn();
        
        $this->assertEquals(2, $count, 'Should find 2 messages containing "hello"');
        
        // Test search with results
        $stmt = $this->db->prepare("
            SELECT m.message
            FROM messages m
            WHERE m.username = ? AND m.message ILIKE ?
            ORDER BY m.created_at DESC
        ");
        $stmt->execute([$testUsername, '%' . $search . '%']);
        $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $this->assertCount(2, $results);
        foreach ($results as $result) {
            $this->assertStringContainsStringIgnoringCase(
                'hello', 
                $result['message'],
                'Result should contain search term'
            );
        }
        
        // Cleanup
        $stmt = $this->db->prepare("DELETE FROM messages WHERE username = ?");
        $stmt->execute([$testUsername]);
        $stmt = $this->db->prepare("DELETE FROM user_activity WHERE username = ?");
        $stmt->execute([$testUsername]);
    }
}
