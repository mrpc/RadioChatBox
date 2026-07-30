<?php

namespace RadioChatBox\Console;

use RadioChatBox\Services\LlmLog;
use RadioChatBox\Services\SettingsService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bot:prune-log` — delete LLM log entries past the retention window.
 */
class BotPruneLog extends Command
{
    protected function configure(): void
    {
        $this->setName('bot:prune-log')
            ->setDescription('Delete LLM log entries past the retention window');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $deleted = (new LlmLog(new SettingsService()))->prune();
        $output->writeln("Deleted {$deleted} LLM log entr(ies) past the retention window");

        return Command::SUCCESS;
    }
}
