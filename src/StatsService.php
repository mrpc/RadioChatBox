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
     * Includes current week stats (computed from daily data) if applicable.
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

        // Include current week if not already in results
        $currentWeekData = $this->computeCurrentWeekStats();
        if ($currentWeekData) {
            // Check if current week is already in results
            $currentWeekExists = false;
            foreach ($results as $row) {
                if ($row['stat_year'] == $currentWeekData['stat_year'] && 
                    isset($row['stat_week']) && $row['stat_week'] == (int)date('W')) {
                    $currentWeekExists = true;
                    break;
                }
            }
            
            // Add current week to the beginning if not present and filter matches
            if (!$currentWeekExists && ($year === null || $year == date('Y'))) {
                array_unshift($results, [
                    'stat_year' => $currentWeekData['stat_year'],
                    'stat_week' => (int)date('W'),
                    'week_start_date' => $currentWeekData['week_start'],
                    'active_users' => $currentWeekData['active_users'],
                    'guest_users' => $currentWeekData['guest_users'],
                    'registered_users' => $currentWeekData['registered_users'],
                    'total_messages' => $currentWeekData['total_messages'],
                    'private_messages' => $currentWeekData['private_messages'],
                    'photo_uploads' => $currentWeekData['photo_uploads'],
                    'new_registrations' => $currentWeekData['new_registrations'],
                    'radio_listeners_avg' => $currentWeekData['radio_listeners_avg'],
                    'radio_listeners_peak' => $currentWeekData['radio_listeners_peak'],
                    'peak_concurrent_users' => $currentWeekData['peak_concurrent_users']
                ]);
            }
        }

        // Cache for 1 hour
        $this->redis->setex($cacheKey, 3600, json_encode($results));

        return $results;
    }

    /**
     * Get monthly statistics.
     * Includes current month stats (computed from daily data) if applicable.
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

        // Include current month if not already in results
        $currentMonthData = $this->computeCurrentMonthStats();
        if ($currentMonthData) {
            // Check if current month is already in results
            $currentMonthExists = false;
            foreach ($results as $row) {
                if ($row['stat_year'] == $currentMonthData['stat_year'] && 
                    $row['stat_month'] == $currentMonthData['stat_month']) {
                    $currentMonthExists = true;
                    break;
                }
            }
            
            // Add current month to the beginning if not present and filter matches
            if (!$currentMonthExists && ($year === null || $year == date('Y'))) {
                array_unshift($results, [
                    'stat_year' => $currentMonthData['stat_year'],
                    'stat_month' => $currentMonthData['stat_month'],
                    'active_users' => $currentMonthData['active_users'],
                    'guest_users' => $currentMonthData['guest_users'],
                    'registered_users' => $currentMonthData['registered_users'],
                    'total_messages' => $currentMonthData['total_messages'],
                    'private_messages' => $currentMonthData['private_messages'],
                    'photo_uploads' => $currentMonthData['photo_uploads'],
                    'new_registrations' => $currentMonthData['new_registrations'],
                    'radio_listeners_avg' => $currentMonthData['radio_listeners_avg'],
                    'radio_listeners_peak' => $currentMonthData['radio_listeners_peak'],
                    'peak_concurrent_users' => $currentMonthData['peak_concurrent_users']
                ]);
            }
        }

        // Cache for 1 hour
        $this->redis->setex($cacheKey, 3600, json_encode($results));

        return $results;
    }

    /**
     * Get yearly statistics.
     * Includes current year stats (computed from daily data) if applicable.
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

        // Include current year if not already in results
        $currentYearData = $this->computeCurrentYearStats();
        if ($currentYearData) {
            // Check if current year is already in results
            $currentYearExists = false;
            foreach ($results as $row) {
                if ($row['stat_year'] == $currentYearData['stat_year']) {
                    $currentYearExists = true;
                    break;
                }
            }
            
            // Add current year to the beginning if not present
            if (!$currentYearExists) {
                array_unshift($results, [
                    'stat_year' => $currentYearData['stat_year'],
                    'active_users' => $currentYearData['active_users'],
                    'guest_users' => $currentYearData['guest_users'],
                    'registered_users' => $currentYearData['registered_users'],
                    'total_messages' => $currentYearData['total_messages'],
                    'private_messages' => $currentYearData['private_messages'],
                    'photo_uploads' => $currentYearData['photo_uploads'],
                    'new_registrations' => $currentYearData['new_registrations'],
                    'radio_listeners_avg' => $currentYearData['radio_listeners_avg'],
                    'radio_listeners_peak' => $currentYearData['radio_listeners_peak'],
                    'peak_concurrent_users' => $currentYearData['peak_concurrent_users']
                ]);
            }
        }

        // Cache for 1 hour
        $this->redis->setex($cacheKey, 3600, json_encode($results));

        return $results;
    }

    /**
     * Get summary statistics (latest data from each granularity).
     * Useful for admin dashboard overview.
     * 
     * Computes real-time stats for current periods by aggregating raw data
     * (today, this week, this month, this year) if pre-aggregated data doesn't exist yet.
     * Falls back to pre-aggregated data for past periods.
     * 
     * @return array Summary with today, this week, this month, this year stats
     */
    public function getSummary(): array
    {
        $this->ensureTablesExist();
        
        $cacheKey = 'stats:summary';
        $cached = $this->redis->get($cacheKey);
        
        // Return cached value if available - cache is only valid for 5 minutes anyway
        // Detailed validation happens server-side in getSummary if needed
        if ($cached !== false) {
            return json_decode($cached, true);
        }

        $today = date('Y-m-d');
        $thisYear = (int)date('Y');
        $thisMonth = (int)date('m');
        $thisWeek = (int)date('W');

        // Today's stats - compute from raw data if not yet aggregated
        $todayStats = $this->computeTodayStats();

        // This week's stats - compute from daily data including today
        $weekStats = $this->computeCurrentWeekStats();

        // This month's stats - compute from daily data including today
        $monthStats = $this->computeCurrentMonthStats();

        // This year's stats - compute from daily data including today
        $yearStats = $this->computeCurrentYearStats();

        // Latest snapshot
        $latestSnapshot = $this->getLatestSnapshot();

        // If users just arrived and haven't been aggregated to hourly stats yet,
        // use real-time concurrent users from latest snapshot if higher
        if ($todayStats && $latestSnapshot) {
            $todayStats['active_users'] = max(
                $todayStats['active_users'] ?? 0,
                $latestSnapshot['concurrent_users'] ?? 0
            );
        }

        // Get real-time message counts from messages table for today
        // This ensures new messages show up immediately without waiting for hourly cron
        if ($todayStats) {
            $today = date('Y-m-d');
            $todayStart = $today . ' 00:00:00';
            $todayEnd = $today . ' 23:59:59';
            
            // Count total public messages today
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as count 
                FROM messages 
                WHERE created_at >= :today_start 
                AND created_at <= :today_end 
                AND is_deleted = FALSE
            ");
            $stmt->execute(['today_start' => $todayStart, 'today_end' => $todayEnd]);
            $realTimeMessages = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Use real-time count if higher than aggregated stats
            $todayStats['total_messages'] = max(
                $todayStats['total_messages'] ?? 0,
                (int)($realTimeMessages['count'] ?? 0)
            );
            
            // Count registered and guest users active today from sessions
            $stmt = $this->pdo->prepare("
                SELECT 
                    COUNT(DISTINCT CASE WHEN user_id IS NOT NULL THEN username END) as registered_users,
                    COUNT(DISTINCT CASE WHEN user_id IS NULL THEN username END) as guest_users
                FROM sessions 
                WHERE last_heartbeat >= :today_start 
                AND last_heartbeat <= :today_end
            ");
            $stmt->execute(['today_start' => $todayStart, 'today_end' => $todayEnd]);
            $realTimeUsers = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Use real-time counts if higher than aggregated stats
            $todayStats['registered_users'] = max(
                $todayStats['registered_users'] ?? 0,
                (int)($realTimeUsers['registered_users'] ?? 0)
            );
            $todayStats['guest_users'] = max(
                $todayStats['guest_users'] ?? 0,
                (int)($realTimeUsers['guest_users'] ?? 0)
            );
        }

        $summary = [
            'today' => $todayStats,
            'this_week' => $weekStats,
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
     * Compute today's statistics from raw data (messages, snapshots).
     * Provides real-time stats for the current day.
     * 
     * @return array|null Today's stats or null if no data available
     */
    private function computeTodayStats(): ?array
    {
        $today = date('Y-m-d');
        $todayStart = $today . ' 00:00:00';
        $todayEnd = $today . ' 23:59:59';

        // Always compute from hourly stats for current day (don't trust pre-aggregated data)
        // since today is still in progress and pre-aggregated data may be stale
        $stmt = $this->pdo->prepare("
            SELECT
                MAX(active_users) as active_users,
                MAX(guest_users) as guest_users,
                MAX(registered_users) as registered_users,
                SUM(total_messages)::INTEGER as total_messages,
                SUM(private_messages)::INTEGER as private_messages,
                SUM(photo_uploads)::INTEGER as photo_uploads,
                SUM(new_registrations)::INTEGER as new_registrations,
                AVG(radio_listeners_avg)::INTEGER as radio_listeners_avg,
                MAX(radio_listeners_peak)::INTEGER as radio_listeners_peak,
                MAX(peak_concurrent_users)::INTEGER as peak_concurrent_users
            FROM stats_hourly
            WHERE stat_hour >= :today_start AND stat_hour <= :today_end
        ");
        $stmt->execute(['today_start' => $todayStart, 'today_end' => $todayEnd]);
        $hourlyData = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($hourlyData && ($hourlyData['active_users'] !== null || $hourlyData['total_messages'] !== null)) {
            return [
                'stat_date' => $today,
                'active_users' => $hourlyData['active_users'] ?? 0,
                'guest_users' => $hourlyData['guest_users'] ?? 0,
                'registered_users' => $hourlyData['registered_users'] ?? 0,
                'total_messages' => $hourlyData['total_messages'] ?? 0,
                'private_messages' => $hourlyData['private_messages'] ?? 0,
                'photo_uploads' => $hourlyData['photo_uploads'] ?? 0,
                'new_registrations' => $hourlyData['new_registrations'] ?? 0,
                'radio_listeners_avg' => $hourlyData['radio_listeners_avg'] ?? 0,
                'radio_listeners_peak' => $hourlyData['radio_listeners_peak'] ?? 0,
                'peak_concurrent_users' => $hourlyData['peak_concurrent_users'] ?? 0
            ];
        }

        return null;
    }

    /**
     * Compute this week's statistics.
     * Includes all data from Monday to today.
     * 
     * @return array|null This week's stats or null if no data available
     */
    private function computeCurrentWeekStats(): ?array
    {
        $today = date('Y-m-d');
        $weekStart = date('Y-m-d', strtotime('monday this week'));
        $weekStartTime = $weekStart . ' 00:00:00';
        $todayEndTime = $today . ' 23:59:59';
        
        // Compute from hourly stats for accuracy (don't rely on potentially stale daily aggregates)
        $stmt = $this->pdo->prepare("
            SELECT
                MAX(active_users) as active_users,
                MAX(guest_users) as guest_users,
                MAX(registered_users) as registered_users,
                SUM(total_messages)::INTEGER as total_messages,
                SUM(private_messages)::INTEGER as private_messages,
                SUM(photo_uploads)::INTEGER as photo_uploads,
                SUM(new_registrations)::INTEGER as new_registrations,
                AVG(radio_listeners_avg)::INTEGER as radio_listeners_avg,
                MAX(radio_listeners_peak)::INTEGER as radio_listeners_peak,
                MAX(peak_concurrent_users)::INTEGER as peak_concurrent_users
            FROM stats_hourly
            WHERE stat_hour >= :week_start AND stat_hour <= :today_end
        ");
        $stmt->execute(['week_start' => $weekStartTime, 'today_end' => $todayEndTime]);
        $weekData = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($weekData && ($weekData['active_users'] !== null || $weekData['total_messages'] !== null)) {
            return [
                'stat_year' => (int)date('Y'),
                'stat_week' => (int)date('W'),
                'week_start' => $weekStart,
                'active_users' => $weekData['active_users'] ?? 0,
                'guest_users' => $weekData['guest_users'] ?? 0,
                'registered_users' => $weekData['registered_users'] ?? 0,
                'total_messages' => $weekData['total_messages'] ?? 0,
                'private_messages' => $weekData['private_messages'] ?? 0,
                'photo_uploads' => $weekData['photo_uploads'] ?? 0,
                'new_registrations' => $weekData['new_registrations'] ?? 0,
                'radio_listeners_avg' => $weekData['radio_listeners_avg'] ?? 0,
                'radio_listeners_peak' => $weekData['radio_listeners_peak'] ?? 0,
                'peak_concurrent_users' => $weekData['peak_concurrent_users'] ?? 0
            ];
        }

        return null;
    }

    /**
     * Compute this month's statistics.
     * Includes all data from 1st of month to today.
     * 
     * @return array|null This month's stats or null if no data available
     */
    private function computeCurrentMonthStats(): ?array
    {
        $today = date('Y-m-d');
        $monthStart = date('Y-m-01');
        $monthStartTime = $monthStart . ' 00:00:00';
        $todayEndTime = $today . ' 23:59:59';
        
        // Compute from hourly stats for accuracy (don't rely on potentially stale daily aggregates)
        $stmt = $this->pdo->prepare("
            SELECT
                MAX(active_users) as active_users,
                MAX(guest_users) as guest_users,
                MAX(registered_users) as registered_users,
                SUM(total_messages)::INTEGER as total_messages,
                SUM(private_messages)::INTEGER as private_messages,
                SUM(photo_uploads)::INTEGER as photo_uploads,
                SUM(new_registrations)::INTEGER as new_registrations,
                AVG(radio_listeners_avg)::INTEGER as radio_listeners_avg,
                MAX(radio_listeners_peak)::INTEGER as radio_listeners_peak,
                MAX(peak_concurrent_users)::INTEGER as peak_concurrent_users
            FROM stats_hourly
            WHERE stat_hour >= :month_start AND stat_hour <= :today_end
        ");
        $stmt->execute(['month_start' => $monthStartTime, 'today_end' => $todayEndTime]);
        $monthData = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($monthData && ($monthData['active_users'] !== null || $monthData['total_messages'] !== null)) {
            $thisYear = (int)date('Y');
            $thisMonth = (int)date('m');
            return [
                'stat_year' => $thisYear,
                'stat_month' => $thisMonth,
                'active_users' => $monthData['active_users'] ?? 0,
                'guest_users' => $monthData['guest_users'] ?? 0,
                'registered_users' => $monthData['registered_users'] ?? 0,
                'total_messages' => $monthData['total_messages'] ?? 0,
                'private_messages' => $monthData['private_messages'] ?? 0,
                'photo_uploads' => $monthData['photo_uploads'] ?? 0,
                'new_registrations' => $monthData['new_registrations'] ?? 0,
                'radio_listeners_avg' => $monthData['radio_listeners_avg'] ?? 0,
                'radio_listeners_peak' => $monthData['radio_listeners_peak'] ?? 0,
                'peak_concurrent_users' => $monthData['peak_concurrent_users'] ?? 0
            ];
        }

        return null;
    }

    /**
     * Compute this year's statistics.
     * Includes all data from January 1st to today.
     * 
     * @return array|null This year's stats or null if no data available
     */
    private function computeCurrentYearStats(): ?array
    {
        $today = date('Y-m-d');
        $yearStart = date('Y-01-01');
        $yearStartTime = $yearStart . ' 00:00:00';
        $todayEndTime = $today . ' 23:59:59';
        
        // Compute from hourly stats for accuracy (don't rely on potentially stale daily aggregates)
        $stmt = $this->pdo->prepare("
            SELECT
                MAX(active_users) as active_users,
                MAX(guest_users) as guest_users,
                MAX(registered_users) as registered_users,
                SUM(total_messages)::INTEGER as total_messages,
                SUM(private_messages)::INTEGER as private_messages,
                SUM(photo_uploads)::INTEGER as photo_uploads,
                SUM(new_registrations)::INTEGER as new_registrations,
                AVG(radio_listeners_avg)::INTEGER as radio_listeners_avg,
                MAX(radio_listeners_peak)::INTEGER as radio_listeners_peak,
                MAX(peak_concurrent_users)::INTEGER as peak_concurrent_users
            FROM stats_hourly
            WHERE stat_hour >= :year_start AND stat_hour <= :today_end
        ");
        $stmt->execute(['year_start' => $yearStartTime, 'today_end' => $todayEndTime]);
        $yearData = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($yearData && ($yearData['active_users'] !== null || $yearData['total_messages'] !== null)) {
            $thisYear = (int)date('Y');
            return [
                'stat_year' => $thisYear,
                'active_users' => $yearData['active_users'] ?? 0,
                'guest_users' => $yearData['guest_users'] ?? 0,
                'registered_users' => $yearData['registered_users'] ?? 0,
                'total_messages' => $yearData['total_messages'] ?? 0,
                'private_messages' => $yearData['private_messages'] ?? 0,
                'photo_uploads' => $yearData['photo_uploads'] ?? 0,
                'new_registrations' => $yearData['new_registrations'] ?? 0,
                'radio_listeners_avg' => $yearData['radio_listeners_avg'] ?? 0,
                'radio_listeners_peak' => $yearData['radio_listeners_peak'] ?? 0,
                'peak_concurrent_users' => $yearData['peak_concurrent_users'] ?? 0
            ];
        }

        return null;
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

    /**
     * Get the latest snapshot data
     * 
     * @return array|null Latest snapshot or null if none exists
     */
    private function getLatestSnapshot(): ?array
    {
        $stmt = $this->pdo->query("
            SELECT * FROM stats_snapshots 
            ORDER BY snapshot_time DESC 
            LIMIT 1
        ");
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
