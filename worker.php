#!/usr/bin/env php
<?php
/**
 * Fake User Bot Worker
 *
 * Processes the delayed jobs that produce automatic LLM replies for fake users
 * in private messages (see src/BotService.php).
 *
 * Usage:
 *   php worker.php run [--max-runtime=SECONDS] [--sleep=SECONDS] [--batch=N] [--watch-files]
 *   php worker.php once [--schedule]
 *   php worker.php status [--stale-after=SECONDS]
 *   php worker.php log [--limit=N] [--problems]
 *   php worker.php schedule
 *   php worker.php run-task <name>
 *   php worker.php flush
 *
 * Common options: --lock=PATH, --stale-after=SECONDS, --verbose.
 *
 * Actions:
 *   run     - Loop, polling every --sleep seconds (default 1s) until --max-runtime
 *             is reached (0 = forever). Meant for systemd/supervisor or cron.
 *             A settings change is adopted in place. Code cannot be reloaded into a
 *             running PHP process, so replacing the process IS the reload: in
 *             production daemon.php does that when a new commit is deployed, and
 *             --watch-files makes a directly-run worker do it on any file change.
 *   once    - Process everything that is currently due, then exit. Delivery is then
 *             only as accurate as the cron interval. With --schedule it also runs any
 *             periodic task that is due, so one crontab line covers everything.
 *   status  - Print worker health (pid, uptime, heartbeat age), queue size and
 *             configuration. Exit code: 0 healthy/idle, 2 worker looks wedged.
 *   log     - Recent LLM calls with token usage, so a bad or truncated reply can
 *             be traced. --problems shows only failures and truncations.
 *   schedule    - List the periodic tasks with their cadence and last run.
 *   run-task    - Run one periodic task now, by name.
 *   flush   - Drop every scheduled job (cancels pending bot replies).
 *
 * `run` and `once` hold a single-instance lock with a heartbeat (framework
 * Pramnos\Console\WorkerLock, plain PHP - no flock), so a second worker exits
 * instead of doubling up and no
 * `flock(1)` wrapper is needed in the crontab. A lock left behind by a killed or
 * wedged worker is taken over automatically (dead pid, or no heartbeat for
 * --stale-after seconds), and `status` reports the heartbeat so a worker that is
 * alive but stuck is visible.
 *
 * Recommended: run it as a service so replies land on time.
 *
 *   # systemd (/etc/systemd/system/radiochatbox-worker.service)
 *   [Service]
 *   WorkingDirectory=/path/to/radiochatbox
 *   ExecStart=/usr/bin/php /path/to/radiochatbox/worker.php run
 *   Restart=always
 *   RestartSec=5
 *   # Optional: let systemd restart a wedged worker by itself. The worker sends
 *   # sd_notify pings on every heartbeat when NOTIFY_SOCKET is set.
 *   #Type=notify
 *   #WatchdogSec=120
 *
 * Cron alternative - `run --max-runtime=55` polls for 55 of every 60 seconds, so
 * replies stay ~1s accurate with only a ~5s gap between runs:
 *   * * * * * cd /path/to/radiochatbox && php worker.php run --max-runtime=55 >> logs/bot-worker.log 2>&1
 */

declare(strict_types=1);

// Ensure we're running from CLI
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script can only be run from command line');
}

require_once __DIR__ . '/vendor/autoload.php';

use RadioChatBox\Services\BotService;
use RadioChatBox\JobQueue;
use RadioChatBox\Services\LlmAccount;
use RadioChatBox\Services\LlmLog;
use RadioChatBox\Services\LlmPricing;
use RadioChatBox\Services\LlmService;
use RadioChatBox\Services\Scheduler;
use RadioChatBox\Services\SettingsService;
use Pramnos\Console\WorkerLock;
use Pramnos\Console\WorkerReloader;
use Pramnos\Console\SystemdNotifier;
use Pramnos\Console\SignalStop;
use RadioChatBox\Installation;

