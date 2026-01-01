<?php

namespace RadioChatBox;

use PDO;
use Redis;
use RuntimeException;

/**
 * StatsService - Handles collection and retrieval of statistics
 * 
 * Provides methods to:
 * - Record real-time snapshots (concurrent users, radio listeners)
 * - Aggregate hourly/daily/weekly/monthly/yearly stats
 * - Retrieve stats for admin dashboard
 */
class StatsService
{
    private PDO $pdo;
    private Redis $redis;
    private RadioStatusService $radioStatus;
    private bool $tablesChecked = false;

    public function __construct()
    {
        $this->pdo = Database::getPDO();
        $this->redis = Database::getRedis();
        $this->radioStatus = new RadioStatusService();
    }

    /**
     * Ensure statistics tables exist. Auto-creates if missing.
     * Only checks once per instance.
     */
    private function ensureTablesExist(): void
    {
        if ($this->tablesChecked) {
            return;
        }

        try {
            // Quick check if main table exists
            $stmt = $this->pdo->query(
                "SELECT EXISTS (
                    SELECT FROM information_schema.tables 
                    WHERE table_schema = 'public' 
                    AND table_name = 'stats_snapshots'
                )"
            );
            $exists = $stmt->fetchColumn();

            if (!$exists) {
                error_log('Statistics tables not found, creating automatically...');
                $this->createStatsTables();
            }

            $this->tablesChecked = true;
        } catch (\Exception $e) {
            error_log('Failed to check/create stats tables: ' . $e->getMessage());
            throw new RuntimeException('Statistics tables not available');
        }
    }

    /**
     * Create statistics tables from migration SQL
     */
    private function createStatsTables(): void
    {
        $migrationFile = __DIR__ . '/../database/migrations/006_add_statistics_tables.sql';
        
        if (!file_exists($migrationFile)) {
            throw new RuntimeException('Statistics migration file not found');
        }

        $sql = file_get_contents($migrationFile);
        $this->pdo->exec($sql);
        
        error_log('Statistics tables created successfully');
    }

    /**
     * Record a real-time snapshot of current activity.
     * Can be called by cron every 5-15 minutes, or triggered on-demand by API calls.
     * Includes rate-limiting to prevent excessive writes (max once per 5 minutes).
     * 
     * @param bool $ignoreRateLimit Force record even if rate-limited (for manual triggers)
     * @return array Snapshot data that was recorded
     */
    public function recordSnapshot(bool $ignoreRateLimit = false): array
    {
        // Rate limiting: don't record more than once per 5 minutes
        if (!$ignoreRateLimit) {
            $lastSnapshotKey = 'stats:last_snapshot_time';
            $lastSnapshot = $this->redis->get($lastSnapshotKey);
            
            if ($lastSnapshot !== false) {
                $lastTime = (int)$lastSnapshot;
                $now = time();
                
                // If less than 5 minutes have passed, skip recording
                if (($now - $lastTime) < 300) {
                    // Return cached snapshot instead of recording
                    $cached = $this->redis->get('stats:latest_snapshot');
                    if ($cached !== false) {
                        return json_decode($cached, true);
                    }
                    return [];
                }
            }
            
            // Update last snapshot time
            $this->redis->setex($lastSnapshotKey, 3600, time());
        }
        $this->ensureTablesExist();
        
        // Count concurrent users (unique usernames in sessions)
        $stmt = $this->pdo->query("
            SELECT COUNT(DISTINCT username) as count
            FROM sessions
            WHERE last_heartbeat > NOW() - INTERVAL '5 minutes'
        ");
        $concurrentUsers = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];

        // Count total active sessions (including multiple tabs)
        $stmt = $this->pdo->query("
            SELECT COUNT(*) as count
            FROM sessions
            WHERE last_heartbeat > NOW() - INTERVAL '5 minutes'
        ");
        $activeSessions = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];

        // Get radio listeners from RadioStatusService
        $radioData = $this->radioStatus->getNowPlaying();
        $radioListeners = $radioData['listeners'] ?? 0;

        // Insert snapshot
        $stmt = $this->pdo->prepare("
            INSERT INTO stats_snapshots (concurrent_users, radio_listeners, active_sessions)
            VALUES (:concurrent_users, :radio_listeners, :active_sessions)
        ");
        
        $stmt->execute([
            'concurrent_users' => $concurrentUsers,
            'radio_listeners' => $radioListeners,
            'active_sessions' => $activeSessions
        ]);

        $snapshot = [
            'timestamp' => date('Y-m-d H:i:s'),
            'concurrent_users' => $concurrentUsers,
            'radio_listeners' => $radioListeners,
            'active_sessions' => $activeSessions
        ];

        // Cache latest snapshot for 5 minutes
        $this->redis->setex('stats:latest_snapshot', 300, json_encode($snapshot));

        return $snapshot;
    }

    /**
     * Aggregate hourly statistics for a specific hour.
     * Calls the PostgreSQL function to do the heavy lifting.
     * 
     * @param string|null $hourTimestamp ISO timestamp (defaults to last complete hour)
     * @return bool Success status
     */
    public function aggregateHourlyStats(?string $hourTimestamp = null): bool
    {
        if ($hourTimestamp === null) {
            // Default: last complete hour
            $hourTimestamp = date('Y-m-d H:00:00', strtotime('-1 hour'));
        }

        try {
            $stmt = $this->pdo->prepare("SELECT aggregate_hourly_stats(:hour)");
            $stmt->execute(['hour' => $hourTimestamp]);
            
            // Invalidate cache
            $this->redis->del('stats:hourly:latest');
            
            return true;
        } catch (\Exception $e) {
            error_log("Failed to aggregate hourly stats: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Aggregate daily statistics for a specific date.
     * 
     * @param string|null $date Date in Y-m-d format (defaults to yesterday)
     * @return bool Success status
     */
    public function aggregateDailyStats(?string $date = null): bool
    {
        if ($date === null) {
            $date = date('Y-m-d', strtotime('-1 day'));
        }

        try {
            $stmt = $this->pdo->prepare("SELECT aggregate_daily_stats(:date)");
            $stmt->execute(['date' => $date]);
            
            $this->redis->del('stats:daily:latest');
            
            return true;
        } catch (\Exception $e) {
            error_log("Failed to aggregate daily stats: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Aggregate weekly statistics.
     * 
     * @param string|null $date Any date within the target week (defaults to last week)
     * @return bool Success status
     */
    public function aggregateWeeklyStats(?string $date = null): bool
    {
        if ($date === null) {
            $date = date('Y-m-d', strtotime('-1 week'));
        }

        try {
            $stmt = $this->pdo->prepare("SELECT aggregate_weekly_stats(:date)");
            $stmt->execute(['date' => $date]);
            
            $this->redis->del('stats:weekly:latest');
            
            return true;
        } catch (\Exception $e) {
            error_log("Failed to aggregate weekly stats: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Aggregate monthly statistics.
     * 
     * @param string|null $date Any date within the target month (defaults to last month)
     * @return bool Success status
     */
    public function aggregateMonthlyStats(?string $date = null): bool
    {
        if ($date === null) {
            $date = date('Y-m-d', strtotime('first day of last month'));
        }

        try {
            $stmt = $this->pdo->prepare("SELECT aggregate_monthly_stats(:date)");
            $stmt->execute(['date' => $date]);
            
            $this->redis->del('stats:monthly:latest');
            
            return true;
        } catch (\Exception $e) {
            error_log("Failed to aggregate monthly stats: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Aggregate yearly statistics.
     * 
     * @param int|null $year Target year (defaults to last year)
     * @return bool Success status
     */
    public function aggregateYearlyStats(?int $year = null): bool
    {
        if ($year === null) {
            $year = (int)date('Y') - 1;
        }

        try {
            $stmt = $this->pdo->prepare("SELECT aggregate_yearly_stats(:year)");
            $stmt->execute(['year' => $year]);
            
            $this->redis->del('stats:yearly:latest');
            
            return true;
        } catch (\Exception $e) {
            error_log("Failed to aggregate yearly stats: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get hourly statistics with optional date range.
     * 
     * @param string|null $startDate Start date (Y-m-d H:i:s)
     * @param string|null $endDate End date (Y-m-d H:i:s)
     * @param int $limit Maximum rows to return
     * @return array Array of hourly stat rows
     */
    public function getHourlyStats(?string $startDate = null, ?string $endDate = null, int $limit = 168): array
    {
        $cacheKey = "stats:hourly:{$startDate}:{$endDate}:{$limit}";
        $cached = $this->redis->get($cacheKey);
        if ($cached !== false) {
            return json_decode($cached, true);
        }

        $query = "SELECT * FROM stats_hourly WHERE 1=1";
        $params = [];

        if ($startDate !== null) {
            $query .= " AND stat_hour >= :start_date";
            $params['start_date'] = $startDate;
        }

        if ($endDate !== null) {
            $query .= " AND stat_hour <= :end_date";
            $params['end_date'] = $endDate;
        }

        $query .= " ORDER BY stat_hour DESC LIMIT :limit";
        $params['limit'] = $limit;

        $stmt = $this->pdo->prepare($query);
        
        // Bind limit as integer
        foreach ($params as $key => $value) {
            $type = ($key === 'limit') ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue(":{$key}", $value, $type);
        }
        
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Cache for 10 minutes
        $this->redis->setex($cacheKey, 600, json_encode($results));

        return $results;
    }

    /**
     * Get daily statistics with optional date range.
     * 
     * @param string|null $startDate Start date (Y-m-d)
     * @param string|null $endDate End date (Y-m-d)
     * @param int $limit Maximum rows to return
     * @return array Array of daily stat rows
     */
    public function getDailyStats(?string $startDate = null, ?string $endDate = null, int $limit = 90): array
    {
        $cacheKey = "stats:daily:{$startDate}:{$endDate}:{$limit}";
        $cached = $this->redis->get($cacheKey);
        if ($cached !== false) {
            return json_decode($cached, true);
        }

        $query = "SELECT * FROM stats_daily WHERE 1=1";
        $params = [];

        if ($startDate !== null) {
            $query .= " AND stat_date >= :start_date";
            $params['start_date'] = $startDate;
        }

        if ($endDate !== null) {
            $query .= " AND stat_date <= :end_date";
            $params['end_date'] = $endDate;
        }

        $query .= " ORDER BY stat_date DESC LIMIT :limit";
        $params['limit'] = $limit;

        $stmt = $this->pdo->prepare($query);
        
        foreach ($params as $key => $value) {
            $type = ($key === 'limit') ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue(":{$key}", $value, $type);
        }
        
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Cache for 1 hour
        $this->redis->setex($cacheKey, 3600, json_encode($results));

        return $results;
    }

    /**
     * Get weekly statistics.
     * 
     * @param int|null $year Filter by year
     * @param int $limit Maximum rows to return
     * @return array Array of weekly stat rows
     */
    public function getWeeklyStats(?int $year = null, int $limit = 52): array
    {
        $cacheKey = "stats:weekly:{$year}:{$limit}";
        $cached = $this->redis->get($cacheKey);
        if ($cached !== false) {
            return json_decode($cached, true);
        }

        $query = "SELECT * FROM stats_weekly WHERE 1=1";
        $params = [];

        if ($year !== null) {
            $query .= " AND stat_year = :year";
            $params['year'] = $year;
        }

        $query .= " ORDER BY stat_year DESC, stat_week DESC LIMIT :limit";
        $params['limit'] = $limit;

        $stmt = $this->pdo->prepare($query);
        
        foreach ($params as $key => $value) {
            $type = ($key === 'limit' || $key === 'year') ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue(":{$key}", $value, $type);
        }
        
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Cache for 1 hour
        $this->redis->setex($cacheKey, 3600, json_encode($results));

        return $results;
    }

    /**
     * Get monthly statistics.
     * 
     * @param int|null $year Filter by year
     * @param int $limit Maximum rows to return
     * @return array Array of monthly stat rows
     */
    public function getMonthlyStats(?int $year = null, int $limit = 24): array
    {
        $cacheKey = "stats:monthly:{$year}:{$limit}";
        $cached = $this->redis->get($cacheKey);
        if ($cached !== false) {
            return json_decode($cached, true);
        }

        $query = "SELECT * FROM stats_monthly WHERE 1=1";
        $params = [];

        if ($year !== null) {
            $query .= " AND stat_year = :year";
            $params['year'] = $year;
        }

        $query .= " ORDER BY stat_year DESC, stat_month DESC LIMIT :limit";
        $params['limit'] = $limit;

        $stmt = $this->pdo->prepare($query);
        
        foreach ($params as $key => $value) {
            $type = ($key === 'limit' || $key === 'year') ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue(":{$key}", $value, $type);
        }
        
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Cache for 1 hour
        $this->redis->setex($cacheKey, 3600, json_encode($results));

        return $results;
    }

    /**
     * Get yearly statistics.
     * 
     * @param int $limit Maximum rows to return
     * @return array Array of yearly stat rows
     */
    public function getYearlyStats(int $limit = 10): array
    {
        $cacheKey = "stats:yearly:{$limit}";
        $cached = $this->redis->get($cacheKey);
        if ($cached !== false) {
            return json_decode($cached, true);
        }

        $stmt = $this->pdo->prepare("
            SELECT * FROM stats_yearly
            ORDER BY stat_year DESC
            LIMIT :limit
        ");
        
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Cache for 1 hour
        $this->redis->setex($cacheKey, 3600, json_encode($results));

        return $results;
    }

    /**
     * Get summary statistics (latest data from each granularity).
     * Useful for admin dashboard overview.
     * 
     * @return array Summary with today, this week, this month, this year stats
     */
    public function getSummary(): array
    {
        $this->ensureTablesExist();
        
        $cacheKey = 'stats:summary';
        $cached = $this->redis->get($cacheKey);
        if ($cached !== false) {
            return json_decode($cached, true);
        }

        $today = date('Y-m-d');
        $thisYear = (int)date('Y');
        $thisMonth = (int)date('m');

        // Today's stats (if available)
        $stmt = $this->pdo->prepare("SELECT * FROM stats_daily WHERE stat_date = :today");
        $stmt->execute(['today' => $today]);
        $todayStats = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        // This month's stats
        $stmt = $this->pdo->prepare("
            SELECT * FROM stats_monthly 
            WHERE stat_year = :year AND stat_month = :month
        ");
        $stmt->execute(['year' => $thisYear, 'month' => $thisMonth]);
        $monthStats = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        // This year's stats
        $stmt = $this->pdo->prepare("SELECT * FROM stats_yearly WHERE stat_year = :year");
        $stmt->execute(['year' => $thisYear]);
        $yearStats = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        // Latest snapshot
        $stmt = $this->pdo->query("
            SELECT * FROM stats_snapshots 
            ORDER BY snapshot_time DESC 
            LIMIT 1
        ");
        $latestSnapshot = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $summary = [
            'today' => $todayStats,
            'this_month' => $monthStats,
            'this_year' => $yearStats,
            'latest_snapshot' => $latestSnapshot,
            'generated_at' => date('Y-m-d H:i:s')
        ];

        // Cache for 5 minutes
        $this->redis->setex($cacheKey, 300, json_encode($summary));

        return $summary;
    }

    /**
     * Cleanup old snapshots (keep only last 30 days).
     * Should be run daily via cron.
     * 
     * @return int Number of rows deleted
     */
    public function cleanupOldSnapshots(): int
    {
        $stmt = $this->pdo->query("SELECT cleanup_old_snapshots()");
        
        // Count deleted rows
        $stmt = $this->pdo->query("
            SELECT COUNT(*) as deleted 
            FROM stats_snapshots 
            WHERE snapshot_time < NOW() - INTERVAL '30 days'
        ");
        
        return (int)$stmt->fetch(PDO::FETCH_ASSOC)['deleted'];
    }

    /**
     * Run all aggregation tasks for maintenance.
     * Aggregates: last hour, yesterday, last week, last month, last year.
     * Can be called by cron or triggered on-demand (e.g., when admin views stats without cron).
     * 
     * @return array Results of each aggregation
     */
    public function runMaintenanceAggregations(): array
    {
        $results = [
            'hourly' => $this->aggregateHourlyStats(),
            'daily' => $this->aggregateDailyStats(),
            'weekly' => $this->aggregateWeeklyStats(),
            'monthly' => $this->aggregateMonthlyStats(),
            'yearly' => $this->aggregateYearlyStats(),
            'cleanup' => $this->cleanupOldSnapshots()
        ];

        return $results;
    }

    /**
     * Trigger aggregation if needed (fallback when cron is unavailable).
     * Uses Redis to track the last aggregation time and only runs if:
     * - Hourly: hasn't run in the last 70 minutes
     * - Daily: hasn't run in the last 25 hours
     * 
     * This is called on-demand and prevents duplicate work.
     * 
     * @return array Results of aggregations that were triggered
     */
    public function triggerAggregationIfNeeded(): array
    {
        $results = [];
        $now = time();
        
        // Check if hourly aggregation is needed (run every 70 minutes max)
        $lastHourlyKey = 'stats:last_hourly_aggregation';
        $lastHourly = $this->redis->get($lastHourlyKey);
        
        if ($lastHourly === false || ($now - (int)$lastHourly) > 4200) { // 70 minutes
            $results['hourly'] = $this->aggregateHourlyStats();
            $this->redis->setex($lastHourlyKey, 86400, $now); // Remember for 24 hours
        }
        
        // Check if daily aggregation is needed (run every 25 hours max)
        $lastDailyKey = 'stats:last_daily_aggregation';
        $lastDaily = $this->redis->get($lastDailyKey);
        
        if ($lastDaily === false || ($now - (int)$lastDaily) > 90000) { // 25 hours
            $results['daily'] = $this->aggregateDailyStats();
            $this->redis->setex($lastDailyKey, 604800, $now); // Remember for 7 days
        }
        
        return $results;
    }
}
