<?php

namespace RadioChatBox\ConsoleCommands;

use Pramnos\Console\DaemonOrchestrator;
use RadioChatBox\Installation;

/**
 * RadioChatBox daemon orchestrator (migration Phase 3).
 *
 * Subclasses PramnosFramework's DaemonOrchestrator (which replaced the app's
 * former bespoke supervisor), providing the reconcile loop, crash respawn,
 * heartbeat-staleness restart, /proc dedup, flock singleton guard, graceful
 * `.stop` handling and git-HEAD redeploy restart.
 *
 * It supervises the `bot:worker` console command (bot replies + the periodic
 * scheduler), spawned via `radiochatbox.php bot:worker --schedule`.
 */
final class RadioChatBoxDaemons extends DaemonOrchestrator
{
    protected function getJobName(): string
    {
        return 'daemon_orchestrator';
    }

    protected function getDashboardTitle(): string
    {
        return ' RADIOCHATBOX DAEMON ORCHESTRATOR ';
    }

    protected function getEntryPoint(): string
    {
        // Fallback entry point; the worker below uses an explicit shellCommand.
        return ROOT . '/radiochatbox.php';
    }

    protected function buildDesiredProcesses(): array
    {
        // The worker keeps its own JSON lock+heartbeat at this path; pointing the
        // orchestrator's lockFile at it means the graceful-stop sentinel
        // (<lockFile>.stop) is exactly the file the bot:worker command already watches for.
        $lockFile = ROOT . '/logs/worker-' . Installation::id() . '.lock';

        return [
            [
                'id'              => 'bot_worker',
                'daemon'          => 'worker',
                'workerId'        => 'worker-1',
                'lockFile'        => $lockFile,
                // Supervise the bot worker command (bot replies + periodic scheduler).
                'shellCommand'    => escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(ROOT . '/radiochatbox.php') . ' bot:worker --schedule',
                // Health is process-liveness based for now (the worker's lock uses a
                // JSON heartbeat rather than the orchestrator's mtime convention).
                'requireLockFile' => false,
                'profile'         => 'bot replies + periodic scheduler',
            ],
        ];
    }
}
