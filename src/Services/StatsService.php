<?php

namespace RadioChatBox\Services;

use Pramnos\Cache\FlatCache;
use RuntimeException;
use Pramnos\Database\Database as PramnosDatabase;

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
    private PramnosDatabase $db;
    private RadioStatusService $radioStatus;
    private bool $tablesChecked = false;

    public function __construct()
    {
        $this->db = PramnosDatabase::getInstance();
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
            $exists = $this->db->query(
                "SELECT EXISTS (
                    SELECT FROM information_schema.tables
                    WHERE table_schema = 'public'
                    AND table_name = 'stats_snapshots'
                )"
            )->fetchColumn();

            if (!$exists) {
                \Pramnos\Logs\Logger::log('Statistics tables not found, creating automatically...', 'radiochatbox');
                $this->createStatsTables();
            }

            $this->tablesChecked = true;
        } catch (\Exception $e) {
            \Pramnos\Logs\Logger::log('Failed to check/create stats tables: ' . $e->getMessage(), 'radiochatbox');
            throw new RuntimeException('Statistics tables not available');
        }
    }

    /**
     * Create statistics tables from migration SQL
     */
    private function createStatsTables(): void
    {
        // The database schema — statistics tables included — is created by the
        // CreateSchema migration (run via `radiochatbox migrate`). This lazy
        // fallback no longer ships raw SQL; missing tables mean migrations have
        // not been applied.
        throw new RuntimeException(
            'Statistics tables are missing — run database migrations (radiochatbox migrate).'
        );
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
            $lastSnapshot = FlatCache::default()->get($lastSnapshotKey);

            if ($lastSnapshot !== null) {
                $now = time();

                // If less than 5 minutes have passed, skip recording
                if (($now - (int) $lastSnapshot) < 300) {
                    // Return cached snapshot instead of recording
                    $cached = FlatCache::default()->get('stats:latest_snapshot');
                    if ($cached !== null) {
                        return $cached;
                    }
                    return [];
                }
            }

            // Update last snapshot time
            FlatCache::default()->set($lastSnapshotKey, time(), 3600);
        }
        $this->ensureTablesExist();
        
        // Count concurrent users (unique usernames in sessions)
        $concurrentUsers = (int) $this->db->queryBuilder()
            ->from('presence_sessions')
            ->select(['COUNT(DISTINCT username) AS count'])
            ->whereRaw("last_heartbeat > NOW() - INTERVAL '5 minutes'")
            ->first()->fields['count'];

        // Count total active sessions (including multiple tabs)
        $activeSessions = $this->db->queryBuilder()
            ->from('presence_sessions')
            ->whereRaw("last_heartbeat > NOW() - INTERVAL '5 minutes'")
            ->count();

        // Get radio listeners from RadioStatusService
        $radioData = $this->radioStatus->getNowPlaying();
        $radioListeners = $radioData['listeners'] ?? 0;

        // Insert snapshot
        $this->db->queryBuilder()->from('stats_snapshots')->insert([
            'concurrent_users' => $concurrentUsers,
            'radio_listeners'  => $radioListeners,
            'active_sessions'  => $activeSessions,
        ]);

        $snapshot = [
            'timestamp' => date('Y-m-d H:i:s'),
            'concurrent_users' => $concurrentUsers,
            'radio_listeners' => $radioListeners,
            'active_sessions' => $activeSessions
        ];

        // Cache latest snapshot for 5 minutes
        FlatCache::default()->set('stats:latest_snapshot', $snapshot, 300);

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
            $this->db->preparedQuery("SELECT aggregate_hourly_stats(:hour)", ['hour' => $hourTimestamp]);
            
            // Invalidate cache
            FlatCache::default()->delete('stats:hourly:latest');
            
            return true;
        // @codeCoverageIgnoreStart
        } catch (\Exception $e) {
            \Pramnos\Logs\Logger::log("Failed to aggregate hourly stats: " . $e->getMessage(), 'radiochatbox');
            return false;
        }
        // @codeCoverageIgnoreEnd
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
            $this->db->preparedQuery("SELECT aggregate_daily_stats(:date)", ['date' => $date]);
            
            FlatCache::default()->delete('stats:daily:latest');
            
            return true;
        // @codeCoverageIgnoreStart
        } catch (\Exception $e) {
            \Pramnos\Logs\Logger::log("Failed to aggregate daily stats: " . $e->getMessage(), 'radiochatbox');
            return false;
        }
        // @codeCoverageIgnoreEnd
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
            $this->db->preparedQuery("SELECT aggregate_weekly_stats(:date)", ['date' => $date]);
            
            FlatCache::default()->delete('stats:weekly:latest');
            
            return true;
        // @codeCoverageIgnoreStart
        } catch (\Exception $e) {
            \Pramnos\Logs\Logger::log("Failed to aggregate weekly stats: " . $e->getMessage(), 'radiochatbox');
            return false;
        }
        // @codeCoverageIgnoreEnd
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
            $this->db->preparedQuery("SELECT aggregate_monthly_stats(:date)", ['date' => $date]);
            
            FlatCache::default()->delete('stats:monthly:latest');
            
            return true;
        // @codeCoverageIgnoreStart
        } catch (\Exception $e) {
            \Pramnos\Logs\Logger::log("Failed to aggregate monthly stats: " . $e->getMessage(), 'radiochatbox');
            return false;
        }
        // @codeCoverageIgnoreEnd
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
            $this->db->preparedQuery("SELECT aggregate_yearly_stats(:year)", ['year' => $year]);
            
            FlatCache::default()->delete('stats:yearly:latest');
            
            return true;
        // @codeCoverageIgnoreStart
        } catch (\Exception $e) {
            \Pramnos\Logs\Logger::log("Failed to aggregate yearly stats: " . $e->getMessage(), 'radiochatbox');
            return false;
        }
        // @codeCoverageIgnoreEnd
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
        $cached = FlatCache::default()->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $qb = $this->db->queryBuilder()->from('stats_hourly');
        if ($startDate !== null) {
            $qb->where('stat_hour', '>=', $startDate);
        }
        if ($endDate !== null) {
            $qb->where('stat_hour', '<=', $endDate);
        }
        $results = $qb->orderBy('stat_hour', 'desc')->limit($limit)->getAll();

        // Cache for 10 minutes
        FlatCache::default()->set($cacheKey, $results, 600);

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
        $cached = FlatCache::default()->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $qb = $this->db->queryBuilder()->from('stats_daily');
        if ($startDate !== null) {
            $qb->where('stat_date', '>=', $startDate);
        }
        if ($endDate !== null) {
            $qb->where('stat_date', '<=', $endDate);
        }
        $results = $qb->orderBy('stat_date', 'desc')->limit($limit)->getAll();

        // Cache for 1 hour
        FlatCache::default()->set($cacheKey, $results, 3600);

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
        $cached = FlatCache::default()->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $qb = $this->db->queryBuilder()->from('stats_weekly');
        if ($year !== null) {
            $qb->where('stat_year', '=', $year);
        }
        $results = $qb->orderBy('stat_year', 'desc')->orderBy('stat_week', 'desc')->limit($limit)->getAll();

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

        // The computed current period was added on top of a full page of stored rows,
        // so the caller's limit has to be re-applied - asking for one week and getting
        // two is a broken contract, and the extra row lands in the UI.
        $results = array_slice($results, 0, max(1, $limit));

        // Cache for 1 hour
        FlatCache::default()->set($cacheKey, $results, 3600);

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
        $cached = FlatCache::default()->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $qb = $this->db->queryBuilder()->from('stats_monthly');
        if ($year !== null) {
            $qb->where('stat_year', '=', $year);
        }
        $results = $qb->orderBy('stat_year', 'desc')->orderBy('stat_month', 'desc')->limit($limit)->getAll();

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

        // Same as the weekly list: re-apply the caller's limit after the merge.
        $results = array_slice($results, 0, max(1, $limit));

        // Cache for 1 hour
        FlatCache::default()->set($cacheKey, $results, 3600);

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
        $cached = FlatCache::default()->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $results = $this->db->queryBuilder()
            ->from('stats_yearly')
            ->orderBy('stat_year', 'desc')
            ->limit($limit)
            ->getAll();

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

        // Same as the weekly and monthly lists: re-apply the caller's limit.
        $results = array_slice($results, 0, max(1, $limit));

        // Cache for 1 hour
        FlatCache::default()->set($cacheKey, $results, 3600);

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
    /**
     * Active-user retention metrics: distinct usernames that posted a public
     * message in the last day (DAU), 7 days (WAU) and 30 days (MAU), plus a
     * simple stickiness ratio (DAU/MAU as a percentage). Computed from
     * chat_messages so it reflects real participation.
     *
     * @return array{dau:int, wau:int, mau:int, stickiness:int}
     */
    public function activeUserCounts(): array
    {
        $distinct = static function (PramnosDatabase $db, string $interval): int {
            try {
                $result = $db->query(
                    "SELECT COUNT(DISTINCT LOWER(username)) AS c
                     FROM chat_messages
                     WHERE is_deleted = FALSE AND created_at >= NOW() - INTERVAL '{$interval}'"
                );
                return $result ? (int) $result->fetchColumn() : 0;
            // @codeCoverageIgnoreStart
            } catch (\Throwable $e) {
                \Pramnos\Logs\Logger::log('StatsService::activeUserCounts failed: ' . $e->getMessage(), 'radiochatbox');
                return 0;
            }
            // @codeCoverageIgnoreEnd
        };

        $dau = $distinct($this->db, '1 day');
        $wau = $distinct($this->db, '7 days');
        $mau = $distinct($this->db, '30 days');
        $stickiness = $mau > 0 ? (int) round($dau / $mau * 100) : 0;

        return ['dau' => $dau, 'wau' => $wau, 'mau' => $mau, 'stickiness' => $stickiness];
    }

    public function getSummary(): array
    {
        $this->ensureTablesExist();
        
        $cacheKey = 'stats:summary';
        $cached = FlatCache::default()->get($cacheKey);
        
        // Use cache if available - real-time checks happen at cache creation time
        // Cache is short-lived (30s) to ensure frequent updates
        if ($cached !== null) {
            return $cached;
        }

        $today = date('Y-m-d');
        $thisYear = (int)date('Y');
        $thisMonth = (int)date('m');
        $thisWeek = (int)date('W');

        // Today's stats - compute from raw data if not yet aggregated
        $todayStats = $this->computeTodayStats();

        // If no hourly data exists yet, initialize with defaults for real-time queries
        if (!$todayStats) {
            $todayStats = [
                'stat_date' => date('Y-m-d'),
                'active_users' => 0,
                'guest_users' => 0,
                'registered_users' => 0,
                'total_messages' => 0,
                'private_messages' => 0,
                'photo_uploads' => 0,
                'new_registrations' => 0,
                'radio_listeners_avg' => 0,
                'radio_listeners_peak' => 0,
                'peak_concurrent_users' => 0
            ];
        }

        // The week/month/year rollups are secondary to the dashboard's headline
        // "today" number. If one of them throws (a broken aggregate function, a
        // schema mismatch after a migration), it must not blank the whole summary
        // and drag "messages today" to 0 — degrade that section to null instead so
        // the real-time today override below still runs.
        $safe = function (callable $fn) {
            try {
                return $fn();
            // @codeCoverageIgnoreStart
            } catch (\Throwable $e) {
                \Pramnos\Logs\Logger::log('StatsService::getSummary partial failure: ' . $e->getMessage(), 'radiochatbox');
                return null;
            }
            // @codeCoverageIgnoreEnd
        };

        // This week's stats - compute from daily data including today
        $weekStats = $safe(fn () => $this->computeCurrentWeekStats());

        // This month's stats - compute from daily data including today
        $monthStats = $safe(fn () => $this->computeCurrentMonthStats());

        // This year's stats - compute from daily data including today
        $yearStats = $safe(fn () => $this->computeCurrentYearStats());

        // Latest snapshot
        $latestSnapshot = $safe(fn () => $this->getLatestSnapshot());

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
        $today = date('Y-m-d');
        $todayStart = $today . ' 00:00:00';
        $todayEnd = $today . ' 23:59:59';
        
        try {
            // Count total public messages today
            $realTimeMessages = $this->db->queryBuilder()
                ->from('chat_messages')
                ->select(['COUNT(*) AS count'])
                ->where('created_at', '>=', $todayStart)
                ->where('created_at', '<=', $todayEnd)
                ->whereRaw('is_deleted = FALSE')
                ->first()->fields;
            
            if ($realTimeMessages && isset($realTimeMessages['count'])) {
                // Use real-time count if higher than aggregated stats (handles new messages before cron)
                $todayStats['total_messages'] = max(
                    $todayStats['total_messages'] ?? 0,
                    (int)($realTimeMessages['count'] ?? 0)
                );
            }

            // Same real-time treatment for DMs: on a private-mode install these ARE
            // the day's activity, so the dashboard must not wait for the hourly cron
            // (it showed a stale/0 count while the chat was busy).
            $realTimePrivate = $this->db->queryBuilder()
                ->from('private_messages')
                ->select(['COUNT(*) AS count'])
                ->where('created_at', '>=', $todayStart)
                ->where('created_at', '<=', $todayEnd)
                ->first()->fields;
            if ($realTimePrivate && isset($realTimePrivate['count'])) {
                $todayStats['private_messages'] = max(
                    $todayStats['private_messages'] ?? 0,
                    (int)($realTimePrivate['count'] ?? 0)
                );
            }
        // @codeCoverageIgnoreStart
        } catch (\Exception $e) {
            \Pramnos\Logs\Logger::log("StatsService: Error querying real-time messages: " . $e->getMessage(), 'radiochatbox');
        }
        // @codeCoverageIgnoreEnd
        
        // Count registered and guest users active today from sessions
        try {
            $result = $this->db->preparedQuery(
                "SELECT
                    COUNT(DISTINCT CASE WHEN user_id IS NOT NULL THEN username END) as registered_users,
                    COUNT(DISTINCT CASE WHEN user_id IS NULL THEN username END) as guest_users
                FROM presence_sessions
                WHERE last_heartbeat >= :today_start
                AND last_heartbeat <= :today_end",
                ['today_start' => $todayStart, 'today_end' => $todayEnd]
            );
            $realTimeUsers = $result ? $result->fetch() : null;
            
            // Use real-time counts if higher than aggregated stats
            $todayStats['registered_users'] = max(
                $todayStats['registered_users'] ?? 0,
                (int)($realTimeUsers['registered_users'] ?? 0)
            );
            $todayStats['guest_users'] = max(
                $todayStats['guest_users'] ?? 0,
                (int)($realTimeUsers['guest_users'] ?? 0)
            );
        // @codeCoverageIgnoreStart
        } catch (\Exception $e) {
            \Pramnos\Logs\Logger::log("StatsService: Error querying real-time users: " . $e->getMessage(), 'radiochatbox');
        }
        // @codeCoverageIgnoreEnd

        $summary = [
            'today' => $todayStats,
            'this_week' => $weekStats,
            'this_month' => $monthStats,
            'this_year' => $yearStats,
            'latest_snapshot' => $latestSnapshot,
            'generated_at' => date('Y-m-d H:i:s')
        ];

        // Cache for 30 seconds - short TTL ensures real-time updates show up quickly
        FlatCache::default()->set($cacheKey, $summary, 30);

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
        $result = $this->db->preparedQuery(
            "SELECT
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
            WHERE stat_hour >= :today_start AND stat_hour <= :today_end",
            ['today_start' => $todayStart, 'today_end' => $todayEnd]
        );
        $hourlyData = $result ? $result->fetch() : null;

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
        $result = $this->db->preparedQuery(
            "SELECT
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
            WHERE stat_hour >= :week_start AND stat_hour <= :today_end",
            ['week_start' => $weekStartTime, 'today_end' => $todayEndTime]
        );
        $weekData = $result ? $result->fetch() : null;

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
        $result = $this->db->preparedQuery(
            "SELECT
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
            WHERE stat_hour >= :month_start AND stat_hour <= :today_end",
            ['month_start' => $monthStartTime, 'today_end' => $todayEndTime]
        );
        $monthData = $result ? $result->fetch() : null;

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
        $result = $this->db->preparedQuery(
            "SELECT
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
            WHERE stat_hour >= :year_start AND stat_hour <= :today_end",
            ['year_start' => $yearStartTime, 'today_end' => $todayEndTime]
        );
        $yearData = $result ? $result->fetch() : null;

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
        $this->db->statement("SELECT cleanup_old_snapshots()");

        // Count remaining rows still older than the retention window.
        return $this->db->queryBuilder()
            ->from('stats_snapshots')
            ->whereRaw("snapshot_time < NOW() - INTERVAL '30 days'")
            ->count();
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
        $lastHourly = FlatCache::default()->get($lastHourlyKey);
        
        if ($lastHourly === null || ($now - (int)$lastHourly) > 4200) { // 70 minutes
            $results['hourly'] = $this->aggregateHourlyStats();
            FlatCache::default()->set($lastHourlyKey, $now, 86400); // Remember for 24 hours
        }
        
        // Check if daily aggregation is needed (run every 25 hours max)
        $lastDailyKey = 'stats:last_daily_aggregation';
        $lastDaily = FlatCache::default()->get($lastDailyKey);
        
        if ($lastDaily === null || ($now - (int)$lastDaily) > 90000) { // 25 hours
            $results['daily'] = $this->aggregateDailyStats();
            FlatCache::default()->set($lastDailyKey, $now, 604800); // Remember for 7 days
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
        $row = $this->db->queryBuilder()
            ->from('stats_snapshots')
            ->orderBy('snapshot_time', 'desc')
            ->first();
        return ($row && $row->numRows > 0) ? $row->fields : null;
    }
}
