<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use RadioChatBox\Services\Scheduler;

/**
 * Guards the cron-fallback schedule (app/schedule.php) against the in-worker one.
 *
 * The in-worker Scheduler (src/Scheduler.php) is primary; app/schedule.php mirrors
 * the same jobs for hosts that run `schedule:run` from cron instead of the worker
 * daemon. If a task is added to Scheduler::TASKS but not to the fallback, the two
 * would silently drift and the cron path would skip that job — this fails loudly.
 */
class ScheduleFallbackTest extends TestCase
{
    public function testFallbackScheduleCoversEveryInWorkerTask(): void
    {
        $inWorker = array_keys(Scheduler::TASKS);
        sort($inWorker);

        $src = (string) file_get_contents(__DIR__ . '/../app/schedule.php');
        preg_match_all('/\$task\(\'([a-z_]+)\'\)/', $src, $m);
        $fallback = $m[1];
        sort($fallback);

        $this->assertNotEmpty($inWorker, 'no in-worker tasks found — wiring is wrong');
        $this->assertSame(
            $inWorker,
            $fallback,
            'app/schedule.php (cron fallback) and Scheduler::TASKS (in-worker) have drifted apart.'
        );
    }
}
