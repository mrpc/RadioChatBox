<?php

namespace RadioChatBox\Tests;

use Pramnos\Framework\Testing\TestDatabase;

use PHPUnit\Framework\TestCase;
use Pramnos\Cache\FlatCache;
use RadioChatBox\Services\StatsService;
use Pramnos\Database\Database;

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
            self::$pdo = TestDatabase::connection();
            self::$redis = \Pramnos\Redis\ConnectionManager::getInstance()->connection();
            self::$prefix = \Pramnos\Redis\ConnectionManager::getInstance()->prefix();
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
        
        // Clear test data
        $this->clearTestData();
    }

    protected function tearDown(): void
    {
        // Clean up after each test
        $this->clearTestData();
    }

    private function clearTestData(): void
    {
        try {
            // Clear Redis stats cache (don't add prefix - Redis client handles it)
            FlatCache::default()->delete('stats:summary');
            
            // Clean up test messages and sessions from today
            self::$pdo->exec("DELETE FROM chat_messages WHERE created_at >= CURRENT_DATE AND message LIKE '%Test message%'");
            self::$pdo->exec("DELETE FROM presence_sessions WHERE last_heartbeat >= CURRENT_DATE");
            self::$pdo->exec("DELETE FROM stats_hourly WHERE stat_hour >= CURRENT_DATE");
        } catch (\Exception $e) {
            // Ignore cleanup errors
        }
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
        FlatCache::default()->delete('stats:summary');
        
        $summary = self::$service->getSummary();
        
        // Verify the structure indicates real-time fallback is working
        // The fact that we get non-null values even without hourly cron running
        // proves the real-time fallback is functioning
        $this->assertIsNumeric($summary['today']['active_users']);
        $this->assertGreaterThanOrEqual(0, $summary['today']['active_users']);
        
        // Verify latest_snapshot exists (required for active_users fallback) or is null
        // It's ok if snapshot is null when no recent snapshot data exists
        if ($summary['latest_snapshot'] !== null) {
            $this->assertIsArray($summary['latest_snapshot']);
            $this->assertArrayHasKey('concurrent_users', $summary['latest_snapshot']);
        }
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
                "INSERT INTO chat_messages (message_id, username, message, ip_address, created_at, is_deleted) 
                 VALUES (?, ?, ?, ?, NOW(), false) 
                 RETURNING id"
            );
            $stmt->execute([$messageId, 'test_user', "Test message $i", '127.0.0.1']);
            $messageIds[] = $stmt->fetchColumn();
        }
        
        try {
            // Clear Redis cache to force fresh query
            FlatCache::default()->delete('stats:summary');
            
            $summary = self::$service->getSummary();
            
            // Should have at least our 3 test messages
            $this->assertGreaterThanOrEqual(3, $summary['today']['total_messages'],
                'Message count should include real-time messages from database');
            
        } finally {
            // Cleanup test messages
            $stmt = self::$pdo->prepare("DELETE FROM chat_messages WHERE id = ANY(?)");
            $stmt->execute(['{' . implode(',', $messageIds) . '}']);
        }
    }

    /**
     * On a private-mode install the day's activity is DMs, so getSummary must
     * count private_messages in real time too (not wait for the hourly cron) —
     * otherwise the dashboard's "messages today" reads 0 while the chat is busy.
     */
    public function testPrivateMessageCountUsesRealTimeData()
    {
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $stmt = self::$pdo->prepare(
                "INSERT INTO private_messages (from_username, to_username, message, created_at)
                 VALUES (?, ?, ?, NOW()) RETURNING id"
            );
            $stmt->execute(['rt_from_' . $i, 'rt_to_' . $i, "DM $i"]);
            $ids[] = $stmt->fetchColumn();
        }

        try {
            FlatCache::default()->delete('stats:summary');
            $summary = self::$service->getSummary();
            $this->assertGreaterThanOrEqual(3, $summary['today']['private_messages'],
                'private_messages today must include real-time DMs');
        } finally {
            $stmt = self::$pdo->prepare("DELETE FROM private_messages WHERE id = ANY(?)");
            $stmt->execute(['{' . implode(',', $ids) . '}']);
        }
    }

    /**
     * People who only ever send DMs are still users. The counts came from the
     * public messages table alone, so a DM-only install reported "Active Users:
     * 0" all day next to thousands of private messages.
     */
    public function testDmSendersCountAsActiveUsers()
    {
        $before = $this->activeUserCountsToday();

        $ids = [];
        try {
            foreach (['dm_guest_a', 'dm_guest_b'] as $sender) {
                $stmt = self::$pdo->prepare(
                    "INSERT INTO private_messages (from_username, to_username, message, created_at)
                     VALUES (?, 'somebody', 'hello', NOW()) RETURNING id"
                );
                $stmt->execute([$sender]);
                $ids[] = $stmt->fetchColumn();
            }

            $after = $this->activeUserCountsToday();

            $this->assertSame($before['active_users'] + 2, $after['active_users'],
                'both DM senders must count as active users');
            $this->assertSame($before['guest_users'] + 2, $after['guest_users'],
                'unregistered DM senders count as guests');

            FlatCache::default()->delete('stats:summary');
            $summary = self::$service->getSummary();
            $this->assertGreaterThanOrEqual(2, $summary['today']['active_users'],
                'the dashboard summary must see the DM senders');
        } finally {
            $stmt = self::$pdo->prepare("DELETE FROM private_messages WHERE id = ANY(?)");
            $stmt->execute(['{' . implode(',', $ids) . '}']);
        }
    }

    /** A bot talking to itself is not an audience: fake users are never counted. */
    public function testBotsAreNotCountedAsActiveUsers()
    {
        $before = $this->activeUserCountsToday();

        $nickname = 'stats_test_bot';
        $ids = [];
        try {
            $stmt = self::$pdo->prepare(
                "INSERT INTO fake_users (nickname, is_active) VALUES (?, true)
                 ON CONFLICT (nickname) DO NOTHING"
            );
            $stmt->execute([$nickname]);

            $stmt = self::$pdo->prepare(
                "INSERT INTO private_messages (from_username, to_username, message, created_at)
                 VALUES (?, 'dm_guest_c', 'hi there', NOW()) RETURNING id"
            );
            $stmt->execute([$nickname]);
            $ids[] = $stmt->fetchColumn();

            $this->assertSame($before, $this->activeUserCountsToday(),
                'a fake user (bot) must not move the user counts');
        } finally {
            $stmt = self::$pdo->prepare("DELETE FROM private_messages WHERE id = ANY(?)");
            $stmt->execute(['{' . implode(',', $ids) . '}']);
            $stmt = self::$pdo->prepare("DELETE FROM fake_users WHERE nickname = ?");
            $stmt->execute([$nickname]);
        }
    }

    /** The stored hourly rollup — what the daily chart is built from — sees DMs too. */
    public function testHourlyAggregationCountsDmSenders()
    {
        $hour = self::$pdo
            ->query("SELECT date_trunc('hour', NOW() - INTERVAL '1 hour')")
            ->fetchColumn();

        $ids = [];
        try {
            $stmt = self::$pdo->prepare(
                "INSERT INTO private_messages (from_username, to_username, message, created_at)
                 VALUES ('dm_hourly_guest', 'somebody', 'hello',
                         date_trunc('hour', NOW() - INTERVAL '1 hour') + INTERVAL '5 minutes')
                 RETURNING id"
            );
            $stmt->execute();
            $ids[] = $stmt->fetchColumn();

            $this->assertTrue(self::$service->aggregateHourlyStats($hour));

            $stmt = self::$pdo->prepare(
                "SELECT active_users, guest_users FROM stats_hourly WHERE stat_hour = ?"
            );
            $stmt->execute([$hour]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            $this->assertNotFalse($row, 'the hour must have been aggregated');
            $this->assertGreaterThanOrEqual(1, (int) $row['active_users'],
                'a DM-only hour must not aggregate to zero active users');
            $this->assertGreaterThanOrEqual(1, (int) $row['guest_users']);
        } finally {
            $stmt = self::$pdo->prepare("DELETE FROM private_messages WHERE id = ANY(?)");
            $stmt->execute(['{' . implode(',', $ids) . '}']);
            $stmt = self::$pdo->prepare("DELETE FROM stats_hourly WHERE stat_hour = ?");
            $stmt->execute([$hour]);
        }
    }

    /** The shared SQL function both the aggregations and the summary read. */
    private function activeUserCountsToday(): array
    {
        $row = self::$pdo
            ->query("SELECT active_users, guest_users, registered_users
                     FROM active_user_counts(CURRENT_DATE, CURRENT_DATE + 1)")
            ->fetch(\PDO::FETCH_ASSOC);

        return array_map('intval', $row);
    }

    /**
     * Test that cache works on second call
     */
    public function testCacheWorksOnSecondCall()
    {
        // First call - miss cache
        FlatCache::default()->delete('stats:summary');
        $summary1 = self::$service->getSummary();
        
        // Second call - should hit cache
        $summary2 = self::$service->getSummary();
        
        // Should return same data (with same generated_at timestamp)
        $this->assertEquals($summary1['generated_at'], $summary2['generated_at'],
            'Second call should return cached data with same timestamp');
    }
}
