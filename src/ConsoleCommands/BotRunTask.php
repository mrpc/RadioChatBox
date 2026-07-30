<?php

namespace RadioChatBox\ConsoleCommands;

use RadioChatBox\Services\Scheduler;
use RadioChatBox\Services\SettingsService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bot:run-task <name>` — run one periodic maintenance task now, by name.
 */
class BotRunTask extends Command
{
    protected function configure(): void
    {
        $this->setName('bot:run-task')
            ->setDescription('Run one periodic task now, by name')
            ->addArgument('task', InputArgument::REQUIRED, 'Task name (' . implode(', ', array_keys(Scheduler::TASKS)) . ')');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = (string) $input->getArgument('task');

        if (!isset(Scheduler::TASKS[$name])) {
            $output->writeln('<error>Unknown task.</error> Known tasks: ' . implode(', ', array_keys(Scheduler::TASKS)));
            return Command::FAILURE;
        }

        $result = (new Scheduler(new SettingsService()))->run($name);
        $output->writeln(sprintf(
            'Task %s: %s in %dms%s',
            $result['task'],
            $result['status'],
            $result['duration_ms'],
            $result['error'] === null ? '' : ' - ' . $result['error']
        ));

        return $result['status'] === 'ok' ? Command::SUCCESS : Command::FAILURE;
    }
}
