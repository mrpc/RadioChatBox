<?php

namespace RadioChatBox\ConsoleCommands;

/**
 * Human-readable durations for bot console output (shared by bot:status /
 * bot:schedule).
 */
trait FormatsDuration
{
    protected function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }
        if ($seconds < 3600) {
            return intdiv($seconds, 60) . 'm ' . ($seconds % 60) . 's';
        }

        return intdiv($seconds, 3600) . 'h ' . intdiv($seconds % 3600, 60) . 'm';
    }
}