// Boot the framework: define ROOT, load .env, wire the Redis ConnectionManager and
// connect the database (same bootstrap the web front controller and bin/rcb use).
// Without this a directly-run worker cannot read settings — Settings/Database need it.
if (!defined('ROOT')) {
    define('ROOT', __DIR__);
}
require ROOT . '/bootstrap/pramnos.php';
if (!radiochatbox_boot_pramnos()) {
    fwrite(STDERR, "PramnosFramework is not available in this environment.\n");
    exit(1);
}

/**
 * Log message with timestamp
 */
function logMessage(string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
}

/**
 * Format a duration for the status output.
 */
function formatDuration(int $seconds): string
{
    if ($seconds < 60) {
        return $seconds . 's';
    }
    if ($seconds < 3600) {
        return intdiv($seconds, 60) . 'm ' . ($seconds % 60) . 's';
    }

    return intdiv($seconds, 3600) . 'h ' . intdiv($seconds % 3600, 60) . 'm';
}

/**
 * Parse --key=value arguments
 *
 * @param list<string> $argv
 *
 * @return array<string,string>
 */
function parseOptions(array $argv): array
{
    $options = [];

    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--') && str_contains($arg, '=')) {
            [$key, $value] = explode('=', substr($arg, 2), 2);
            $options[$key] = $value;
        }
    }

    return $options;
}

/**
 * Run one batch of due jobs. Returns the number of jobs processed.
 *
 * The heartbeat is refreshed after every single job, not once per batch: one job
 * can block for as long as the LLM timeout, and a batch of them would otherwise
 * look like a wedged worker.
 *
 * @param int $totalProcessed Running total across batches, updated in place
 */
function processBatch(
    BotService $bot,
    JobQueue $queue,
    int $batchSize,
    bool $verbose,
    ?WorkerLock $lock = null,
    int &$totalProcessed = 0,
    ?SystemdNotifier $systemd = null
): int {
    $jobs = $queue->claimDue($batchSize);

    foreach ($jobs as $job) {
        $lock?->heartbeat([
            'jobs_processed' => $totalProcessed,
            'current_job' => $job['type'] . ' ' . $job['id'],
        ]);

        try {
            $result = match ($job['type']) {
                BotService::JOB_REPLY => $bot->processReplyJob($job['payload']),
                BotService::JOB_DELIVER => $bot->processDeliverJob($job['payload']),
                default => 'unknown job type: ' . $job['type'],
            };

            if ($verbose || !str_starts_with($result, 'skipped')) {
                logMessage("{$job['type']} {$job['id']}: {$result}");
            }
        } catch (Throwable $e) {
            $result = 'FAILED: ' . $e->getMessage();
            $requeued = $queue->retry($job);
            logMessage(sprintf(
                '%s %s FAILED (attempt %d): %s%s',
                $job['type'],
                $job['id'],
                $job['attempts'] + 1,
                $e->getMessage(),
                $requeued ? ' - retrying' : ' - giving up'
            ));
        }

        $totalProcessed++;

        $lock?->heartbeat([
            'jobs_processed' => $totalProcessed,
            'current_job' => null,
            'last_job' => $job['type'] . ': ' . mb_substr($result, 0, 120),
            'last_job_at' => time(),
        ]);
        $systemd?->watchdog();
    }

    return count($jobs);
}

/**
 * Refuse to start when another worker already holds the lock, and say whether
 * that worker looks healthy or wedged.
 *
 * @return int|null Exit code to use, or null when the lock was acquired
 */
