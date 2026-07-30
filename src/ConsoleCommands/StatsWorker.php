<?php

namespace RadioChatBox\ConsoleCommands;

use RadioChatBox\Services\Scheduler;

/**
 * `stats:start` — record user/listener snapshots and roll up the hourly/daily/
 * weekly/monthly/yearly statistics aggregations. A core worker (always supervised).
 */
class StatsWorker extends AbstractScheduledWorker
{
    protected function commandName(): string
    {
        return 'stats:start';
    }

    protected function commandDescription(): string
    {
        return 'Record statistics snapshots and roll up the aggregations';
    }

    protected function getJobName(): string
    {
        return 'stats';
    }

    protected function taskGroup(): string
    {
        return Scheduler::GROUP_STATS;
    }
}
