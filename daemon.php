<?php

/**
 * Daemon supervisor: keeps the background workers running.
 *
 * Usage:
 *   php daemon.php run [--interval=SECONDS] [--dry-run]
 *   php daemon.php once [--dry-run]
 *   php daemon.php status
 *   php daemon.php stop
 *   php daemon.php restart
 *
 * This is the one process that needs supervising from outside (systemd, and nothing
 * else has to be). It reconciles what should be running against what is: starts a
 * missing worker, asks a wedged one to restart, and on a new deployment asks everything
 * to come back on the new code.
 *
 * Stopping is always a request - a `.stop` file the worker notices between jobs - so a
 * job is never cut in half. The next cycle finds the slot empty and fills it.
 *
 * See docs/BOT_REPLIES.md for the systemd unit.
 */

require_once __DIR__ . '/vendor/autoload.php';

use RadioChatBox\DaemonSupervisor;

/**
 * @param array<string,mixed> $row
 */
function logLine(string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
}

$action = $argv[1] ?? 'run';

$options = [];
foreach (array_slice($argv, 2) as $argument) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $argument, $matches) === 1) {
        $options[$matches[1]] = $matches[2] ?? true;
    }
}

$dryRun = isset($options['dry-run']);
$supervisor = new DaemonSupervisor();

switch ($action) {
    case 'status':
        $ownState = $supervisor->ownLock()->readState();
        $ownAge = $supervisor->ownLock()->heartbeatAge($ownState);
        logLine(sprintf(
            'installation %s | supervisor %s',
            $supervisor->label(),
            $ownState === null
                ? 'NOT RUNNING (nothing will restart the workers)'
                : sprintf('pid %s, heartbeat %s', $ownState['pid'] ?? '?', $ownAge === null ? 'unknown' : $ownAge . 's ago')
        ));

        foreach ($supervisor->status() as $row) {
            $state = $row['running']
                ? ($row['stale'] ? 'STUCK' : 'running')
                : ($row['stop_requested'] ? 'stopping' : 'STOPPED');

            logLine(sprintf(
                '%-12s %-9s pid %-8s heartbeat %-8s jobs %s',
                $row['daemon'],
                $state,
                $row['pid'] ?? '-',
                $row['heartbeat_age_seconds'] === null ? '-' : $row['heartbeat_age_seconds'] . 's ago',
                $row['jobs_processed'] ?? '-'
            ));
            logLine('             ' . $row['description']);
        }

        // Non-zero so monitoring can alert without parsing the text.
        $unhealthy = array_filter(
            $supervisor->status(),
            static fn (array $row): bool => !$row['running'] || $row['stale']
        );
        exit($unhealthy === [] ? 0 : 2);

    case 'stop':
        $supervisor->requestStopAll();
        logLine('Stop requested for every daemon - they exit after the job in hand.');
        exit(0);

    case 'restart':
        $supervisor->requestStopAll();
        logLine('Restart requested; the next cycle starts them again on the current code.');
        exit(0);

    case 'once':
        foreach ($supervisor->reconcile($dryRun) as $result) {
            logLine(sprintf('%s: %s - %s', $result['daemon'], $result['action'], $result['detail']));
        }
        exit(0);

    case 'run':
        $interval = max(1, (int) ($options['interval'] ?? DaemonSupervisor::DEFAULT_INTERVAL));
        $running = true;

        if (function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            $stop = static function (int $signal) use (&$running): void {
                logLine("Received signal {$signal} - the supervisor is stopping (workers keep running)");
                $running = false;
            };
            pcntl_signal(SIGTERM, $stop);
            pcntl_signal(SIGINT, $stop);
        }

        // One supervisor per installation: a second one would see the same empty slots
        // and start a second worker for each.
        if (!$dryRun && !$supervisor->claim($heldBy)) {
            logLine('Another supervisor is already running for installation ' . $supervisor->instance()
                . ' (' . $heldBy . ') - nothing to do.');
            exit(0);
        }

        logLine(sprintf(
            'Supervisor started (installation %s, pid %d, interval %ds%s), watching: %s',
            $supervisor->label(),
            getmypid(),
            $interval,
            $dryRun ? ', dry run' : '',
            implode(', ', array_keys($supervisor->daemons()))
        ));

        // Quiet by default: only say something when the picture changes, or the log
        // fills with "healthy" every ten seconds and nobody reads it.
        $lastDetail = [];

        while ($running) {
            if ($supervisor->deployChanged()) {
                logLine('New deployment detected - asking every daemon to restart on the new code');
                if (!$dryRun) {
                    $supervisor->requestStopAll();
                }
            }

            foreach ($supervisor->reconcile($dryRun) as $result) {
                $key = $result['daemon'] . ':' . $result['action'];

                if (($lastDetail[$result['daemon']] ?? '') !== $key || $result['action'] !== 'healthy') {
                    logLine(sprintf('%s: %s - %s', $result['daemon'], $result['action'], $result['detail']));
                }

                $lastDetail[$result['daemon']] = $key;
            }

            // Keeps the lock lease alive, and is what `daemon.php status` reads to say
            // whether anything is supervising the workers at all.
            if (!$dryRun) {
                $supervisor->ownLock()->heartbeat();
            }

            sleep($interval);
        }

        if (!$dryRun) {
            $supervisor->ownLock()->release();
        }

        logLine('Supervisor stopped.');
        exit(0);

    default:
        logLine('Usage: php daemon.php [run|once|status|stop|restart] [--interval=N] [--dry-run]');
        exit(1);
}
