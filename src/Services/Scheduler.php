<?php

namespace RadioChatBox\Services;

use RadioChatBox\Services\SettingsService;
use RadioChatBox\Services\TrackStatsService;
use RadioChatBox\Services\RadioStatusService;
use RadioChatBox\Services\StatsService;
use RadioChatBox\Services\CleanupService;
use Pramnos\Database\Database as PramnosDatabase;

/**
 * Periodic maintenance, run by the bot worker instead of by crontab entries.
 *
 * The worker already has everything scheduled work needs and cron does not: a
 * single-instance lock with a heartbeat (so a slow run can never overlap itself),
 * graceful shutdown, structured logging, and a place to report health. Moving the
 * jobs in also removes the token-authenticated cleanup URL, which was a public
 * endpoint doing database maintenance.
 *
 * What deliberately stays in cron: the database dump. It has to keep working when the
 * application is broken - and if the worker is the broken part, backups must not stop
 * with it.
 *
 * Driven by the per-feature workers (each runs its own task group; see
 * src/ConsoleCommands) under the daemon orchestrator, or by `schedule:run` from
 * cron. Running both is pointless (not both at once): each task records its own
 * last run and will not run twice within its interval.
 *
 * Cadence is either a plain interval ('every' seconds) or calendar-aligned
 * ('at_hour'/'at_minute' UTC), because "aggregate yesterday" means a time of day, not
 * "24h after whenever the worker last restarted".
 */
class Scheduler
{
    /**
     * @var array<string,array{every:int,at_hour?:int,at_minute?:int,description:string}>
     */
    public const TASKS = [
        // The current track has to be caught while it is playing: bundled with the
        // five-minute snapshot, a three-minute song was missed entirely. Polling this
        // often is cheap and safe - TrackStatsService::recordPlay() de-duplicates
        // atomically, so nothing is written until the track actually changes.
        'track_poll' => [
            'every' => 30,
            'every_setting' => 'track_poll_seconds',
            'group' => 'tracker',
            'description' => 'Read the stream and record the current track',
        ],
        'track_enrich' => [
            'every' => 300,
            'group' => 'tracker',
            'description' => 'Sweep up tracks whose metadata is still missing (a change enriches immediately; this catches failures)',
        ],
        'stats_snapshot' => [
            'every' => 300,
            'group' => 'stats',
            'description' => 'Record a user/listener snapshot (was: */5 * * * * stats-cron.php snapshot)',
        ],
        'stats_hourly' => [
            'every' => 3600,
            'at_minute' => 5,
            'group' => 'stats',
            'description' => 'Aggregate the finished hour',
        ],
        'stats_daily' => [
            'every' => 86400,
            'at_hour' => 0,
            'at_minute' => 10,
            'group' => 'stats',
            'description' => 'Aggregate the finished day',
        ],
        'stats_weekly' => [
            'every' => 86400,
            'at_hour' => 0,
            'at_minute' => 20,
            'group' => 'stats',
            'description' => 'Aggregate the week so far',
        ],
        'stats_monthly' => [
            'every' => 86400,
            'at_hour' => 0,
            'at_minute' => 30,
            'group' => 'stats',
            'description' => 'Aggregate the month so far',
        ],
        'stats_yearly' => [
            'every' => 86400,
            'at_hour' => 0,
            'at_minute' => 40,
            'group' => 'stats',
            'description' => 'Aggregate the year so far',
        ],
        'cleanup' => [
            'every' => 3600,
            'group' => 'maintenance',
            'description' => 'Expired bans and DM blocks, stale sessions, old messages (was: the cleanup.php cron URL)',
        ],
        'mood_decay' => [
            'every' => 600,
            'group' => 'maintenance',
            'description' => 'Fade bot moods back toward baseline over time',
        ],
        'prune_llm_log' => [
            'every' => 86400,
            'at_hour' => 3,
            'group' => 'bot',
            'description' => 'Drop LLM log entries past the retention window',
        ],
        'llm_balance_snapshot' => [
            'every' => LlmAccount::SNAPSHOT_INTERVAL,
            'group' => 'bot',
            'description' => 'Record the provider balance, so real spend can be measured',
        ],
    ];

    /** Task groups, one per feature worker (see src/ConsoleCommands). */
    public const GROUP_TRACKER = 'tracker';
    public const GROUP_STATS = 'stats';
    public const GROUP_MAINTENANCE = 'maintenance';
    public const GROUP_BOT = 'bot';

