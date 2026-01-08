<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use RadioChatBox\Database;
use RadioChatBox\StatsService;

/**
 * Integration tests for StatsService compute methods
 * 
 * Tests the real-time computation of statistics for current periods:
 * - Today (computed from hourly stats)
 * - Current week (computed from hourly stats)
 * - Current month (computed from hourly stats)  
 * - Current year (computed from hourly stats)
 * 
 * These tests verify that fixes to the compute methods work correctly.
 * They require a running PostgreSQL instance.
 */
class StatsServiceComputeMethodsTest extends TestCase
{
    private static $pdo;
    private static $redis;
    private StatsService $statsService;

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

        $this->statsService = new StatsService();
        
        // Clear test data
        $this->clearTestData();
    }

    protected function tearDown(): void
    {
        // Clean up test data
        $this->clearTestData();
    }

    private function clearTestData(): void
    {
        if (!self::$pdo) return;
        
        try {
            // Delete test snapshots and stats created in the last hour
            self::$pdo->exec("DELETE FROM stats_snapshots WHERE snapshot_time > NOW() - INTERVAL '1 hour'");
            self::$pdo->exec("DELETE FROM stats_hourly WHERE stat_hour > NOW() - INTERVAL '25 hours'");
            self::$pdo->exec("DELETE FROM stats_daily WHERE stat_date >= CURRENT_DATE - INTERVAL '1 day'");
            
            // Clear Redis cache
            self::$redis->flushAll();
        } catch (\Exception $e) {
            // Ignore cleanup errors
        }
    }

    /**
     * Test that computeTodayStats computes from hourly data
     * 
     * This tests the fix for computing today's stats from stats_hourly
     * instead of relying on potentially stale stats_daily entries.
     */
    public function testComputeTodayStatsComputesFromHourlyData()
    {
        $today = date('Y-m-d');
        
        // Insert test hourly data for today
        $sql = "INSERT INTO stats_hourly 
                (stat_hour, active_users, guest_users, registered_users, 
                 total_messages, private_messages, photo_uploads, new_registrations,
                 radio_listeners_avg, radio_listeners_peak, peak_concurrent_users)
                VALUES (:stat_hour, :active, :guest, :registered, :total, 
                        :private, :photos, :new_reg, :listeners_avg, :listeners_peak, :peak)
                ON CONFLICT (stat_hour) DO UPDATE SET 
                  active_users = EXCLUDED.active_users";
        
        $stmt = self::$pdo->prepare($sql);
        $stmt->execute([
            'stat_hour' => $today . ' 10:00:00',
            'active' => 5,
            'guest' => 2,
            'registered' => 3,
            'total' => 15,
            'private' => 3,
            'photos' => 2,
            'new_reg' => 1,
            'listeners_avg' => 10,
            'listeners_peak' => 25,
            'peak' => 7
        ]);
        
        // Get summary which calls computeTodayStats
        $summary = $this->statsService->getSummary();
        
        $this->assertIsArray($summary);
        $this->assertArrayHasKey('today', $summary);
        
        $today_data = $summary['today'];
        $this->assertEquals(5, $today_data['active_users'], 'Should compute active_users from hourly');
        $this->assertEquals(2, $today_data['guest_users'], 'Should compute guest_users from hourly');
        $this->assertEquals(3, $today_data['registered_users'], 'Should compute registered_users from hourly');
        $this->assertEquals(15, $today_data['total_messages'], 'Should compute total_messages from hourly');
    }

    /**
     * Test that computeCurrentWeekStats computes from hourly data
     * 
     * This tests that weekly stats include current week data computed
     * from stats_hourly, not relying on pre-aggregated stats_daily.
     */
    public function testComputeCurrentWeekStatsComputesFromHourlyData()
    {
        $today = date('Y-m-d');
        
        // Insert test hourly data for this week
        $sql = "INSERT INTO stats_hourly 
                (stat_hour, active_users, guest_users, registered_users, 
                 total_messages, private_messages, photo_uploads, new_registrations,
                 radio_listeners_avg, radio_listeners_peak, peak_concurrent_users)
                VALUES (:stat_hour, 10, 4, 6, 50, 5, 3, 2, 15, 35, 12)
                ON CONFLICT (stat_hour) DO UPDATE SET active_users = EXCLUDED.active_users";
        
        $stmt = self::$pdo->prepare($sql);
        $stmt->execute(['stat_hour' => $today . ' 14:00:00']);
        
        // Get weekly stats
        $weekly = $this->statsService->getWeeklyStats(null, 1);
        
        $this->assertIsArray($weekly);
        $this->assertCount(1, $weekly);
        
        $currentWeek = $weekly[0];
        $this->assertArrayHasKey('stat_week', $currentWeek);
        $this->assertEquals(10, $currentWeek['active_users'], 'Week should include current hourly data');
        $this->assertEquals(50, $currentWeek['total_messages'], 'Week should include current hourly data');
    }

    /**
     * Test that column names are correct for radio_listeners
     * 
     * This tests the fix for using radio_listeners_avg and radio_listeners_peak
     * instead of a non-existent radio_listeners column in stats_hourly.
     */
    public function testRadioListenerColumnNamesAreCorrect()
    {
        $today = date('Y-m-d');
        
        // Insert test data with specific radio listener values
        $sql = "INSERT INTO stats_hourly 
                (stat_hour, active_users, guest_users, registered_users, 
                 total_messages, private_messages, photo_uploads, new_registrations,
                 radio_listeners_avg, radio_listeners_peak, peak_concurrent_users)
                VALUES (:stat_hour, 1, 0, 1, 1, 0, 0, 0, 15, 40, 1)
                ON CONFLICT (stat_hour) DO UPDATE SET radio_listeners_avg = EXCLUDED.radio_listeners_avg";
        
        $stmt = self::$pdo->prepare($sql);
        $stmt->execute(['stat_hour' => $today . ' 11:00:00']);
        
        // Get summary
        $summary = $this->statsService->getSummary();
        
        $this->assertIsArray($summary);
        $this->assertArrayHasKey('today', $summary);
        
        $today_data = $summary['today'];
        $this->assertEquals(15, $today_data['radio_listeners_avg'], 
            'Should correctly read radio_listeners_avg from stats_hourly');
        $this->assertEquals(40, $today_data['radio_listeners_peak'],
            'Should correctly read radio_listeners_peak from stats_hourly');
    }

    /**
     * Test that compute methods handle zero values correctly
     */
    public function testComputeMethodsHandleZeroValues()
    {
        $today = date('Y-m-d');
        
        // Insert hourly data with all zeros
        $sql = "INSERT INTO stats_hourly 
                (stat_hour, active_users, guest_users, registered_users, 
                 total_messages, private_messages, photo_uploads, new_registrations,
                 radio_listeners_avg, radio_listeners_peak, peak_concurrent_users)
                VALUES (:stat_hour, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0)
                ON CONFLICT (stat_hour) DO UPDATE SET active_users = EXCLUDED.active_users";
        
        $stmt = self::$pdo->prepare($sql);
        $stmt->execute(['stat_hour' => $today . ' 12:00:00']);
        
        $summary = $this->statsService->getSummary();
        
        // Should return array with zero values, not null
        $this->assertIsArray($summary['today']);
        $this->assertEquals(0, $summary['today']['total_messages']);
        $this->assertEquals(0, $summary['today']['active_users']);
    }

    /**
     * Test getSummary returns all expected fields
     * 
     * Tests that the summary includes data for today, this week, this month,
     * this year, and latest snapshot.
     */
    public function testGetSummaryReturnsAllPeriods()
    {
        $summary = $this->statsService->getSummary();
        
        $this->assertIsArray($summary);
        $this->assertArrayHasKey('today', $summary, 'Summary should include today data');
        $this->assertArrayHasKey('this_week', $summary, 'Summary should include this_week data');
        $this->assertArrayHasKey('this_month', $summary, 'Summary should include this_month data');
        $this->assertArrayHasKey('this_year', $summary, 'Summary should include this_year data');
        $this->assertArrayHasKey('latest_snapshot', $summary, 'Summary should include latest_snapshot');
        $this->assertArrayHasKey('generated_at', $summary, 'Summary should include generated_at timestamp');
        
        // Check that all period data has expected fields (today/week/month/year may be null/empty)
        $requiredFields = ['active_users', 'guest_users', 'registered_users', 
                          'total_messages', 'private_messages', 'photo_uploads'];
        
        // Only check if data exists
        if (is_array($summary['today']) && !empty($summary['today'])) {
            foreach ($requiredFields as $field) {
                $this->assertArrayHasKey($field, $summary['today'], "Today should have $field");
            }
        }
        
        if (is_array($summary['this_week']) && !empty($summary['this_week'])) {
            foreach ($requiredFields as $field) {
                $this->assertArrayHasKey($field, $summary['this_week'], "Week should have $field");
            }
        }
    }

    /**
     * Test that compute methods use stats_hourly not stats_daily for current periods
     * 
     * This is a regression test to ensure that the fix to compute from hourly data
     * hasn't been accidentally reverted.
     */
    public function testComputeMethodsPreferHourlyDataOverDaily()
    {
        $today = date('Y-m-d');
        
        // Insert hourly data
        self::$pdo->prepare("
            INSERT INTO stats_hourly 
            (stat_hour, active_users, total_messages, radio_listeners_avg, radio_listeners_peak)
            VALUES (:hour, 8, 24, 20, 50)
            ON CONFLICT (stat_hour) DO UPDATE SET active_users = EXCLUDED.active_users
        ")->execute(['hour' => $today . ' 15:00:00']);
        
        // Insert stale daily data (different values)
        self::$pdo->prepare("
            INSERT INTO stats_daily
            (stat_date, active_users, total_messages, radio_listeners_avg, radio_listeners_peak)
            VALUES (:date, 2, 6, 5, 10)
            ON CONFLICT (stat_date) DO UPDATE SET active_users = EXCLUDED.active_users
        ")->execute(['date' => $today]);
        
        $summary = $this->statsService->getSummary();
        
        // The summary should use hourly data (8, 24) not daily data (2, 6)
        $this->assertEquals(8, $summary['today']['active_users'], 
            'Should use hourly data (8) not daily data (2)');
        $this->assertEquals(24, $summary['today']['total_messages'],
            'Should use hourly data (24) not daily data (6)');
    }
}

