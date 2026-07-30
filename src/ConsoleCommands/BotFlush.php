<?php

namespace RadioChatBox\ConsoleCommands;

use RadioChatBox\JobQueue;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bot:flush` — drop every scheduled bot job (cancels pending replies).
 */
class BotFlush extends Command
{
    protected function configure(): void
    {
        $this->setName('bot:flush')
            ->setDescription('Drop every scheduled bot job (cancels pending replies)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dropped = (new JobQueue())->flush();
        $output->writeln("Dropped {$dropped} scheduled job(s)");

        return Command::SUCCESS;
    }
}
