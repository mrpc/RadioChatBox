<?php

namespace RadioChatBox\ConsoleCommands;

use RadioChatBox\Services\Scheduler;

/**
 * `maintenance:start` — periodic housekeeping: expired bans and DM blocks, stale
 * sessions, old messages. A core worker (always supervised).
 */
class MaintenanceWorker extends AbstractScheduledWorker
{
    protected function commandName(): string
    {
        return 'maintenance:start';
    }

    protected function commandDescription(): string
    {
        return 'Periodic housekeeping (expired bans/blocks, stale sessions, old messages)';
    }

    protected function getJobName(): string
    {
        return 'maintenance';
    }

    protected function taskGroup(): string
    {
        return Scheduler::GROUP_MAINTENANCE;
    }
}
