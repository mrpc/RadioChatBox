<?php

namespace RadioChatBox\Tests\Console;

use PHPUnit\Framework\TestCase;
use RadioChatBox\ConsoleCommands\BotFlush;
use RadioChatBox\ConsoleCommands\BotSchedule;
use RadioChatBox\ConsoleCommands\BotStatus;
use RadioChatBox\ConsoleCommands\BotWorker;
use RadioChatBox\ConsoleCommands\StatsWorker;
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
     * `bot:start --once` runs one pass of the bot worker (empty queue + bot
     * housekeeping), exits cleanly, and releases its 'worker' lock afterwards.
     */
    public function testBotStartOnceRunsAndReleasesLock(): void
    {
        $tester = $this->tester(new BotWorker());
        $exit = $tester->execute(['--once' => true]);

        $this->assertSame(Command::SUCCESS, $exit);

        // The lock is released (removed) on the way out, so a fresh WorkerLock at
        // the same path sees no live holder.
        $lock = new \Pramnos\Console\WorkerLock('worker', Installation::lockPath('worker'));
        $this->assertFalse($lock->isHeldByAnother(), 'the bot lock must be free after --once');
    }

    /**
     * A scheduled feature worker (stats:start) runs its group's due tasks once and
     * releases its own distinct lock — it never touches the bot worker's lock.
     */
    public function testStatsStartOnceUsesItsOwnLock(): void
    {
        $tester = $this->tester(new StatsWorker());
        $exit = $tester->execute(['--once' => true]);

        $this->assertSame(Command::SUCCESS, $exit);

        $statsLock = new \Pramnos\Console\WorkerLock('stats', Installation::lockPath('stats'));
        $this->assertFalse($statsLock->isHeldByAnother(), 'the stats lock must be free after --once');
        $this->assertNotSame(Installation::lockPath('worker'), Installation::lockPath('stats'),
            'each feature worker must use a distinct lock');
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
        $this->assertStringContainsString('Scheduled tasks', $tester->getDisplay());
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
