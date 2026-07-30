<?php

namespace RadioChatBox\ConsoleCommands;

use Pramnos\Console\DaemonOrchestrator;
use RadioChatBox\Installation;
use RadioChatBox\Services\SettingsService;

/**
 * RadioChatBox daemon orchestrator.
 *
 * Subclasses PramnosFramework's DaemonOrchestrator (which replaced the app's
 * former bespoke supervisor), providing the reconcile loop, crash respawn,
 * heartbeat-staleness restart, /proc dedup, flock singleton guard, graceful
 * `.stop` handling and git-HEAD redeploy restart.
 *
 * It supervises one worker per feature, spawning each only when its feature is
 * needed (read from settings every reconcile cycle, so toggling a feature starts
 * or gracefully stops its worker on the next pass):
 *
 *   stats:start        core — always
 *   maintenance:start  core — always
 *   tracker:start      when a radio status URL is configured
 *   bot:start          when the bots feature is enabled
 *
 * Each worker keeps its own per-installation JSON lock+heartbeat; pointing the
 * orchestrator's lockFile at that same path means the graceful-stop sentinel
 * (`<lockFile>.stop`) is exactly the file the worker's shouldStop() watches.
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
        // Fallback entry point; each worker below uses an explicit shellCommand.
        return ROOT . '/radiochatbox.php';
    }

    protected function buildDesiredProcesses(): array
    {
        $settings = new SettingsService();

        // Core workers — every installation runs these.
        $processes = [
            $this->worker('stats', 'stats:start', 'statistics snapshots + aggregations'),
            $this->worker('maintenance', 'maintenance:start', 'expired bans/blocks, stale sessions, old messages'),
        ];

        // Radio track polling only matters once a stream status URL is set.
        if (trim((string) $settings->get('radio_status_url', '')) !== '') {
            $processes[] = $this->worker('tracker', 'tracker:start', 'radio track polling + enrichment');
        }

        // The bots are an optional feature.
        if ($settings->get('bot_replies_enabled', 'false') === 'true') {
            // Lock name 'worker' — the one the admin dashboard health check reads.
            $processes[] = $this->worker('worker', 'bot:start', 'bot replies + LLM housekeeping', 'bot_worker');
        }

        return $processes;
    }

    /**
     * Build one desired-process entry for a worker command.
     *
     * @return array<string,mixed>
     */
    private function worker(string $lockName, string $command, string $profile, ?string $id = null): array
    {
        return [
            'id'              => $id ?? $lockName,
            'daemon'          => $lockName,
            'workerId'        => $lockName . '-1',
            'lockFile'        => Installation::lockPath($lockName),
            'shellCommand'    => escapeshellarg(PHP_BINARY) . ' '
                . escapeshellarg(ROOT . '/radiochatbox.php') . ' ' . $command,
            // Health is process-liveness based (the worker's lock uses a JSON
            // heartbeat rather than the orchestrator's mtime convention).
            'requireLockFile' => false,
            'profile'         => $profile,
        ];
    }
}
