<?php

namespace RadioChatBox\ConsoleCommands;

use Pramnos\Console\CommandBase;
use Pramnos\Console\WorkerReloader;
use RadioChatBox\Installation;
use RadioChatBox\Services\SettingsService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Base for RadioChatBox's per-feature background workers.
 *
 * Each feature has its own single-instance worker (bot replies, radio tracking,
 * statistics, maintenance), supervised by the daemon orchestrator. They differ
 * only in the unit of work they do each tick; everything else — the single-instance
 * lock + heartbeat, the cooperative SIGTERM/SIGINT stop, the systemd watchdog, the
 * `.stop` sentinel, code/settings reload, and self-respawn without a supervisor —
 * is identical and lives here (built on the framework worker primitives that
 * {@see CommandBase} composes).
 *
 * A concrete worker supplies its command name, its lock name (so the workers never
 * share a lock), and its {@see workUnit()}.
 */
abstract class AbstractLoopWorker extends CommandBase
{
    private string $lockPathOverride = '';
    private int $staleAfterOverride = 0;

    /** The console command name, e.g. `bot:start`. */
    abstract protected function commandName(): string;

    /** One-line command description. */
    abstract protected function commandDescription(): string;

    /**
     * Build the services this worker needs (called once, before the loop). Read
     * any command-specific options here.
     */
    abstract protected function prepare(InputInterface $input, OutputInterface $output): void;

    /** Do one unit of work (a batch, the due tasks in a group, …). */
    abstract protected function workUnit(OutputInterface $output): void;

    /** Rebuild anything derived from settings after a live settings change. */
    protected function onSettingsChanged(OutputInterface $output): void
    {
    }

    /** Extra command-specific options. */
    protected function configureExtra(): void
    {
    }

    /**
     * Directories whose *.php this worker runs (WorkerReloader globs per dir,
     * non-recursive). The command + the services it drives.
     *
     * @return string[]
     */
    protected function watchedPaths(): array
    {
        return ['src', 'src/ConsoleCommands', 'src/Services', 'composer.lock'];
    }

    protected function configure(): void
    {
        $this->setName($this->commandName())
            ->setDescription($this->commandDescription())
            ->addOption('once', null, InputOption::VALUE_NONE, 'Process everything currently due, then exit')
            ->addOption('watch-files', null, InputOption::VALUE_NONE, 'Exit/respawn when watched code changes (dev)')
            ->addOption('max-runtime', null, InputOption::VALUE_REQUIRED, 'Stop after N seconds (0 = forever)', '0')
            ->addOption('sleep', null, InputOption::VALUE_REQUIRED, 'Seconds to sleep between ticks', '1')
            ->addOption('stale-after', null, InputOption::VALUE_REQUIRED, 'Heartbeat staleness before a lock is taken over', (string) \Pramnos\Console\WorkerLock::DEFAULT_STALE_AFTER)
            ->addOption('lock', null, InputOption::VALUE_REQUIRED, 'Explicit lock file path (default: per-installation)')
            ->addOption('respawned', null, InputOption::VALUE_REQUIRED, 'Internal: self-respawn attempt counter', '0');

        $this->configureExtra();
    }

    /** Lock path: per-installation, named for the worker so the workers never collide. */
    protected function getJobLockFilePath(): string
    {
        return $this->lockPathOverride !== ''
            ? $this->lockPathOverride
            : Installation::lockPath($this->getJobName());
    }

    protected function getLockStaleSeconds(): int
    {
        return $this->staleAfterOverride > 0 ? $this->staleAfterOverride : parent::getLockStaleSeconds();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->staleAfterOverride = max(10, (int) $input->getOption('stale-after'));
        $lock = (string) $input->getOption('lock');
        if ($lock !== '') {
            $this->lockPathOverride = $lock;
        }

        // Refuse to start when a live worker already holds this lock.
        $takenOverFrom = null;
        if (!$this->workerLock()->acquire($takenOverFrom)) {
            return $this->explainBusyLock($output);
        }
        if ($takenOverFrom !== null) {
            $this->log($output, "Took over the lock from {$takenOverFrom}");
        }

        try {
            $this->prepare($input, $output);
            $this->systemd()->ready();

            if ($input->getOption('once')) {
                $this->workUnit($output);
                return self::SUCCESS;
            }

            return $this->runLoop($input, $output);
        } finally {
            $this->endJob();   // release + remove the lock, notify systemd STOPPING
        }
    }

