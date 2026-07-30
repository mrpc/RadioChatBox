<?php

namespace RadioChatBox\ConsoleCommands;

use Pramnos\Console\CommandBase;
use Pramnos\Console\WorkerReloader;
use RadioChatBox\Installation;
use RadioChatBox\JobQueue;
use RadioChatBox\Services\BotService;
use RadioChatBox\Services\LlmAccount;
use RadioChatBox\Services\LlmPricing;
use RadioChatBox\Services\Scheduler;
use RadioChatBox\Services\SettingsService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bot:worker` — process the delayed jobs that produce automatic LLM replies for
 * fake users, and (opt-in) run the periodic maintenance schedule.
 *
 * The long-running loop of the retired worker.php, now a first-class framework
 * command: the single-instance lock + heartbeat, cooperative SIGTERM/SIGINT stop,
 * systemd watchdog and `.stop` sentinel all come from {@see CommandBase} (which
 * composes the framework worker primitives). Settings are adopted in place; code
 * changes trigger an exit-for-restart (or self-respawn without a supervisor).
 *
 * The lock lives at {@see Installation::lockPath()} — the same file the admin
 * dashboard health check reads — so `bot:status` and the dashboard stay accurate.
 */
class BotWorker extends CommandBase
{
    private string $lockPathOverride = '';
    private int $staleAfterOverride = 0;

    protected function configure(): void
    {
        $this->setName('bot:worker')
            ->setDescription('Process bot reply/deliver jobs (and, with --schedule, periodic tasks)')
            ->addOption('once', null, InputOption::VALUE_NONE, 'Process everything currently due, then exit')
            ->addOption('schedule', null, InputOption::VALUE_NONE, 'Also run periodic maintenance tasks that are due')
            ->addOption('watch-files', null, InputOption::VALUE_NONE, 'Exit/respawn when watched code changes (dev)')
            ->addOption('max-runtime', null, InputOption::VALUE_REQUIRED, 'Stop after N seconds (0 = forever)', '0')
            ->addOption('sleep', null, InputOption::VALUE_REQUIRED, 'Seconds to sleep between polls', '1')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Max jobs to claim per batch', '20')
            ->addOption('stale-after', null, InputOption::VALUE_REQUIRED, 'Heartbeat staleness before a lock is taken over', (string) \Pramnos\Console\WorkerLock::DEFAULT_STALE_AFTER)
            ->addOption('lock', null, InputOption::VALUE_REQUIRED, 'Explicit lock file path (default: per-installation)')
            ->addOption('respawned', null, InputOption::VALUE_REQUIRED, 'Internal: self-respawn attempt counter', '0');
    }

    protected function getJobName(): string
    {
        return 'worker';
    }

    /** The per-installation lock the dashboard health check also reads. */
    protected function getJobLockFilePath(): string
    {
        return $this->lockPathOverride !== '' ? $this->lockPathOverride : Installation::lockPath();
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

        $verbose  = (bool) $input->getOption('verbose') || $output->isVerbose();
        $settings = new SettingsService();
        $queue    = new JobQueue();

        // Refuse to start when another live worker holds the lock; explain whether
        // it looks healthy or wedged (the retired script's acquireOrExplain).
        $takenOverFrom = null;
        if (!$this->workerLock()->acquire($takenOverFrom)) {
            return $this->explainBusyLock($output);
        }
        if ($takenOverFrom !== null) {
            $this->log($output, "Took over the lock from {$takenOverFrom}");
        }

        try {
            $bot = new BotService($settings, $queue);

            if ($input->getOption('once')) {
                return $this->runOnce($input, $output, $settings, $queue, $bot, $verbose);
            }

            return $this->runLoop($input, $output, $settings, $queue, $bot, $verbose);
        } finally {
            $this->endJob();   // release + remove the lock, notify systemd STOPPING
        }
    }

    // ------------------------------------------------------------------

    private function runOnce(
        InputInterface $input,
        OutputInterface $output,
        SettingsService $settings,
        JobQueue $queue,
        BotService $bot,
        bool $verbose
    ): int {
        $this->systemd()->ready();

        $processed = 0;
        $this->processBatch($output, $bot, $queue, (int) $input->getOption('batch'), $verbose, $processed);
        $this->log($output, "Processed {$processed} job(s)");

        if ($input->getOption('schedule')) {
            foreach ((new Scheduler($settings))->runDue(fn () => $this->heartbeat()) as $result) {
                $this->logTaskResult($output, $result);
            }
        }

        return self::SUCCESS;
    }

    private function runLoop(
        InputInterface $input,
        OutputInterface $output,
        SettingsService $settings,
        JobQueue $queue,
        BotService $bot,
        bool $verbose
    ): int {
        $maxRuntime = max(0, (int) $input->getOption('max-runtime'));
        $sleep      = max(1, (int) $input->getOption('sleep'));
        $batchSize  = max(1, (int) $input->getOption('batch'));
        $withSchedule = (bool) $input->getOption('schedule');
        $autoReload   = (bool) $input->getOption('watch-files');

        $account   = new LlmAccount($settings);
        $scheduler = $withSchedule ? new Scheduler($settings) : null;
        // WorkerReloader globs *.php per directory (non-recursive), so list the
        // dirs whose code this worker runs — the command + the services it drives.
        $reloader  = new WorkerReloader(
            Installation::root(),
            ['src', 'src/ConsoleCommands', 'src/Services', 'composer.lock'],
            fn (): string => $settings->versionStamp()
        );
        $reloader->baseline();

        // Cooperative graceful stop (SIGTERM/SIGINT → finish the batch, then exit).
        $this->installStopSignals();
        $this->systemd()->ready();

        $startedAt      = time();
        $totalProcessed = 0;
        $codeChanged    = false;

        $this->log($output, sprintf(
            'Worker started (pid %d, sleep=%ds, batch=%d, max-runtime=%s, lock=%s)',
            getmypid(),
            $sleep,
            $batchSize,
            $maxRuntime > 0 ? $maxRuntime . 's' : 'unlimited',
            $this->getJobLockFilePath()
        ));

        while (!$this->shouldStop()) {
            $this->processBatch($output, $bot, $queue, $batchSize, $verbose, $totalProcessed);

            if ($maxRuntime > 0 && (time() - $startedAt) >= $maxRuntime) {
                break;
            }

            // Heartbeat while idle too (keeps `status` meaningful, pings the systemd
            // watchdog); false ⇒ our lease was lost to a replacement worker.
            if (!$this->heartbeat(['jobs_processed' => $totalProcessed, 'current_job' => null])) {
                $this->log($output, 'Lock taken over by another worker (we stalled past --stale-after) - exiting');
                break;
            }

            if ($reloader->settingsChanged()) {
                $this->log($output, 'Settings changed - rebuilding the LLM client from them');
                $bot->refreshSettings();
                $account = new LlmAccount($settings);
            }

            if ($autoReload && $reloader->codeChanged()) {
                $codeChanged = true;
                break;
            }

            if ($scheduler !== null) {
                foreach ($scheduler->runDue(fn () => $this->heartbeat()) as $result) {
                    $this->logTaskResult($output, $result);
                }
            } else {
                $recorded = $account->snapshot();
                if ($recorded !== null && $verbose) {
                    $this->log($output, 'Balance snapshot: ' . LlmPricing::format($recorded['total'], $recorded['currency']));
                }
            }

            sleep($sleep);
        }

        if ($codeChanged) {
            return $this->handleCodeChange($input, $output, $startedAt);
        }

        $this->log($output, "Worker stopped after {$totalProcessed} job(s)");
        return self::SUCCESS;
    }

    /**
     * Run one batch of due jobs; the heartbeat is refreshed after every job (one
     * LLM call can block for its whole timeout, so a batch would otherwise look
     * wedged). $totalProcessed is a running total, updated in place.
     */
    private function processBatch(
        OutputInterface $output,
        BotService $bot,
        JobQueue $queue,
        int $batchSize,
        bool $verbose,
        int &$totalProcessed
    ): void {
        foreach ($queue->claimDue($batchSize) as $job) {
            $this->heartbeat([
                'jobs_processed' => $totalProcessed,
                'current_job'    => $job['type'] . ' ' . $job['id'],
            ]);

            try {
                $result = match ($job['type']) {
                    BotService::JOB_REPLY   => $bot->processReplyJob($job['payload']),
                    BotService::JOB_DELIVER => $bot->processDeliverJob($job['payload']),
                    default                 => 'unknown job type: ' . $job['type'],
                };

                if ($verbose || !str_starts_with($result, 'skipped')) {
                    $this->log($output, "{$job['type']} {$job['id']}: {$result}");
                }
            } catch (\Throwable $e) {
                $result   = 'FAILED: ' . $e->getMessage();
                $requeued = $queue->retry($job);
                $this->log($output, sprintf(
                    '%s %s FAILED (attempt %d): %s%s',
                    $job['type'],
                    $job['id'],
                    $job['attempts'] + 1,
                    $e->getMessage(),
                    $requeued ? ' - retrying' : ' - giving up'
                ));
            }

            $totalProcessed++;
            $this->heartbeat([
                'jobs_processed' => $totalProcessed,
                'current_job'    => null,
                'last_job'       => $job['type'] . ': ' . mb_substr($result, 0, 120),
                'last_job_at'    => time(),
            ]);
        }
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

        $args = [escapeshellarg(PHP_BINARY), escapeshellarg(ROOT . '/radiochatbox.php'), 'bot:worker'];
        foreach (['schedule', 'watch-files'] as $flag) {
            if ($input->getOption($flag)) {
                $args[] = '--' . $flag;
            }
        }
        $args[] = '--respawned=' . ($respawns + 1);

        // The delay lets this process finish releasing the lock first.
        @exec(sprintf('nohup sh -c %s > /dev/null 2>&1 &', escapeshellarg('sleep 3; exec ' . implode(' ', $args))));

        return self::SUCCESS;
    }

    /** Explain a lock held by another worker (running vs wedged); exit code 0/2. */
    private function explainBusyLock(OutputInterface $output): int
    {
        $lock  = $this->workerLock();
        $state = $lock->readState();
        $age   = $lock->heartbeatAge($state);
        $pid   = $state['pid'] ?? '?';

        if ($age !== null && $age > $this->getLockStaleSeconds()) {
            $this->log($output, sprintf('Another worker (pid %s) holds the lock but looks WEDGED (heartbeat %ds ago).', (string) $pid, $age));
            return 2;
        }

        $this->log($output, sprintf('Another worker is already running (pid %s, heartbeat %s ago) - nothing to do.', (string) $pid, $age === null ? 'unknown' : $age . 's'));
        return self::SUCCESS;
    }

    private function logTaskResult(OutputInterface $output, array $result): void
    {
        $this->log($output, sprintf(
            'Task %s: %s in %dms%s',
            $result['task'],
            $result['status'],
            $result['duration_ms'],
            $result['error'] === null ? '' : ' - ' . $result['error']
        ));
    }

    private function log(OutputInterface $output, string $message): void
    {
        $output->writeln('[' . date('Y-m-d H:i:s') . '] ' . $message);
    }
}
