<?php

namespace RadioChatBox\ConsoleCommands;

use RadioChatBox\Services\Scheduler;
use RadioChatBox\Services\SettingsService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A feature worker whose unit of work is "run the due periodic tasks in my group"
 * (see {@see Scheduler}). Tracker / stats / maintenance are one-liners over this;
 * only the group (and command name / lock) differ.
 */
abstract class AbstractScheduledWorker extends AbstractLoopWorker
{
    private ?Scheduler $scheduler = null;

    /** The Scheduler task group this worker runs (Scheduler::GROUP_*). */
    abstract protected function taskGroup(): string;

    protected function prepare(InputInterface $input, OutputInterface $output): void
    {
        $this->scheduler = new Scheduler(new SettingsService());
    }

    protected function workUnit(OutputInterface $output): void
    {
        foreach ($this->scheduler->runDue(fn () => $this->heartbeat(), 10, $this->taskGroup()) as $result) {
            $this->logTaskResult($output, $result);
        }
    }
}
