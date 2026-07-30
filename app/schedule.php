<?php

/**
 * PramnosFramework task schedule — the CRON FALLBACK for the periodic jobs
 * (migration Phase 4).
 *
 * The PRIMARY scheduler is the in-worker one (src/Services/Scheduler.php), driven
 * by the per-feature workers (stats:start / tracker:start / maintenance:start /
 * bot:start) under the daemon orchestrator. This cron path is kept because
 * cron cannot express sub-minute cadences: `track_poll` runs every 30s so short
 * songs are not missed — the finest a per-minute `schedule:run` cron can do is
 * every minute (declared below with that caveat).
 *
 * Use ONE of the two, never both (they don't share the in-worker per-task
 * last-run state, so running both would double-execute):
 *   - default: the orchestrated worker (`./radiochatbox daemons`), or
 *   - cron:    `* * * * * php radiochatbox.php schedule:run`  (for hosts without the daemon)
 *
 * Each task shells out to `radiochatbox.php bot:run-task <name>`, reusing the exact same
 * runners as the in-worker scheduler (no duplicated logic). withoutOverlapping()
 * guards against a slow run colliding with the next tick.
 */

use Pramnos\Scheduling\Scheduler;

$root = defined('ROOT') ? ROOT : dirname(__DIR__);

/** Build a callable that runs one in-worker task via the worker CLI. */
$task = static function (string $name) use ($root): callable {
    return static function () use ($root, $name): void {
        passthru(
            escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg($root . '/radiochatbox.php') . ' bot:run-task '
            . escapeshellarg($name)
        );
    };
};

// Track polling — 30s in-worker; cron can only manage every minute.
Scheduler::call($task('track_poll'))->everyMinute()->withoutOverlapping()
    ->description('Read the stream and record the current track (cron fallback: 60s, worker does 30s)');
Scheduler::call($task('track_enrich'))->everyFiveMinutes()->withoutOverlapping()
    ->description('Enrich tracks whose metadata is still missing');
Scheduler::call($task('stats_snapshot'))->everyFiveMinutes()->withoutOverlapping()
    ->description('Record a user/listener snapshot');
Scheduler::call($task('stats_hourly'))->cron('5 * * * *')->withoutOverlapping()
    ->description('Aggregate the finished hour');
Scheduler::call($task('stats_daily'))->cron('10 0 * * *')->withoutOverlapping()
    ->description('Aggregate the finished day');
Scheduler::call($task('stats_weekly'))->cron('20 0 * * *')->withoutOverlapping()
    ->description('Aggregate the week so far');
Scheduler::call($task('stats_monthly'))->cron('30 0 * * *')->withoutOverlapping()
    ->description('Aggregate the month so far');
Scheduler::call($task('stats_yearly'))->cron('40 0 * * *')->withoutOverlapping()
    ->description('Aggregate the year so far');
Scheduler::call($task('cleanup'))->hourly()->withoutOverlapping()
    ->description('Expired bans/DM blocks, stale sessions, old messages');
Scheduler::call($task('prune_llm_log'))->cron('0 3 * * *')->withoutOverlapping()
    ->description('Drop LLM log entries past the retention window');
Scheduler::call($task('llm_balance_snapshot'))->hourly()->withoutOverlapping()
    ->description('Record the provider balance for spend measurement');