    private PramnosDatabase $db;
    private SettingsService $settings;

    /** @var array<string,callable>|null Injected runners, for tests */
    private ?array $runners;

    /** @var callable */
    private $clock;

    /**
     * @param array<string,callable>|null $runners Task name => runner, for tests
     * @param callable|null               $clock   Returns the current unix time
     */
    public function __construct(
        ?SettingsService $settings = null,
        ?array $runners = null,
        ?callable $clock = null
    ) {
        $this->db = PramnosDatabase::getInstance();
        $this->settings = $settings ?? new SettingsService();
        $this->runners = $runners;
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function tasks(): array
    {
        if ($this->runners === null) {
            return array_map([$this, 'withConfiguredInterval'], self::TASKS);
        }

        // Injected runners define the task set, so a test is not tied to the real one.
        return array_intersect_key(
            self::TASKS + array_map(
                static fn (): array => ['every' => 60, 'description' => 'test task'],
                $this->runners
            ),
            $this->runners
        );
    }

    /**
     * Apply a settings override to a task's interval, so how often the stream is
     * polled can be tuned without a deploy.
     *
     * @param array<string,mixed> $task
     *
     * @return array<string,mixed>
     */
    private function withConfiguredInterval(array $task): array
    {
        if (!isset($task['every_setting'])) {
            return $task;
        }

        $configured = (int) $this->settings->get((string) $task['every_setting'], 0);

        if ($configured > 0) {
            $task['every'] = max(10, min(3600, $configured));
        }

        return $task;
    }

    /**
     * When each task last ran, with its outcome.
     *
     * @return array<string,array<string,mixed>>
     */
    public function state(): array
    {
        $rows = $this->db->queryBuilder()->from('scheduled_tasks')->getAll();

        $state = [];
        foreach ($rows as $row) {
            $state[(string) $row['task']] = $row;
        }

        return $state;
    }

    /**
     * Everything due now, in the order they are defined.
     *
     * @return list<string>
     */
    public function dueTasks(): array
    {
        $now = ($this->clock)();
        $state = $this->state();

        $due = [];
        foreach ($this->tasks() as $name => $task) {
            if ($this->isDue($name, $task, $state[$name] ?? null, $now)) {
                $due[] = $name;
            }
        }

        return $due;
    }

    /**
     * @param array<string,mixed>      $task
     * @param array<string,mixed>|null $row
     */
    private function isDue(string $name, array $task, ?array $row, int $now): bool
    {
        $lastRun = isset($row['last_run_at']) ? strtotime((string) $row['last_run_at']) : null;

        // Never run: start now rather than waiting a whole interval first.
        if ($lastRun === null || $lastRun === false) {
            return true;
        }

        if (($now - $lastRun) < (int) $task['every']) {
            return false;
        }

        // Calendar-aligned tasks additionally wait for their time of day, so "the
        // finished day" is aggregated after midnight rather than at whatever moment
        // the interval happens to elapse.
        if (isset($task['at_hour']) && (int) gmdate('G', $now) !== (int) $task['at_hour']) {
            return false;
        }

        if (isset($task['at_minute']) && (int) gmdate('i', $now) < (int) $task['at_minute']) {
            return false;
        }

        return true;
    }

    /**
     * Run one task, recording the outcome either way. Never throws: one broken task
     * must not stop the worker or the others.
     *
     * @return array{task:string,status:string,duration_ms:int,error:?string}
     */
    public function run(string $name): array
    {
        $startedAt = microtime(true);
        $status = 'ok';
        $error = null;

        try {
            $runner = $this->runner($name);

            if ($runner === null) {
                throw new \InvalidArgumentException("Unknown scheduled task: {$name}");
            }

            $runner();
        } catch (\Throwable $e) {
            $status = 'failed';
            $error = $e->getMessage();
            \Pramnos\Logs\Logger::log("Scheduler: task {$name} failed: " . $error, 'radiochatbox');
        }

        $duration = (int) round((microtime(true) - $startedAt) * 1000);
        $this->record($name, $status, $duration, $error);

        return ['task' => $name, 'status' => $status, 'duration_ms' => $duration, 'error' => $error];
    }

    /**
     * Run what is due. At most $limit tasks per call, so a backlog cannot stall the
     * reply queue for long: the worker comes back on its next tick.
     *
     * @param callable|null $heartbeat Called between tasks, to keep the lock lease
     *
     * @return list<array{task:string,status:string,duration_ms:int,error:?string}>
     */
    public function runDue(?callable $heartbeat = null, int $limit = 2, ?string $group = null): array
    {
        $results = [];

        $due = $this->dueTasks();
        if ($group !== null) {
            $tasks = $this->tasks();
            $due = array_values(array_filter(
                $due,
                static fn (string $name): bool => ($tasks[$name]['group'] ?? null) === $group
            ));
        }

        foreach (array_slice($due, 0, max(1, $limit)) as $name) {
            $results[] = $this->run($name);

            if ($heartbeat !== null) {
                $heartbeat();
            }
        }

        return $results;
    }

    /**
     * What actually happens for a task. Kept out of the const table because those
     * cannot hold closures.
     */
    private function runner(string $name): ?callable
    {
        if ($this->runners !== null) {
            return $this->runners[$name] ?? null;
        }

        return match ($name) {
            'track_poll' => static function (): void {
                $nowPlaying = (new RadioStatusService())->getNowPlaying();
                $tracks = new TrackStatsService();
                $trackId = $tracks->recordPlay($nowPlaying);

                // recordPlay returns non-null ONLY when the track just changed, so
                // this branch is the single server-side detection of a new track.
                if ($trackId !== null) {
                    // A change is exactly when the album and artwork are wanted, so a
                    // track nobody has seen before is enriched immediately instead of
                    // waiting for the sweep. Already-enriched tracks are skipped, or
                    // every replay would hit the external APIs again.
                    $tracks->enrichIfPending($trackId);

                    // Push the new track to every connected client so browsers do
                    // not each poll /api/now-playing — one server poll fans out.
                    try {
                        \Pramnos\Broadcasting\BroadcastingManager::instance()->broadcast(
                            'chat:updates',
                            'now_playing',
                            ['type' => 'now_playing', 'nowPlaying' => $nowPlaying]
                        );
                    // @codeCoverageIgnoreStart
                    } catch (\Throwable $e) {
                        \Pramnos\Logs\Logger::log('Scheduler: now_playing broadcast failed: ' . $e->getMessage(), 'radiochatbox');
                    }
                    // @codeCoverageIgnoreEnd
                }
            },
            'track_enrich' => static fn () => (new TrackStatsService())->enrichPending(5),
            'stats_snapshot' => static fn () => (new StatsService())->recordSnapshot(true),
            'stats_hourly' => static fn () => (new StatsService())->aggregateHourlyStats(),
            'stats_daily' => static fn () => (new StatsService())->aggregateDailyStats(),
            'stats_weekly' => static fn () => (new StatsService())->aggregateWeeklyStats(),
            'stats_monthly' => static fn () => (new StatsService())->aggregateMonthlyStats(),
            'stats_yearly' => static fn () => (new StatsService())->aggregateYearlyStats(),
            'cleanup' => static fn () => (new CleanupService())->runAll(),
            'mood_decay' => fn () => (new MoodService($this->settings))->decay(),
            'prune_llm_log' => fn () => (new LlmLog($this->settings))->prune(),
            'llm_balance_snapshot' => fn () => (new LlmAccount($this->settings))->snapshot(true),
            default => null,
        };
    }

    private function record(string $name, string $status, int $durationMs, ?string $error): void
    {
        try {
            $qb = $this->db->queryBuilder()->from('scheduled_tasks');
            $qb->upsert(
                [
                    'task'             => $name,
                    'last_run_at'      => $qb->raw('NOW()'),
                    'last_status'      => $status,
                    'last_duration_ms' => $durationMs,
                    'last_error'       => $error,
                    'runs'             => 1,
                    'failures'         => $status === 'ok' ? 0 : 1,
                ],
                ['task'],
                [
                    // Custom SET clause: overwrite the "last" columns with the
                    // just-inserted values, bump runs, and accumulate failures —
                    // the counter semantics EXCLUDED-only upserts cannot express.
                    'last_run_at'      => $qb->raw('NOW()'),
                    'last_status'      => $qb->raw('EXCLUDED.last_status'),
                    'last_duration_ms' => $qb->raw('EXCLUDED.last_duration_ms'),
                    'last_error'       => $qb->raw('EXCLUDED.last_error'),
                    'runs'             => $qb->raw('scheduled_tasks.runs + 1'),
                    'failures'         => $qb->raw('scheduled_tasks.failures + EXCLUDED.failures'),
                ]
            );
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Scheduler::record failed: ' . $e->getMessage(), 'radiochatbox');
        }
        // @codeCoverageIgnoreEnd
    }
}