    private function runLoop(InputInterface $input, OutputInterface $output): int
    {
        $maxRuntime = max(0, (int) $input->getOption('max-runtime'));
        $sleep      = max(1, (int) $input->getOption('sleep'));
        $autoReload = (bool) $input->getOption('watch-files');

        $settings = new SettingsService();
        $reloader = new WorkerReloader(
            Installation::root(),
            $this->watchedPaths(),
            fn (): string => $settings->versionStamp()
        );
        $reloader->baseline();

        // Cooperative graceful stop (SIGTERM/SIGINT → finish the tick, then exit).
        $this->installStopSignals();

        $startedAt   = time();
        $codeChanged = false;

        $this->log($output, sprintf(
            '%s started (pid %d, sleep=%ds, max-runtime=%s, lock=%s)',
            $this->commandName(),
            getmypid(),
            $sleep,
            $maxRuntime > 0 ? $maxRuntime . 's' : 'unlimited',
            $this->getJobLockFilePath()
        ));

        while (!$this->shouldStop()) {
            $this->workUnit($output);

            if ($maxRuntime > 0 && (time() - $startedAt) >= $maxRuntime) {
                break;
            }

            // Heartbeat while idle too (keeps status meaningful, pings the systemd
            // watchdog); false ⇒ our lease was lost to a replacement worker.
            if (!$this->heartbeat()) {
                $this->log($output, 'Lock taken over by another worker (we stalled past --stale-after) - exiting');
                break;
            }

            if ($reloader->settingsChanged()) {
                $this->log($output, 'Settings changed - rebuilding from them');
                $this->onSettingsChanged($output);
            }

            if ($autoReload && $reloader->codeChanged()) {
                $codeChanged = true;
                break;
            }

            sleep($sleep);
        }

        if ($codeChanged) {
            return $this->handleCodeChange($input, $output, $startedAt);
        }

        $this->log($output, $this->commandName() . ' stopped');
        return self::SUCCESS;
    }

    /**
     * PHP cannot reload code into a running process, so a code change means a new
     * process: under a supervisor, exit and let it restart; otherwise respawn.
     */
    private function handleCodeChange(InputInterface $input, OutputInterface $output, int $startedAt): int
    {
        if (WorkerReloader::isSupervised()) {
            $this->log($output, 'Code changed on disk - exiting so the supervisor starts a fresh process');
            return self::SUCCESS;
        }

        $respawns = (int) $input->getOption('respawned');
        if ((time() - $startedAt) >= 60) {
            $respawns = 0;   // lived a healthy while → not a crash loop
        }
        if ($respawns >= 5) {
            $this->log($output, 'Code changed, but already respawned 5 times without staying up - stopping');
            return self::FAILURE;
        }

        $this->log($output, sprintf('Code changed on disk and nothing is supervising - restarting myself (attempt %d)', $respawns + 1));

        $args = [escapeshellarg(PHP_BINARY), escapeshellarg(ROOT . '/radiochatbox.php'), $this->commandName()];
        if ($input->getOption('watch-files')) {
            $args[] = '--watch-files';
        }
        $args[] = '--respawned=' . ($respawns + 1);

        // The delay lets this process finish releasing the lock first.
        @exec(sprintf('nohup sh -c %s > /dev/null 2>&1 &', escapeshellarg('sleep 3; exec ' . implode(' ', $args))));

        return self::SUCCESS;
    }

    /** Explain a lock held by another worker (running vs wedged); exit 0/2. */
    protected function explainBusyLock(OutputInterface $output): int
    {
        $lock  = $this->workerLock();
        $state = $lock->readState();
        $age   = $lock->heartbeatAge($state);
        $pid   = $state['pid'] ?? '?';

        if ($age !== null && $age > $this->getLockStaleSeconds()) {
            $this->log($output, sprintf('Another instance (pid %s) holds the lock but looks WEDGED (heartbeat %ds ago).', (string) $pid, $age));
            return 2;
        }

        $this->log($output, sprintf('Already running (pid %s, heartbeat %s ago) - nothing to do.', (string) $pid, $age === null ? 'unknown' : $age . 's'));
        return self::SUCCESS;
    }

    protected function logTaskResult(OutputInterface $output, array $result): void
    {
        $this->log($output, sprintf(
            'Task %s: %s in %dms%s',
            $result['task'],
            $result['status'],
            $result['duration_ms'],
            $result['error'] === null ? '' : ' - ' . $result['error']
        ));
    }

    protected function log(OutputInterface $output, string $message): void
    {
        $output->writeln('[' . date('Y-m-d H:i:s') . '] ' . $message);
    }
}
