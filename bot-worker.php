#!/usr/bin/env php
<?php
/**
 * Fake User Bot Worker
 *
 * Processes the delayed jobs that produce automatic LLM replies for fake users
 * in private messages (see src/BotService.php).
 *
 * Usage:
 *   php bot-worker.php run [--max-runtime=SECONDS] [--sleep=SECONDS] [--batch=N]
 *   php bot-worker.php once
 *   php bot-worker.php status [--stale-after=SECONDS]
 *   php bot-worker.php flush
 *
 * Common options: --lock=PATH, --stale-after=SECONDS, --verbose.
 *
 * Actions:
 *   run     - Loop, polling every --sleep seconds (default 1s) until --max-runtime
 *             is reached (0 = forever). Meant for systemd/supervisor or cron.
 *   once    - Process everything that is currently due, then exit. Delivery is
 *             then only as accurate as the cron interval.
 *   status  - Print worker health (pid, uptime, heartbeat age), queue size and
 *             configuration. Exit code: 0 healthy/idle, 2 worker looks wedged.
 *   flush   - Drop every scheduled job (cancels pending bot replies).
 *
 * `run` and `once` hold a single-instance lock with a heartbeat (src/WorkerLock.php,
 * plain PHP - no flock), so a second worker exits instead of doubling up and no
 * `flock(1)` wrapper is needed in the crontab. A lock left behind by a killed or
 * wedged worker is taken over automatically (dead pid, or no heartbeat for
 * --stale-after seconds), and `status` reports the heartbeat so a worker that is
 * alive but stuck is visible.
 *
 * Recommended: run it as a service so replies land on time.
 *
 *   # systemd (/etc/systemd/system/radiochatbox-bot.service)
 *   [Service]
 *   WorkingDirectory=/path/to/radiochatbox
 *   ExecStart=/usr/bin/php /path/to/radiochatbox/bot-worker.php run
 *   Restart=always
 *   RestartSec=5
 *   # Optional: let systemd restart a wedged worker by itself. The worker sends
 *   # sd_notify pings on every heartbeat when NOTIFY_SOCKET is set.
 *   #Type=notify
 *   #WatchdogSec=120
 *
 * Cron alternative - `run --max-runtime=55` polls for 55 of every 60 seconds, so
 * replies stay ~1s accurate with only a ~5s gap between runs:
 *   * * * * * cd /path/to/radiochatbox && php bot-worker.php run --max-runtime=55 >> logs/bot-worker.log 2>&1
 */

declare(strict_types=1);

// Ensure we're running from CLI
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script can only be run from command line');
}

require_once __DIR__ . '/vendor/autoload.php';

use RadioChatBox\BotService;
use RadioChatBox\JobQueue;
use RadioChatBox\LlmService;
use RadioChatBox\SettingsService;
use RadioChatBox\WorkerLock;

/**
 * Log message with timestamp
 */
function logMessage(string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
}

/**
 * Notify systemd, when running under `Type=notify`. A no-op otherwise, so the
 * worker behaves identically under cron or by hand.
 *
 * Abstract-namespace sockets (NOTIFY_SOCKET starting with '@') are not reachable
 * from PHP streams and are skipped.
 */
function sdNotify(string $state): void
{
    $socket = getenv('NOTIFY_SOCKET');

    if (!is_string($socket) || $socket === '' || $socket[0] === '@') {
        return;
    }

    $fp = @stream_socket_client('udg://' . $socket, $errno, $errstr, 1, STREAM_CLIENT_CONNECT);
    if ($fp === false) {
        return;
    }

    @fwrite($fp, $state);
    fclose($fp);
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
    int &$totalProcessed = 0
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
        sdNotify("WATCHDOG=1\n");
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
    $lock = new WorkerLock('bot-worker', (string) ($options['lock'] ?? ''), $staleAfter);

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

            // Non-zero so monitoring can alert on a wedged worker.
            exit($isWedged ? 2 : 0);

        case 'flush':
            $dropped = $queue->flush();
            logMessage("Dropped {$dropped} scheduled job(s)");
            exit(0);

        case 'once':
            $exitCode = acquireOrExplain($lock, $staleAfter);
            if ($exitCode !== null) {
                exit($exitCode);
            }

            sdNotify("READY=1\n");

            try {
                $bot = new BotService($settings, $queue);
                $processed = 0;
                processBatch($bot, $queue, (int) ($options['batch'] ?? 50), $verbose, $lock, $processed);
                logMessage("Processed {$processed} job(s)");
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
            $startedAt = time();
            $running = true;
            $totalProcessed = 0;

            // Graceful shutdown so systemd restarts / deploys don't cut a job
            // in half.
            if (function_exists('pcntl_signal')) {
                pcntl_async_signals(true);
                $stop = function (int $signal) use (&$running): void {
                    logMessage("Received signal {$signal}, finishing current batch...");
                    $running = false;
                };
                pcntl_signal(SIGTERM, $stop);
                pcntl_signal(SIGINT, $stop);
            }

            logMessage(sprintf(
                'Worker started (pid %d, sleep=%ds, batch=%d, max-runtime=%s, lock=%s)',
                getmypid(),
                $sleep,
                $batchSize,
                $maxRuntime > 0 ? $maxRuntime . 's' : 'unlimited',
                $lock->getPath()
            ));

            sdNotify("READY=1\n");

            try {
                while ($running) {
                    processBatch($bot, $queue, $batchSize, $verbose, $lock, $totalProcessed);

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
                    sdNotify("WATCHDOG=1\n");

                    sleep($sleep);
                }
            } finally {
                sdNotify("STOPPING=1\n");
                $lock->release();
            }

            logMessage("Worker stopped after {$totalProcessed} job(s)");
            exit(0);

        default:
            logMessage("Unknown action: {$action}");
            logMessage(
                'Usage: php bot-worker.php [run|once|status|flush]'
                . ' [--max-runtime=N] [--sleep=N] [--batch=N] [--stale-after=N] [--lock=PATH] [--verbose]'
            );
            exit(1);
    }
} catch (Throwable $e) {
    logMessage('FATAL: ' . $e->getMessage());
    logMessage($e->getTraceAsString());
    exit(1);
}
