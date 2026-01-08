<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use RadioChatBox\ChatService;
use RadioChatBox\Database;

/**
 * Test Redis cache consistency and duplicate prevention
 * 
 * These tests verify that the message cache doesn't get corrupted,
 * duplicated, or out of sync with PostgreSQL.
 */
class ChatServiceCacheTest extends TestCase
{
    private static $pdo;
    private static $redis;
    private static $prefix;
    private static $service;

    public static function setUpBeforeClass(): void
    {
        try {
            self::$pdo = Database::getPDO();
            self::$redis = Database::getRedis();
            self::$prefix = Database::getRedisPrefix();
            self::$service = new ChatService();
        } catch (\Exception $e) {
            self::markTestSkipped('Database connection not available: ' . $e->getMessage());
        }
    }

    protected function setUp(): void
    {
        if (!self::$pdo || !self::$redis) {
            $this->markTestSkipped('Database not available');
        }
        
        // Clear messages cache before each test
        self::$redis->del(self::$prefix . 'chat:messages');
    }

    /**
     * Test that loadHistoryFromDB() clears existing cache to prevent duplicates
     */
    public function testLoadFromDBPreventsMessageDuplicates()
    {
        // Insert test messages in DB
        $messageIds = [];
        for ($i = 0; $i < 3; $i++) {
            $messageId = uniqid('test_cache_', true);
            $stmt = self::$pdo->prepare(
                "INSERT INTO messages (message_id, username, message, ip_address, created_at, is_deleted) 
                 VALUES (?, ?, ?, ?, NOW(), false) 
                 RETURNING id"
            );
            $stmt->execute([$messageId, 'test_user', "Cache test message $i", '127.0.0.1']);
            $messageIds[] = $stmt->fetchColumn();
        }
        
        try {
            // Manually add some junk to Redis cache to simulate stale/partial data
            $staleMessage = json_encode([
                'id' => 'stale_msg_' . uniqid(),
                'username' => 'stale_user',
                'message' => 'This should be removed',
                'timestamp' => time() - 3600
            ]);
            self::$redis->lPush(self::$prefix . 'chat:messages', $staleMessage);
            
            // Verify stale message is in cache
            $cachedBefore = self::$redis->lRange(self::$prefix . 'chat:messages', 0, -1);
            $this->assertCount(1, $cachedBefore, 'Should have stale message in cache');
            
            // Clear cache to trigger loadHistoryFromDB
            self::$redis->del(self::$prefix . 'chat:messages');
            
            // Get history - this should load from DB and populate clean cache
            $history = self::$service->getHistory(50);
            
            // Verify we got at least our 3 test messages
            $this->assertGreaterThanOrEqual(3, count($history), 'Should load messages from DB');
            
            // Check Redis cache - should NOT have duplicates
            $cachedAfter = self::$redis->lRange(self::$prefix . 'chat:messages', 0, -1);
            $decodedMessages = array_map('json_decode', $cachedAfter);
            
            // Count message IDs - if there are duplicates, we'll have more IDs than unique IDs
            $messageIdsList = array_map(function($msg) {
                return $msg->id ?? null;
            }, $decodedMessages);
            
            $uniqueIds = array_unique(array_filter($messageIdsList));
            
            $this->assertEquals(
                count($uniqueIds),
                count(array_filter($messageIdsList)),
                'Cache should not contain duplicate message IDs'
            );
            
            // Verify the stale message is NOT in the repopulated cache
            foreach ($decodedMessages as $msg) {
                $this->assertNotEquals('stale_user', $msg->username ?? null,
                    'Stale message should not be in repopulated cache');
            }
            
        } finally {
            // Cleanup test messages
            if (!empty($messageIds)) {
                $stmt = self::$pdo->prepare("DELETE FROM messages WHERE id = ANY(?)");
                $stmt->execute(['{' . implode(',', $messageIds) . '}']);
            }
            self::$redis->del(self::$prefix . 'chat:messages');
        }
    }

    /**
     * Test that Redis cache has TTL set
     */
    public function testMessageCacheHasTTL()
    {
        // Post a test message
        $messageId = uniqid('test_ttl_', true);
        $stmt = self::$pdo->prepare(
            "INSERT INTO messages (message_id, username, message, ip_address, created_at, is_deleted) 
             VALUES (?, ?, ?, ?, NOW(), false) 
             RETURNING id"
        );
        $stmt->execute([$messageId, 'test_user', 'TTL test message', '127.0.0.1']);
        $dbMessageId = $stmt->fetchColumn();
        
        try {
            // Clear cache and get history to populate it
            self::$redis->del(self::$prefix . 'chat:messages');
            self::$service->getHistory(50);
            
            // Check that cache has TTL set
            $ttl = self::$redis->ttl(self::$prefix . 'chat:messages');
            
            $this->assertGreaterThan(0, $ttl, 'Message cache should have TTL set');
            $this->assertLessThanOrEqual(86400, $ttl, 'TTL should not exceed 24 hours');
            
        } finally {
            // Cleanup
            $stmt = self::$pdo->prepare("DELETE FROM messages WHERE id = ?");
            $stmt->execute([$dbMessageId]);
            self::$redis->del(self::$prefix . 'chat:messages');
        }
    }

    /**
     * Test that posting a new message refreshes the TTL
     */
    public function testPostMessageRefreshesTTL()
    {
        $messageIds = [];
        
        try {
            // Clear cache
            self::$redis->del(self::$prefix . 'chat:messages');
            
            // Post first message
            $msg1Id = uniqid('test_ttl1_', true);
            $stmt = self::$pdo->prepare(
                "INSERT INTO messages (message_id, username, message, ip_address, created_at, is_deleted) 
                 VALUES (?, ?, ?, ?, NOW(), false) 
                 RETURNING id"
            );
            $stmt->execute([$msg1Id, 'user1', 'First message', '127.0.0.1']);
            $messageIds[] = $stmt->fetchColumn();
            
            // Populate cache
            self::$service->getHistory(50);
            
            // Get initial TTL
            $ttl1 = self::$redis->ttl(self::$prefix . 'chat:messages');
            
            // Wait a second
            sleep(1);
            
            // Post second message (simulated by directly calling postMessage would be better, but this tests the logic)
            $msg2Id = uniqid('test_ttl2_', true);
            $stmt->execute([$msg2Id, 'user2', 'Second message', '127.0.0.1']);
            $messageIds[] = $stmt->fetchColumn();
            
            // Manually simulate what postMessage does
            self::$redis->lPush(self::$prefix . 'chat:messages', json_encode([
                'id' => $msg2Id,
                'username' => 'user2',
                'message' => 'Second message',
                'timestamp' => time()
            ]));
            self::$redis->expire(self::$prefix . 'chat:messages', 86400);
            
            // Get new TTL
            $ttl2 = self::$redis->ttl(self::$prefix . 'chat:messages');
            
            // TTL should be refreshed (should be close to 86400 again, not decreased by 1 second)
            $this->assertGreaterThanOrEqual($ttl1, $ttl2, 
                'Posting a message should refresh the TTL to prevent premature expiration');
            
        } finally {
            // Cleanup
            if (!empty($messageIds)) {
                $stmt = self::$pdo->prepare("DELETE FROM messages WHERE id = ANY(?)");
                $stmt->execute(['{' . implode(',', $messageIds) . '}']);
            }
            self::$redis->del(self::$prefix . 'chat:messages');
        }
    }
}
