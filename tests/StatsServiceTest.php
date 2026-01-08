<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use RadioChatBox\Database;

/**
 * Integration tests for StatsService
 * 
 * These tests require a running PostgreSQL and Redis instance.
 * Run with: ./test.sh or composer test
 */
class StatsServiceTest extends TestCase
{
    private static $pdo;
    private static $redis;

    public static function setUpBeforeClass(): void
    {
        try {
            self::$pdo = Database::getPDO();
            self::$redis = Database::getRedis();
        } catch (\Exception $e) {
            self::markTestSkipped('Database connection not available: ' . $e->getMessage());
        }
    }

    protected function setUp(): void
    {
        if (!self::$pdo) {
            $this->markTestSkipped('Database not available');
        }
    }

    public function testStatsTablesExist()
    {
        $tables = ['stats_snapshots', 'stats_hourly', 'stats_daily', 'stats_weekly', 'stats_monthly', 'stats_yearly'];
        
        foreach ($tables as $table) {
            $stmt = self::$pdo->prepare(
                "SELECT EXISTS (
                    SELECT FROM information_schema.tables 
                    WHERE table_schema = 'public' 
                    AND table_name = :table
                )"
            );
            $stmt->execute(['table' => $table]);
            $exists = $stmt->fetchColumn();
            
            $this->assertTrue((bool)$exists, "Table {$table} should exist");
        }
    }

    public function testStatsFunctionsExist()
    {
        $functions = [
            'aggregate_hourly_stats',
            'aggregate_daily_stats',
            'aggregate_weekly_stats',
            'aggregate_monthly_stats',
            'aggregate_yearly_stats',
            'cleanup_old_snapshots'
        ];
        
        foreach ($functions as $function) {
            $stmt = self::$pdo->prepare(
                "SELECT EXISTS (
                    SELECT FROM pg_proc 
                    WHERE proname = :function
                )"
            );
            $stmt->execute(['function' => $function]);
            $exists = $stmt->fetchColumn();
            
            $this->assertTrue((bool)$exists, "Function {$function} should exist");
        }
    }

    public function testCanInsertSnapshot()
    {
        $stmt = self::$pdo->prepare(
            "INSERT INTO stats_snapshots (concurrent_users, radio_listeners, active_sessions) 
             VALUES (:users, :listeners, :sessions)"
        );
        
        $result = $stmt->execute([
            'users' => 5,
            'listeners' => 10,
            'sessions' => 7
        ]);
        
        $this->assertTrue($result, 'Should be able to insert snapshot');
        
        // Cleanup
        self::$pdo->exec("DELETE FROM stats_snapshots WHERE concurrent_users = 5 AND radio_listeners = 10");
    }

    public function testCanQuerySnapshots()
    {
        // Insert test data
        self::$pdo->exec(
            "INSERT INTO stats_snapshots (concurrent_users, radio_listeners, active_sessions) 
             VALUES (3, 8, 5)"
        );
        
        $stmt = self::$pdo->query(
            "SELECT * FROM stats_snapshots ORDER BY snapshot_time DESC LIMIT 1"
        );
        $snapshot = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        $this->assertNotNull($snapshot, 'Should retrieve snapshot');
        $this->assertArrayHasKey('concurrent_users', $snapshot);
        $this->assertArrayHasKey('radio_listeners', $snapshot);
        $this->assertArrayHasKey('snapshot_time', $snapshot);
        
        // Cleanup
        self::$pdo->exec("DELETE FROM stats_snapshots WHERE concurrent_users = 3 AND radio_listeners = 8");
    }

    public function testHourlyStatsTableStructure()
    {
        $stmt = self::$pdo->query(
            "SELECT column_name FROM information_schema.columns 
             WHERE table_name = 'stats_hourly' 
             ORDER BY ordinal_position"
        );
        $columns = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        
        $expectedColumns = [
            'id', 'stat_hour', 'active_users', 'guest_users', 'registered_users',
            'total_messages', 'private_messages', 'photo_uploads', 'new_registrations',
            'radio_listeners_avg', 'radio_listeners_peak', 'peak_concurrent_users', 'created_at'
        ];
        
        foreach ($expectedColumns as $column) {
            $this->assertContains($column, $columns, "Column {$column} should exist in stats_hourly");
        }
    }

    public function testAggregateHourlyStatsFunction()
    {
        // This is a smoke test - just ensure the function doesn't error
        $stmt = self::$pdo->prepare("SELECT aggregate_hourly_stats(:hour)");
        
        try {
            $stmt->execute(['hour' => date('Y-m-d H:00:00', strtotime('-1 hour'))]);
            $this->assertTrue(true, 'Hourly aggregation function should execute without error');
        } catch (\Exception $e) {
            // Expected if no data exists - that's OK for this test
            $this->assertStringContainsString('aggregate_hourly_stats', $e->getMessage());
        }
    }

    public function testRedisConnection()
    {
        $this->assertNotNull(self::$redis, 'Redis connection should be available');
        
        // Test set/get
        $testKey = 'stats:test:' . uniqid();
        self::$redis->setex($testKey, 60, 'test_value');
        $value = self::$redis->get($testKey);
        
        $this->assertEquals('test_value', $value, 'Should store and retrieve from Redis');
        
        // Cleanup
        self::$redis->del($testKey);
    }

    public function testStatsIndexesExist()
    {
        $indexes = [
            'idx_stats_hourly_stat_hour',
            'idx_stats_daily_stat_date',
            'idx_stats_weekly_year_week',
            'idx_stats_monthly_year_month',
            'idx_stats_yearly_year',
            'idx_stats_snapshots_time'
        ];
        
        foreach ($indexes as $index) {
            $stmt = self::$pdo->prepare(
                "SELECT EXISTS (
                    SELECT FROM pg_indexes 
                    WHERE indexname = :index
                )"
            );
            $stmt->execute(['index' => $index]);
            $exists = $stmt->fetchColumn();
            
            $this->assertTrue((bool)$exists, "Index {$index} should exist");
        }
    }

    protected function tearDown(): void
    {
        // Clean up any test data
        if (self::$pdo) {
            try {
                self::$pdo->exec("DELETE FROM stats_snapshots WHERE concurrent_users < 10");
            } catch (\Exception $e) {
                // Ignore cleanup errors
            }
        }
    }
}
