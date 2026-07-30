<?php

namespace RadioChatBox\ConsoleCommands;

use RadioChatBox\JobQueue;
use RadioChatBox\Services\BotService;
use RadioChatBox\Services\Scheduler;
use RadioChatBox\Services\SettingsService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bot:start` — the OPTIONAL fake-user bot worker: process the delayed jobs that
 * produce automatic LLM replies, plus the bot-domain housekeeping (provider-balance
 * snapshots, LLM-log pruning). Spawned by the orchestrator only when the bots
 * feature is enabled (`bot_replies_enabled`).
 *
 * Its lock is named 'worker' — the same file the admin dashboard health check reads.
 */
class BotWorker extends AbstractLoopWorker
{
    private SettingsService $settings;
    private JobQueue $queue;
    private BotService $bot;
    private Scheduler $scheduler;
    private int $batchSize = 20;
    private bool $verbose = false;
    private int $totalProcessed = 0;

    protected function commandName(): string
    {
        return 'bot:start';
    }

    protected function commandDescription(): string
    {
        return 'Process bot reply/deliver jobs (LLM auto-replies) and bot housekeeping';
    }

    protected function getJobName(): string
    {
        return 'worker';
    }

    protected function configureExtra(): void
    {
        $this->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Max jobs to claim per batch', '20');
    }

    protected function prepare(InputInterface $input, OutputInterface $output): void
    {
        $this->settings  = new SettingsService();
        $this->queue     = new JobQueue();
        $this->bot       = new BotService($this->settings, $this->queue);
        $this->scheduler = new Scheduler($this->settings);
        $this->batchSize = max(1, (int) $input->getOption('batch'));
        $this->verbose   = (bool) $input->getOption('verbose') || $output->isVerbose();
    }

    protected function onSettingsChanged(OutputInterface $output): void
    {
        // Settings can be adopted in place: rebuild the objects derived from them.
        $this->bot->refreshSettings();
    }

    protected function workUnit(OutputInterface $output): void
    {
        $this->processBatch($output);

        // Bot-domain housekeeping (balance snapshot, prune log) — only meaningful
        // while the bots feature runs, so it lives with the bot worker.
        foreach ($this->scheduler->runDue(fn () => $this->heartbeat(), 2, Scheduler::GROUP_BOT) as $result) {
            $this->logTaskResult($output, $result);
        }
    }

    /**
     * Claim and run one batch of due reply/deliver jobs. The heartbeat is refreshed
     * after every job (one LLM call can block for its whole timeout, so a batch
     * would otherwise look wedged).
     */
    private function processBatch(OutputInterface $output): void
    {
        foreach ($this->queue->claimDue($this->batchSize) as $job) {
            $this->heartbeat([
                'jobs_processed' => $this->totalProcessed,
                'current_job'    => $job['type'] . ' ' . $job['id'],
            ]);

            try {
                $result = match ($job['type']) {
                    BotService::JOB_REPLY   => $this->bot->processReplyJob($job['payload']),
                    BotService::JOB_DELIVER => $this->bot->processDeliverJob($job['payload']),
                    default                 => 'unknown job type: ' . $job['type'],
                };

                if ($this->verbose || !str_starts_with($result, 'skipped')) {
                    $this->log($output, "{$job['type']} {$job['id']}: {$result}");
                }
            } catch (\Throwable $e) {
                $result   = 'FAILED: ' . $e->getMessage();
                $requeued = $this->queue->retry($job);
                $this->log($output, sprintf(
                    '%s %s FAILED (attempt %d): %s%s',
                    $job['type'],
                    $job['id'],
                    $job['attempts'] + 1,
                    $e->getMessage(),
                    $requeued ? ' - retrying' : ' - giving up'
                ));
            }

            $this->totalProcessed++;
            $this->heartbeat([
                'jobs_processed' => $this->totalProcessed,
                'current_job'    => null,
                'last_job'       => $job['type'] . ': ' . mb_substr($result, 0, 120),
                'last_job_at'    => time(),
            ]);
        }
    }
}
