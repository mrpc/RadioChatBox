<?php

namespace RadioChatBox\Console;

use Pramnos\Console\DaemonOrchestrator;
use RadioChatBox\Installation;

/**
 * RadioChatBox daemon orchestrator (migration Phase 3).
 *
 * Replaces the bespoke src/DaemonSupervisor by subclassing PramnosFramework's
 * DaemonOrchestrator, which provides the reconcile loop, crash respawn,
 * heartbeat-staleness restart, /proc dedup, flock singleton guard, graceful
 * `.stop` handling and git-HEAD redeploy restart.
 *
 * For this phase it supervises the EXISTING worker.php unchanged (spawned via a
 * raw shell command), so no worker internals change. A later phase can reshape
 * worker.php into a framework queue worker.
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
        return ROOT . '/worker.php';
    }

    protected function buildDesiredProcesses(): array
    {
        // The worker keeps its own JSON lock+heartbeat at this path; pointing the
        // orchestrator's lockFile at it means the graceful-stop sentinel
        // (<lockFile>.stop) is exactly the file worker.php already watches for.
        $lockFile = ROOT . '/logs/worker-' . Installation::id() . '.lock';

        return [
            [
                'id'              => 'bot_worker',
                'daemon'          => 'worker',
                'workerId'        => 'worker-1',
                'lockFile'        => $lockFile,
                // Supervise the existing worker verbatim (bot replies + scheduler).
                'shellCommand'    => escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(ROOT . '/worker.php') . ' run --schedule',
                // Health is process-liveness based for now (the worker's lock uses a
                // JSON heartbeat rather than the orchestrator's mtime convention).
                'requireLockFile' => false,
                'profile'         => 'bot replies + periodic scheduler',
            ],
        ];
    }
}