function acquireOrExplain(WorkerLock $lock, int $staleAfter): ?int
{
    $takenOverFrom = null;

    if ($lock->acquire($takenOverFrom)) {
        if ($takenOverFrom !== null) {
            logMessage("Took over the lock from {$takenOverFrom}");
        }

        return null;
    }

    $state = $lock->readState();
    $age = $lock->heartbeatAge($state);
    $pid = $state['pid'] ?? '?';

    if ($age !== null && $age > $staleAfter) {
        logMessage(sprintf(
            'Another worker (pid %s) holds the lock but its last heartbeat was %s ago '
            . '- it looks WEDGED. Investigate or kill it; the lock frees itself when it dies.',
            (string) $pid,
            formatDuration($age)
        ));
        logMessage('  Lock file: ' . $lock->getPath());

        return 2;
    }

    logMessage(sprintf(
        'Another worker is already running (pid %s, heartbeat %s ago) - nothing to do.',
        (string) $pid,
        $age === null ? 'unknown' : formatDuration($age)
    ));

    return 0;
}

// ============================================================================
// MAIN
// ============================================================================

$action = $argv[1] ?? 'run';
if (str_starts_with($action, '--')) {
    $action = 'run';
}

$options = parseOptions(array_slice($argv, 1));
$verbose = isset($options['verbose']) || in_array('--verbose', $argv, true);

