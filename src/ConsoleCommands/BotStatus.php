<?php

namespace RadioChatBox\ConsoleCommands;

use Pramnos\Console\CommandBase;
use Pramnos\Console\WorkerLock;
use RadioChatBox\Installation;
use RadioChatBox\JobQueue;
use RadioChatBox\Services\LlmService;
use RadioChatBox\Services\Scheduler;
use RadioChatBox\Services\SettingsService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bot:status` — health of the background worker (pid, uptime, heartbeat age, its
 * current/last job), the reply configuration, the queue depth and the schedule.
 *
 * Reads the same per-installation lock the worker writes ({@see Installation::lockPath()}),
 * so it reports on a live worker. Exit code: 0 healthy/idle, 2 the worker looks wedged.
 */
class BotStatus extends CommandBase
{
    use FormatsDuration;

    private int $staleAfterOverride = 0;

    protected function configure(): void
    {
        $this->setName('bot:status')
            ->setDescription('Health of the background worker, queue and schedule')
            ->addOption('stale-after', null, InputOption::VALUE_REQUIRED, 'Heartbeat age treated as wedged', (string) WorkerLock::DEFAULT_STALE_AFTER);
    }

    protected function getJobName(): string
    {
        return 'worker';
    }

    protected function getJobLockFilePath(): string
    {
        return Installation::lockPath();
    }

    protected function getLockStaleSeconds(): int
    {
        return $this->staleAfterOverride > 0 ? $this->staleAfterOverride : WorkerLock::DEFAULT_STALE_AFTER;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->staleAfterOverride = max(10, (int) $input->getOption('stale-after'));
        $staleAfter = $this->getLockStaleSeconds();

        $settings = new SettingsService();
        $llm      = LlmService::fromSettings($settings);
        $queue    = new JobQueue();
        $lock     = $this->workerLock();

        $state        = $lock->readState();
        $isRunning    = $state !== null && $lock->isHeldByAnother();
        $heartbeatAge = $lock->heartbeatAge($state);
        $isWedged     = $lock->holderIsWedged();

        $output->writeln('Bot worker status');

        if ($isRunning) {
            $uptime = isset($state['started_at'])
                ? $this->formatDuration(max(0, time() - (int) $state['started_at']))
                : 'unknown';
            $output->writeln('  Worker          : ' . ($isWedged ? 'ALIVE but WEDGED (no progress)' : 'running'));
            $output->writeln('  ├─ pid          : ' . (string) ($state['pid'] ?? '?') . ' on ' . (string) ($state['host'] ?? '?'));
            $output->writeln('  ├─ uptime       : ' . $uptime);
            $output->writeln('  ├─ heartbeat    : ' . ($heartbeatAge === null ? 'unknown' : $this->formatDuration($heartbeatAge) . ' ago')
                . ($isWedged ? "  <-- older than {$staleAfter}s" : ''));
            $output->writeln('  ├─ jobs handled : ' . (string) ($state['jobs_processed'] ?? 0));
            if (!empty($state['current_job'])) {
                $output->writeln('  ├─ current job  : ' . (string) $state['current_job']);
            }
            if (!empty($state['last_job'])) {
                $output->writeln('  ├─ last job     : ' . (string) $state['last_job']);
            }
            $output->writeln('  └─ lock file    : ' . $lock->getPath());
        } elseif ($state !== null) {
            $crashed = (string) ($state['status'] ?? '') === 'running';
            $output->writeln('  Worker          : not running' . ($crashed ? ' (previous run died without releasing the lock)' : ''));
            $output->writeln('  └─ last run     : ' . (string) ($state['status'] ?? 'unknown')
                . ($heartbeatAge === null ? '' : ', ' . $this->formatDuration($heartbeatAge) . ' ago')
                . ', ' . (string) ($state['jobs_processed'] ?? 0) . ' job(s) handled');
        } else {
            $output->writeln('  Worker          : not running (never started on this host)');
        }

        $output->writeln('  Replies enabled : ' . ($settings->get('bot_replies_enabled', 'false') === 'true' ? 'yes' : 'no'));
        $output->writeln('  API key set     : ' . ($llm->isConfigured() ? 'yes' : 'NO (set it in Admin > Settings > Fake User Auto-Replies)'));
        $output->writeln('  Endpoint        : ' . $llm->getBaseUrl());
        $output->writeln('  Model           : ' . $llm->getModel());
        $output->writeln('  Msg limit/thread: ' . $settings->get('bot_max_messages_per_thread', 4));
        $output->writeln('  Typing sec/word : ' . $settings->get('bot_typing_seconds_per_word', 1.5));
        $output->writeln('  Jobs queued     : ' . $queue->size());
        $next = $queue->secondsUntilNext();
        $output->writeln('  Next job in     : ' . ($next === null ? 'n/a (empty)' : $next . 's'));

        $scheduleState = (new Scheduler($settings))->state();
        if ($scheduleState !== []) {
            $output->writeln('');
            $output->writeln('Scheduled tasks');
            foreach (Scheduler::TASKS as $task => $meta) {
                $row = $scheduleState[$task] ?? null;
                $output->writeln(sprintf(
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
        return $isWedged ? 2 : self::SUCCESS;
    }
}
