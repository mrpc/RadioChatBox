<?php

namespace RadioChatBox\Tests\Console;

use PHPUnit\Framework\TestCase;
use Pramnos\Console\WorkerLock;
use RadioChatBox\ConsoleCommands\BotFlush;
use RadioChatBox\ConsoleCommands\BotLog;
use RadioChatBox\ConsoleCommands\BotPruneLog;
use RadioChatBox\ConsoleCommands\BotRunTask;
use RadioChatBox\ConsoleCommands\BotSchedule;
use RadioChatBox\ConsoleCommands\BotStatus;
use RadioChatBox\ConsoleCommands\BotWorker;
use RadioChatBox\ConsoleCommands\MaintenanceWorker;
use RadioChatBox\ConsoleCommands\RadioChatBoxDaemons;
use RadioChatBox\ConsoleCommands\StatsWorker;
use RadioChatBox\ConsoleCommands\TrackerWorker;
use RadioChatBox\Installation;
use RadioChatBox\JobQueue;
use RadioChatBox\Services\SettingsService;
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

    /** tracker:start --once runs its group and releases its own lock. */
    public function testTrackerStartOnce(): void
    {
        $tester = $this->tester(new TrackerWorker());
        $this->assertSame(Command::SUCCESS, $tester->execute(['--once' => true]));
        $this->assertFalse(
            (new WorkerLock('tracker', Installation::lockPath('tracker')))->isHeldByAnother(),
            'the tracker lock must be free after --once'
        );
    }

    /** maintenance:start --once runs its group and releases its own lock. */
    public function testMaintenanceStartOnce(): void
    {
        $tester = $this->tester(new MaintenanceWorker());
        $this->assertSame(Command::SUCCESS, $tester->execute(['--once' => true]));
        $this->assertFalse(
            (new WorkerLock('maintenance', Installation::lockPath('maintenance')))->isHeldByAnother(),
            'the maintenance lock must be free after --once'
        );
    }

    /**
     * The daemon loop honours --max-runtime: it starts, ticks, and stops on its own
     * (covers the AbstractLoopWorker run loop, not just the --once path).
     */
    public function testWorkerLoopHonoursMaxRuntime(): void
    {
        $tester = $this->tester(new BotWorker());
        $exit = $tester->execute(['--max-runtime' => '1', '--sleep' => '1']);

        $this->assertSame(Command::SUCCESS, $exit);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('bot:start started', $display);
        $this->assertStringContainsString('bot:start stopped', $display);
    }

    /**
     * A worker refuses to start when a live instance already holds its lock, and
     * says so (covers explainBusyLock's "already running" path).
     */
    public function testWorkerRefusesWhenLockHeld(): void
    {
        // Hold the bot worker's lock from this process (a live, heartbeating holder).
        $held = new WorkerLock('worker', Installation::lockPath('worker'));
        $this->assertTrue($held->acquire(), 'test setup: must take the lock');

        try {
            $tester = $this->tester(new BotWorker());
            $exit = $tester->execute(['--once' => true]);

            $this->assertSame(Command::SUCCESS, $exit);
            $this->assertStringContainsString('Already running', $tester->getDisplay());
        } finally {
            $held->release();
            @unlink(Installation::lockPath('worker'));
        }
    }

    /**
     * bot:start --once claims and runs a queued job (covers processBatch's claim /
     * dispatch loop). An unknown job type exercises the dispatch without invoking
     * the LLM path.
     */
    public function testBotStartProcessesAQueuedJob(): void
    {
        $queue = new JobQueue();
        $queue->flush();
        $id = $queue->push('unknown_test_type', ['probe' => 1], 0);

        try {
            $tester = $this->tester(new BotWorker());
            $this->assertSame(Command::SUCCESS, $tester->execute(['--once' => true]));
            $this->assertStringContainsString('unknown job type', $tester->getDisplay());
            $this->assertStringContainsString($id, $tester->getDisplay());
        } finally {
            $queue->flush();
        }
    }

    /** bot:log renders the 24h summary and exits cleanly. */
    public function testBotLogRenders(): void
    {
        $tester = $this->tester(new BotLog());
        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('LLM calls', $tester->getDisplay());
    }

    /** bot:run-task runs a known task; an unknown task name fails with the list. */
    public function testBotRunTaskKnownAndUnknown(): void
    {
        $ok = $this->tester(new BotRunTask());
        $this->assertSame(Command::SUCCESS, $ok->execute(['task' => 'cleanup']));
        $this->assertStringContainsString('Task cleanup', $ok->getDisplay());

        $bad = $this->tester(new BotRunTask());
        $this->assertSame(Command::FAILURE, $bad->execute(['task' => 'no_such_task']));
        $this->assertStringContainsString('Unknown task', $bad->getDisplay());
    }

    /** bot:prune-log reports how many entries it dropped. */
    public function testBotPruneLog(): void
    {
        $tester = $this->tester(new BotPruneLog());
        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('LLM log entr', $tester->getDisplay());
    }

    /**
     * The orchestrator spawns only the workers a given feature configuration needs:
     * core (stats + maintenance) always, tracker only with a stream URL, bot only
     * when the bots feature is on.
     */
    public function testOrchestratorGatesWorkersByFeature(): void
    {
        $settings = new SettingsService();
        $prevBot = (string) $settings->get('bot_replies_enabled', 'false');
        $prevUrl = (string) $settings->get('radio_status_url', '');

        try {
            $settings->setMultiple(['bot_replies_enabled' => 'false', 'radio_status_url' => '']);
            $this->assertSame(['stats', 'maintenance'], $this->desiredWorkerIds(),
                'only the core workers when no optional feature is on');

            $settings->setMultiple([
                'bot_replies_enabled' => 'true',
                'radio_status_url'    => 'http://radio.example.com:8000/status-json.xsl',
            ]);
            $ids = $this->desiredWorkerIds();
            $this->assertContains('tracker', $ids, 'tracker runs once a stream URL is set');
            $this->assertContains('bot_worker', $ids, 'the bot worker runs when bots are enabled');
        } finally {
            $settings->setMultiple(['bot_replies_enabled' => $prevBot, 'radio_status_url' => $prevUrl]);
        }
    }

    /** @return list<string> the ids the orchestrator would supervise right now */
    private function desiredWorkerIds(): array
    {
        $method = new \ReflectionMethod(RadioChatBoxDaemons::class, 'buildDesiredProcesses');
        $procs = $method->invoke(new RadioChatBoxDaemons());

        return array_map(static fn (array $p): string => (string) $p['id'], $procs);
    }
}