try {
    $settings = new SettingsService();
    $queue = new JobQueue();
    $staleAfter = max(10, (int) ($options['stale-after'] ?? WorkerLock::DEFAULT_STALE_AFTER));
    // An explicit --lock wins; otherwise the per-installation path the dashboard
    // health check also reads (WorkerLock's own default is not installation-scoped).
    $lockPath = (string) ($options['lock'] ?? '');
    $lock = new WorkerLock('worker', $lockPath !== '' ? $lockPath : Installation::lockPath(), $staleAfter);
    // systemd Type=notify watchdog (a no-op under cron / by hand).
    $systemd = new SystemdNotifier();

    switch ($action) {
        case 'status':
            $llm = LlmService::fromSettings($settings);
            $workerState = $lock->readState();
            $isRunning = $lock->isHeldByAnother();
            $heartbeatAge = $lock->heartbeatAge($workerState);
            // Alive but not reporting progress - a plain pid check would call
            // this healthy.
            $isWedged = $lock->holderIsWedged();

            logMessage('Bot worker status');

            if ($isRunning) {
                $uptime = isset($workerState['started_at'])
                    ? formatDuration(max(0, time() - (int) $workerState['started_at']))
                    : 'unknown';

                logMessage('  Worker          : ' . ($isWedged ? 'ALIVE but WEDGED (no progress)' : 'running'));
                logMessage('  ├─ pid          : ' . (string) ($workerState['pid'] ?? '?')
                    . ' on ' . (string) ($workerState['host'] ?? '?'));
                logMessage('  ├─ uptime       : ' . $uptime);
                logMessage('  ├─ heartbeat    : ' . ($heartbeatAge === null ? 'unknown' : formatDuration($heartbeatAge) . ' ago')
                    . ($isWedged ? "  <-- older than {$staleAfter}s" : ''));
                logMessage('  ├─ jobs handled : ' . (string) ($workerState['jobs_processed'] ?? 0));

                if (!empty($workerState['current_job'])) {
                    logMessage('  ├─ current job  : ' . (string) $workerState['current_job']);
                }
                if (!empty($workerState['last_job'])) {
                    logMessage('  ├─ last job     : ' . (string) $workerState['last_job']);
                }
                logMessage('  └─ lock file    : ' . $lock->getPath());
            } elseif ($workerState !== null) {
                $crashed = (string) ($workerState['status'] ?? '') === 'running';
                logMessage('  Worker          : not running' . ($crashed ? ' (previous run died without releasing the lock)' : ''));
                logMessage('  └─ last run     : ' . (string) ($workerState['status'] ?? 'unknown')
                    . ($heartbeatAge === null ? '' : ', ' . formatDuration($heartbeatAge) . ' ago')
                    . ', ' . (string) ($workerState['jobs_processed'] ?? 0) . ' job(s) handled');
            } else {
                logMessage('  Worker          : not running (never started on this host)');
            }

            logMessage('  Replies enabled : ' . ($settings->get('bot_replies_enabled', 'false') === 'true' ? 'yes' : 'no'));
            logMessage('  API key set     : ' . ($llm->isConfigured()
                ? 'yes'
                : 'NO (set it in Admin > Settings > Fake User Auto-Replies)'));
            logMessage('  Endpoint        : ' . $llm->getBaseUrl());
            logMessage('  Model           : ' . $llm->getModel());
            logMessage('  Msg limit/thread: ' . $settings->get('bot_max_messages_per_thread', 4));
            logMessage('  Typing sec/word : ' . $settings->get('bot_typing_seconds_per_word', 1.5));
            logMessage('  Jobs queued     : ' . $queue->size());

            $next = $queue->secondsUntilNext();
            logMessage('  Next job in     : ' . ($next === null ? 'n/a (empty)' : $next . 's'));

            if ($isWedged) {
                logMessage(sprintf(
                    'Worker pid %s is alive but has not reported progress for %s (--stale-after=%ds).'
                    . ' The next worker will take the lock over.',
                    (string) ($workerState['pid'] ?? '?'),
                    $heartbeatAge === null ? 'a while' : formatDuration($heartbeatAge),
                    $staleAfter
                ));
            }

            $scheduleState = (new Scheduler($settings))->state();
            if ($scheduleState !== []) {
                logMessage('');
                logMessage('Scheduled tasks');
                foreach (Scheduler::TASKS as $task => $meta) {
                    $row = $scheduleState[$task] ?? null;
                    logMessage(sprintf(
                        '  %-22s %s',
                        $task,
                        $row === null
                            ? 'never run'
                            : sprintf(
                                'last %s (%s, %sms)%s',
                                $row['last_run_at'],
                                $row['last_status'],
                                $row['last_duration_ms'],
                                (int) $row['failures'] > 0 ? sprintf(' - %d failure(s)', $row['failures']) : ''
                            )
                    ));
                }
            }

            // Non-zero so monitoring can alert on a wedged worker.
            exit($isWedged ? 2 : 0);

        case 'log':
            $llmLog = new LlmLog($settings);
            $summary = $llmLog->summary(24);

            logMessage('LLM calls, last 24h');
            $currency = $summary['currency'] ?: LlmPricing::CURRENCY;
            logMessage(sprintf(
                '  %s call(s), %s error(s), %s truncated | %s tokens (%s on reasoning) | ~%s | avg %sms',
                $summary['calls'] ?? 0,
                $summary['errors'] ?? 0,
                $summary['truncated'] ?? 0,
                $summary['total_tokens'] ?? 0,
                $summary['reasoning_tokens'] ?? 0,
                LlmPricing::format((float) ($summary['cost'] ?? 0), $currency),
                $summary['avg_duration_ms'] ?? '-'
            ));

            if ((int) ($summary['uncosted_calls'] ?? 0) > 0) {
                logMessage(sprintf(
                    '  (%s call(s) have no cost: no price configured for the model - see bot_llm_prices)',
                    $summary['uncosted_calls']
                ));
            }

            // The estimate above is priced from a setting; this is real money.
            $account = new LlmAccount($settings);
            $balance = $account->balance();
            if ($balance !== null) {
                logMessage(sprintf(
                    '  balance: %s%s',
                    LlmPricing::format($balance['total'], $balance['currency']),
                    $balance['is_available'] ? '' : ' (INSUFFICIENT - calls will fail)'
                ));
            }

            $real = $account->realSpend(24);
            if ($real !== null) {
                logMessage(sprintf(
                    '  actually spent (from %d balance readings): %s%s',
                    $real['readings'],
                    LlmPricing::format($real['spent'], $real['currency']),
                    $real['topped_up'] > 0
                        ? ' (+' . LlmPricing::format($real['topped_up'], $real['currency']) . ' topped up)'
                        : ''
                ));
            }

            if (!$llmLog->isEnabled()) {
                logMessage('  (logging is OFF - enable it in Admin > Settings)');
            }

            $entries = $llmLog->recent(
                (int) ($options['limit'] ?? 10),
                isset($options['problems']) || in_array('--problems', $argv, true)
            );

            if (empty($entries)) {
                logMessage('No entries.');
                exit(0);
            }

            foreach (array_reverse($entries) as $entry) {
                logMessage(sprintf(
                    '%s  %s -> %s  %s  finish=%s  %sms',
                    $entry['created_at'],
                    $entry['fake_nickname'] ?? '?',
                    $entry['peer_username'] ?? '?',
                    $entry['model'],
                    $entry['finish_reason'] ?? '-',
                    $entry['duration_ms'] ?? '-'
                ));

                if ($entry['cost'] !== null) {
                    logMessage('    cost: ~' . LlmPricing::format(
                        (float) $entry['cost'],
                        (string) ($entry['currency'] ?: $currency)
                    ));
                }

                $usage = json_decode((string) $entry['usage'], true) ?: [];
                logMessage(sprintf(
                    '    tokens: prompt=%s completion=%s reasoning=%s | reasoning setting: %s, budget: %s',
                    $usage['prompt_tokens'] ?? '-',
                    $usage['completion_tokens'] ?? '-',
                    $usage['completion_tokens_details']['reasoning_tokens'] ?? 0,
                    $entry['reasoning'] ? 'on' : 'off',
                    $entry['max_tokens']
                ));

                if (!empty($entry['reply'])) {
                    logMessage('    reply: ' . mb_substr((string) $entry['reply'], 0, 160));
                }
                if (!empty($entry['error'])) {
                    logMessage('    ERROR: ' . $entry['error']);
                }
            }
            exit(0);

        case 'schedule':
            $scheduler = new Scheduler($settings);
            $state = $scheduler->state();
            $due = $scheduler->dueTasks();

            logMessage('Scheduled tasks (run them with: worker.php run --schedule)');
            foreach ($scheduler->tasks() as $task => $meta) {
                $row = $state[$task] ?? null;
                logMessage(sprintf(
                    '  %-22s every %-7s %-11s %s',
                    $task,
                    formatDuration((int) $meta['every']),
                    in_array($task, $due, true) ? 'DUE NOW' : '',
                    $row === null ? 'never run' : 'last ' . $row['last_run_at'] . ' (' . $row['last_status'] . ')'
                ));
                logMessage('       ' . $meta['description']);
            }
            exit(0);

        case 'run-task':
            $taskName = $argv[2] ?? '';

            if ($taskName === '' || !isset(Scheduler::TASKS[$taskName])) {
                logMessage('Usage: php worker.php run-task <name>. Known tasks: '
                    . implode(', ', array_keys(Scheduler::TASKS)));
                exit(1);
            }

            $result = (new Scheduler($settings))->run($taskName);
            logMessage(sprintf(
                'Task %s: %s in %dms%s',
                $result['task'],
                $result['status'],
                $result['duration_ms'],
                $result['error'] === null ? '' : ' - ' . $result['error']
            ));
            exit($result['status'] === 'ok' ? 0 : 1);

        case 'prune-log':
            $deleted = (new LlmLog($settings))->prune();
            logMessage("Deleted {$deleted} LLM log entr(ies) past the retention window");
            exit(0);

        case 'flush':
            $dropped = $queue->flush();
            logMessage("Dropped {$dropped} scheduled job(s)");
            exit(0);

        case 'once':
            $exitCode = acquireOrExplain($lock, $staleAfter);
            if ($exitCode !== null) {
                exit($exitCode);
            }

            $systemd->ready();

            try {
                $bot = new BotService($settings, $queue);
                $processed = 0;
                processBatch($bot, $queue, (int) ($options['batch'] ?? 50), $verbose, $lock, $processed, $systemd);
                logMessage("Processed {$processed} job(s)");

                // With --schedule this one cron line covers the periodic tasks too, so a
                // machine with no supervisor needs a single entry rather than six.
                if (isset($options['schedule']) || in_array('--schedule', $argv, true)) {
                    foreach ((new Scheduler($settings))->runDue(fn () => $lock->heartbeat()) as $result) {
                        logMessage(sprintf(
                            'Task %s: %s in %dms%s',
                            $result['task'],
                            $result['status'],
                            $result['duration_ms'],
                            $result['error'] === null ? '' : ' - ' . $result['error']
                        ));
                    }
                }
            } finally {
                $lock->release();
            }
            exit(0);

        case 'run':
            $maxRuntime = (int) ($options['max-runtime'] ?? 0); // 0 = forever
            $sleep = max(1, (int) ($options['sleep'] ?? 1));
            $batchSize = max(1, (int) ($options['batch'] ?? 20));

            // Single instance, whether started by systemd, cron or by hand.
            $exitCode = acquireOrExplain($lock, $staleAfter);
            if ($exitCode !== null) {
                exit($exitCode);
            }

            $bot = new BotService($settings, $queue);
            $account = new LlmAccount($settings);

            // Periodic maintenance, in place of crontab entries. Opt-in, so an
            // existing crontab keeps working until it is removed by hand.
            $withSchedule = isset($options['schedule']) || in_array('--schedule', $argv, true);
            $scheduler = $withSchedule ? new Scheduler($settings) : null;

            // A daemon otherwise runs the code and the configuration it started with.
            // Watch the app's own code and lockfile; the settings stamp comes from
            // SettingsService (Redis version key, falling back to the DB).
            $reloader = new WorkerReloader(
                Installation::root(),
                ['src', 'worker.php', 'composer.lock'],
                fn (): string => $settings->versionStamp()
            );
            $reloader->baseline();
            // Off by default: in production the supervisor owns restarts (it watches
            // the deployed commit), and mtimes change on every editor save. This is the
            // development convenience - a worker run directly that reloads on save.
            $autoReload = isset($options['watch-files']) || in_array('--watch-files', $argv, true);

            $startedAt = time();
            $running = true;
            $codeChanged = false;
            $totalProcessed = 0;

            // Graceful shutdown so systemd restarts / deploys don't cut a job
            // in half: the framework SignalStop traps SIGTERM/SIGINT and finishes
            // the current batch before the loop exits.
            $signals = new SignalStop([], function (?int $signal) use (&$running): void {
                logMessage('Received signal ' . ($signal ?? '?') . ', finishing current batch...');
                $running = false;
            });
            $signals->install();

            logMessage(sprintf(
                'Worker started (pid %d, sleep=%ds, batch=%d, max-runtime=%s, lock=%s)',
                getmypid(),
                $sleep,
                $batchSize,
                $maxRuntime > 0 ? $maxRuntime . 's' : 'unlimited',
                $lock->getPath()
            ));

            $systemd->ready();

            try {
                while ($running) {
                    processBatch($bot, $queue, $batchSize, $verbose, $lock, $totalProcessed, $systemd);

                    if ($maxRuntime > 0 && (time() - $startedAt) >= $maxRuntime) {
                        break;
                    }

                    // Heartbeat while idle too, so `status` stays meaningful on
                    // a quiet queue - and stop if the lease was lost, rather
                    // than running alongside a replacement worker.
                    if (!$lock->heartbeat(['jobs_processed' => $totalProcessed, 'current_job' => null])) {
                        logMessage('Lock taken over by another worker (we stalled past --stale-after) - exiting');
                        break;
                    }
                    $systemd->watchdog();

                    // The supervisor asks rather than kills, so this is where the
                    // request is honoured: after a batch, with nothing half-done.
                    if ($lock->stopRequested()) {
                        logMessage('Stop requested by the supervisor - finishing up and exiting');
                        break;
                    }

                    // Settings can be adopted in place: only the objects built from
                    // them are stale, and rebuilding those keeps the lock and the
                    // queue intact.
                    if ($reloader->settingsChanged()) {
                        logMessage('Settings changed - rebuilding the LLM client from them');
                        // NOT invalidateCache(): that bumps the version stamp, so the
                        // next tick would see its own write as a fresh change and log
                        // this line forever. The admin save already cleared the cache,
                        // and settings are read through Redis on every get().
                        $bot->refreshSettings();
                        $account = new LlmAccount($settings);
                    }

                    // Code cannot be reloaded into a running PHP process, so the
                    // honest move is to finish the batch and let the supervisor start
                    // a fresh one. Jobs are in Redis and the lock is released below,
                    // so nothing is lost.
                    if ($autoReload && $reloader->codeChanged()) {
                        $codeChanged = true;
                        break;
                    }

                    if ($scheduler !== null) {
                        // Between batches, so a slow aggregation never delays a reply
                        // that is already due; the heartbeat keeps the lock lease.
                        foreach ($scheduler->runDue(fn () => $lock->heartbeat()) as $result) {
                            logMessage(sprintf(
                                'Task %s: %s in %dms%s',
                                $result['task'],
                                $result['status'],
                                $result['duration_ms'],
                                $result['error'] === null ? '' : ' - ' . $result['error']
                            ));
                        }
                    } else {
                        // Without the scheduler, at least keep recording the balance so
                        // real spend stays measurable. Rate-limits itself.
                        $recorded = $account->snapshot();
                        if ($recorded !== null && $verbose) {
                            logMessage(sprintf(
                                'Balance snapshot: %s',
                                LlmPricing::format($recorded['total'], $recorded['currency'])
                            ));
                        }
                    }

                    sleep($sleep);
                }
            } finally {
                $systemd->stopping();
                $lock->release();
            }

            // PHP cannot reload code into a running process, so the reload IS a new
            // process. Under a supervisor that is its job; run by hand or in a
            // container with no restart policy there is nobody to do it, and exiting
            // would simply stop the worker - so it starts its replacement itself.
            if ($codeChanged) {
                if (WorkerReloader::isSupervised()) {
                    logMessage('Code changed on disk - exiting so the supervisor starts a fresh process');
                    exit(0);
                }

                $respawns = (int) ($options['respawned'] ?? 0);
                $uptime = time() - $startedAt;

                // A worker that lived a sensible while was healthy, so the count only
                // guards against a crash loop.
                if ($uptime >= 60) {
                    $respawns = 0;
                }

                if ($respawns >= 5) {
                    logMessage('Code changed, but this process has already respawned 5 times without staying up - stopping instead of looping');
                    exit(1);
                }

                logMessage(sprintf(
                    'Code changed on disk and nothing is supervising this process - restarting myself (attempt %d)',
                    $respawns + 1
                ));

                $arguments = array_values(array_filter(
                    array_slice($argv, 1),
                    static fn (string $argument): bool => !str_starts_with($argument, '--respawned=')
                ));
                $arguments[] = '--respawned=' . ($respawns + 1);

                $command = sprintf(
                    'nohup sh -c %s > /dev/null 2>&1 &',
                    escapeshellarg(sprintf(
                        // The delay lets this process finish releasing the lock, or the
                        // replacement would exit reporting that a worker is running.
                        'sleep 3; exec %s %s %s',
                        escapeshellcmd(PHP_BINARY),
                        escapeshellarg(__FILE__),
                        implode(' ', array_map('escapeshellarg', $arguments))
                    ))
                );

                exec($command);
                exit(0);
            }

            logMessage("Worker stopped after {$totalProcessed} job(s)");
            exit(0);

        default:
            logMessage("Unknown action: {$action}");
            logMessage(
                'Usage: php worker.php [run|once|status|log|prune-log|schedule|run-task|flush]'
                . ' [--max-runtime=N] [--sleep=N] [--batch=N] [--stale-after=N] [--lock=PATH]'
                . ' [--schedule] [--watch-files] [--verbose]'
            );
            exit(1);
    }
} catch (Throwable $e) {
    logMessage('FATAL: ' . $e->getMessage());
    logMessage($e->getTraceAsString());
    exit(1);
}
