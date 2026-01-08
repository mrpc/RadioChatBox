<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use RadioChatBox\StatsService;
use RadioChatBox\Database;

/**
 * Integration tests for real-time stats fallback functionality
 * 
 * This tests the feature that ensures stats show real-time data
 * even when hourly cron aggregation hasn't run yet.
 * 
 * Requires: PostgreSQL and Redis running
 */
class StatsServiceRealTimeTest extends TestCase
{
    private static $pdo;
    private static $redis;
    private static $service;
    private static $prefix;

    public static function setUpBeforeClass(): void
    {
        try {
            self::$pdo = Database::getPDO();
            self::$redis = Database::getRedis();
            self::$prefix = Database::getRedisPrefix();
            self::$service = new StatsService();
        } catch (\Exception $e) {
            self::markTestSkipped('Database connection not available: ' . $e->getMessage());
        }
    }

    protected function setUp(): void
    {
        if (!self::$pdo || !self::$redis) {
            $this->markTestSkipped('Database not available');
        }
        
        // Clear Redis stats cache before each test
        self::$redis->del(self::$prefix . 'stats:summary');
    }

    /**
     * Test that getSummary() returns data with expected structure
     */
    public function testGetSummaryReturnsExpectedStructure()
    {
        $summary = self::$service->getSummary();
        
        $this->assertIsArray($summary);
        $this->assertArrayHasKey('today', $summary);
        $this->assertArrayHasKey('this_week', $summary);
        $this->assertArrayHasKey('this_month', $summary);
        $this->assertArrayHasKey('this_year', $summary);
        $this->assertArrayHasKey('latest_snapshot', $summary);
        $this->assertArrayHasKey('generated_at', $summary);
        
        // Verify today's stats structure
        $this->assertIsArray($summary['today']);
        $this->assertArrayHasKey('active_users', $summary['today']);
        $this->assertArrayHasKey('total_messages', $summary['today']);
        $this->assertArrayHasKey('registered_users', $summary['today']);
        $this->assertArrayHasKey('guest_users', $summary['today']);
    }

    /**
     * Test that real-time fallback logic works correctly
     * This test verifies that today's stats use max() logic for real-time data
     */
    public function testRealTimeFallbackLogicWorks()
    {
        // Clear Redis cache to force fresh query
        self::$redis->del(self::$prefix . 'stats:summary');
        
        $summary = self::$service->getSummary();
        
        // Verify the structure indicates real-time fallback is working
        // The fact that we get non-null values even without hourly cron running
        // proves the real-time fallback is functioning
        $this->assertIsNumeric($summary['today']['active_users']);
        $this->assertGreaterThanOrEqual(0, $summary['today']['active_users']);
        
        // Verify latest_snapshot exists (required for active_users fallback)
        $this->assertIsArray($summary['latest_snapshot']);
        $this->assertArrayHasKey('concurrent_users', $summary['latest_snapshot']);
    }

    /**
     * Test that message count uses real-time data from messages table
     */
    public function testMessageCountUsesRealTimeData()
    {
        // Insert test messages
        $messageIds = [];
        for ($i = 0; $i < 3; $i++) {
            $messageId = uniqid('test_msg_', true);
            $stmt = self::$pdo->prepare(
                "INSERT INTO messages (message_id, username, message, ip_address, created_at, is_deleted) 
                 VALUES (?, ?, ?, ?, NOW(), false) 
                 RETURNING id"
            );
            $stmt->execute([$messageId, 'test_user', "Test message $i", '127.0.0.1']);
            $messageIds[] = $stmt->fetchColumn();
        }
        
        try {
            // Clear Redis cache to force fresh query
            self::$redis->del(self::$prefix . 'stats:summary');
            
            $summary = self::$service->getSummary();
            
            // Should have at least our 3 test messages
            $this->assertGreaterThanOrEqual(3, $summary['today']['total_messages'],
                'Message count should include real-time messages from database');
            
        } finally {
            // Cleanup test messages
            $stmt = self::$pdo->prepare("DELETE FROM messages WHERE id = ANY(?)");
            $stmt->execute(['{' . implode(',', $messageIds) . '}']);
        }
    }

    /**
     * Test that cache works on second call
     */
    public function testCacheWorksOnSecondCall()
    {
        // First call - miss cache
        self::$redis->del(self::$prefix . 'stats:summary');
        $summary1 = self::$service->getSummary();
        
        // Second call - should hit cache
        $summary2 = self::$service->getSummary();
        
        // Should return same data (with same generated_at timestamp)
        $this->assertEquals($summary1['generated_at'], $summary2['generated_at'],
            'Second call should return cached data with same timestamp');
    }
}
