<?php

namespace RadioChatBox\ConsoleCommands;

use RadioChatBox\Services\Scheduler;

/**
 * `tracker:start` — poll the radio stream for the current track and enrich track
 * metadata. Spawned by the orchestrator only when a radio status URL is configured.
 */
class TrackerWorker extends AbstractScheduledWorker
{
    protected function commandName(): string
    {
        return 'tracker:start';
    }

    protected function commandDescription(): string
    {
        return 'Poll the radio stream for the current track and enrich track metadata';
    }

    protected function getJobName(): string
    {
        return 'tracker';
    }

    protected function taskGroup(): string
    {
        return Scheduler::GROUP_TRACKER;
    }
}
