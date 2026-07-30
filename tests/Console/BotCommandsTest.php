<?php

namespace RadioChatBox\Tests\Console;

use PHPUnit\Framework\TestCase;
use RadioChatBox\Console\BotFlush;
use RadioChatBox\Console\BotSchedule;
use RadioChatBox\Console\BotStatus;
use RadioChatBox\Console\BotWorker;
use RadioChatBox\Installation;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Smoke coverage for the bot:* console commands that replaced the (untested)
 * root-level worker.php. Runs against the shared dev DB/Redis the test bootstrap
 * wires up; each command is driven through Symfony's CommandTester.
 */
class BotCommandsTest extends TestCase
{
    private function tester(Command $command): CommandTester
    {
        (new Application())->add($command);

        return new CommandTester($command);
    }

    /**
     * `bot:worker --once` processes the (empty) queue, reports it, exits cleanly,
     * and releases its lock afterwards — the one-shot path of the worker loop.
     */
    public function testWorkerOnceProcessesAndReleasesLock(): void
    {
        $tester = $this->tester(new BotWorker());
        $exit = $tester->execute(['--once' => true]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('Processed', $tester->getDisplay());

        // The lock is released (removed) on the way out, so a fresh WorkerLock at
        // the same path sees no live holder.
        $lock = new \Pramnos\Console\WorkerLock('worker', Installation::lockPath());
        $this->assertFalse($lock->isHeldByAnother(), 'the lock must be free after --once');
    }

    /**
     * `bot:status` renders the health report and exits 0 when no worker is running.
     */
    public function testStatusRendersReport(): void
    {
        $tester = $this->tester(new BotStatus());
        $exit = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exit);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Bot worker status', $display);
        $this->assertStringContainsString('Jobs queued', $display);
    }

    /**
     * `bot:schedule` lists the periodic tasks with the hint to run them.
     */
    public function testScheduleListsTasks(): void
    {
        $tester = $this->tester(new BotSchedule());
        $exit = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('bot:worker --schedule', $tester->getDisplay());
    }

    /**
     * `bot:flush` drops scheduled jobs and reports the count.
     */
    public function testFlushReportsCount(): void
    {
        $tester = $this->tester(new BotFlush());
        $exit = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('scheduled job(s)', $tester->getDisplay());
    }
}
