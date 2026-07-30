<?php

namespace RadioChatBox\Console;

use RadioChatBox\Services\Scheduler;
use RadioChatBox\Services\SettingsService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bot:schedule` — list the periodic tasks with their cadence and last run.
 */
class BotSchedule extends Command
{
    use FormatsDuration;

    protected function configure(): void
    {
        $this->setName('bot:schedule')
            ->setDescription('List the periodic tasks with their cadence and last run');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $scheduler = new Scheduler(new SettingsService());
        $state = $scheduler->state();
        $due   = $scheduler->dueTasks();

        $output->writeln('Scheduled tasks (run them with: bot:worker --schedule)');
        foreach ($scheduler->tasks() as $task => $meta) {
            $row = $state[$task] ?? null;
            $output->writeln(sprintf(
                '  %-22s every %-7s %-11s %s',
                $task,
                $this->formatDuration((int) $meta['every']),
                in_array($task, $due, true) ? 'DUE NOW' : '',
                $row === null ? 'never run' : 'last ' . $row['last_run_at'] . ' (' . $row['last_status'] . ')'
            ));
            $output->writeln('       ' . $meta['description']);
        }

        return Command::SUCCESS;
    }
}
