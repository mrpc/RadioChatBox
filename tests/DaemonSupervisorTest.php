<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use RadioChatBox\DaemonSupervisor;

/**
 * Covers the supervisor: the one process that needs supervising from outside, so that
 * the workers do not.
 *
 * The decisions it makes are all off-by-one-mistake territory - restarting a healthy
 * worker, or leaving a wedged one alone - so they are pinned here with a fake clock and
 * a fake process table rather than real processes.
 */
class DaemonSupervisorTest extends TestCase
{
    private string $root;
    private string $lock;
    private int $now = 1_700_000_000;

    /** @var list<string> Commands the supervisor asked to start */
    private array $spawned = [];

    /** @var array<int,bool> Fake process table */
    private array $alive = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/supervisor_' . bin2hex(random_bytes(4));
        mkdir($this->root . '/logs', 0777, true);
        $this->lock = $this->root . '/logs/test-worker.lock';
        $this->spawned = [];
        $this->alive = [];
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/logs/*') ?: [] as $file) {
            unlink($file);
        }
        foreach (glob($this->root . '/.git/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        @rmdir($this->root . '/.git/refs/heads');
        @rmdir($this->root . '/.git/refs');
        @rmdir($this->root . '/.git');
        @rmdir($this->root . '/logs');
        @rmdir($this->root);
    }

    // ------------------------------------------------------------------
    // Reconciliation
    // ------------------------------------------------------------------

    public function testAMissingWorkerIsStarted(): void
    {
        $actions = $this->supervisor()->reconcile();

        $this->assertSame('started', $actions[0]['action']);
        $this->assertCount(1, $this->spawned);
        $this->assertStringContainsString('worker.php', $this->spawned[0]);
        $this->assertStringContainsString("'run'", $this->spawned[0]);
    }

    public function testAHealthyWorkerIsLeftAlone(): void
    {
        $this->writeLock(['pid' => 42, 'heartbeat_at' => $this->now - 3]);
        $this->alive[42] = true;

        $actions = $this->supervisor()->reconcile();

        $this->assertSame('healthy', $actions[0]['action']);
        $this->assertSame([], $this->spawned, 'restarting a working worker loses queued work for nothing');
    }

    public function testACrashedWorkerIsReplaced(): void
    {
        // Lock left behind by a process that died without releasing it.
        $this->writeLock(['pid' => 42, 'heartbeat_at' => $this->now - 3]);
        $this->alive[42] = false;

        $actions = $this->supervisor()->reconcile();

        $this->assertSame('started', $actions[0]['action']);
        $this->assertStringContainsString('42 is gone', $actions[0]['detail']);
    }

    /**
     * Alive but not progressing is the case a pid check calls healthy - which is how a
     * wedged worker goes unnoticed for hours.
     */
    public function testAWedgedWorkerIsAskedToRestartRatherThanKilled(): void
    {
        $this->writeLock(['pid' => 42, 'heartbeat_at' => $this->now - (DaemonSupervisor::HEARTBEAT_STALE_SECONDS + 5)]);
        $this->alive[42] = true;

        $supervisor = $this->supervisor();
        $actions = $supervisor->reconcile();

        $this->assertSame('stale', $actions[0]['action']);
        $this->assertSame([], $this->spawned, 'the slot is still occupied - a second worker would double up');
        $this->assertTrue($supervisor->stopRequested($this->lock), 'it asks, so the job in hand finishes');
    }

    public function testTheSlotIsRefilledOnlyOnceTheWorkerHasGone(): void
    {
        $this->writeLock(['pid' => 42, 'heartbeat_at' => $this->now - 3]);
        $this->alive[42] = true;

        $supervisor = $this->supervisor();
        $supervisor->requestStop($this->lock);

        // Still finishing: nothing new yet.
        $this->assertSame('waiting', $supervisor->reconcile()[0]['action']);
        $this->assertSame([], $this->spawned);

        // Gone: refill, and clear the request so it does not loop.
        $this->alive[42] = false;
        $this->assertSame('started', $supervisor->reconcile()[0]['action']);
        $this->assertCount(1, $this->spawned);
        $this->assertFalse($supervisor->stopRequested($this->lock));
    }

    public function testADryRunNeitherStartsNorAsksForAStop(): void
    {
        // Wedged worker: a real run would ask it to restart.
        $this->writeLock(['pid' => 42, 'heartbeat_at' => $this->now - (DaemonSupervisor::HEARTBEAT_STALE_SECONDS + 5)]);
        $this->alive[42] = true;

        $supervisor = $this->supervisor();
        $this->assertSame('stale', $supervisor->reconcile(true)[0]['action']);
        $this->assertFalse($supervisor->stopRequested($this->lock), 'a dry run must not ask for a stop');

        // Missing worker: a real run would start one.
        unlink($this->lock);
        $actions = $this->supervisor()->reconcile(true);

        $this->assertSame('would-start', $actions[0]['action']);
        $this->assertStringContainsString('worker.php', $actions[0]['detail']);
        $this->assertSame([], $this->spawned, 'nothing may actually be spawned');
    }

    // ------------------------------------------------------------------
    // Deployments
    // ------------------------------------------------------------------

    public function testTheDeployedCommitIsReadFromASymbolicHead(): void
    {
        $hash = str_repeat('a', 40);
        $this->writeGit('ref: refs/heads/main', ['refs/heads/main' => $hash]);

        $this->assertSame($hash, $this->supervisor()->deployHash());
    }

    public function testADetachedHeadIsReadDirectly(): void
    {
        $hash = str_repeat('b', 40);
        $this->writeGit($hash);

        $this->assertSame($hash, $this->supervisor()->deployHash());
    }

    public function testAPackedRefIsFound(): void
    {
        // After `git gc` the ref file is gone and only packed-refs has it.
        $hash = str_repeat('c', 40);
        $this->writeGit('ref: refs/heads/main');
        file_put_contents(
            $this->root . '/.git/packed-refs',
            "# pack-refs with: peeled fully-peeled sorted\n" . $hash . " refs/heads/main\n"
        );

        $this->assertSame($hash, $this->supervisor()->deployHash());
    }

    public function testANonCheckoutSimplyHasNoDeployHash(): void
    {
        // A release built by copying files: deployment detection is skipped rather
        // than guessed at.
        $this->assertSame('', $this->supervisor()->deployHash());
        $this->assertFalse($this->supervisor()->deployChanged());
    }

    public function testANewCommitIsDetectedOnceAndThenSettles(): void
    {
        $this->writeGit('ref: refs/heads/main', ['refs/heads/main' => str_repeat('a', 40)]);
        $supervisor = $this->supervisor();

        // The first check only records what is deployed now.
        $this->assertFalse($supervisor->deployChanged());

        file_put_contents($this->root . '/.git/refs/heads/main', str_repeat('d', 40));
        $this->now += DaemonSupervisor::DEPLOY_CHECK_SECONDS + 1;

        $this->assertTrue($supervisor->deployChanged());

        // And it does not keep firing on the same commit.
        $this->now += DaemonSupervisor::DEPLOY_CHECK_SECONDS + 1;
        $this->assertFalse($supervisor->deployChanged());
    }

    public function testTheCommitIsNotCheckedOnEveryCycle(): void
    {
        $this->writeGit('ref: refs/heads/main', ['refs/heads/main' => str_repeat('a', 40)]);
        $supervisor = $this->supervisor();
        $supervisor->deployChanged();

        file_put_contents($this->root . '/.git/refs/heads/main', str_repeat('e', 40));

        // Cycles run every few seconds; reading the file that often is pointless.
        $this->now += 5;
        $this->assertFalse($supervisor->deployChanged());
    }

    // ------------------------------------------------------------------
    // Status
    // ------------------------------------------------------------------

    public function testStatusReportsWhatMonitoringNeeds(): void
    {
        $this->writeLock(['pid' => 42, 'heartbeat_at' => $this->now - 7, 'jobs_processed' => 19]);
        $this->alive[42] = true;

        $row = $this->supervisor()->status()[0];

        $this->assertTrue($row['running']);
        $this->assertFalse($row['stale']);
        $this->assertSame(42, $row['pid']);
        $this->assertSame(7, $row['heartbeat_age_seconds']);
        $this->assertSame(19, $row['jobs_processed']);
        $this->assertFalse($row['stop_requested']);
    }

    public function testStatusReportsAStoppedWorker(): void
    {
        $row = $this->supervisor()->status()[0];

        $this->assertFalse($row['running']);
        $this->assertNull($row['pid']);
    }

    // ------------------------------------------------------------------
    // Several instances on one server
    // ------------------------------------------------------------------

    /**
     * Two installations share a server, so every name that could collide is scoped by
     * instance - otherwise one instance's supervisor manages the other's worker, or one
     * of them silently never runs.
     */
    public function testNamesAreScopedByInstance(): void
    {
        $first = \RadioChatBox\Database::getInstanceName();
        $firstWorkerLock = \RadioChatBox\WorkerLock::defaultPath('worker');
        $firstSupervisorLock = (new DaemonSupervisor())->ownLock()->getPath();

        putenv('APP_INSTANCE=another_site');

        try {
            $second = \RadioChatBox\Database::getInstanceName();

            $this->assertSame('another_site', $second);
            $this->assertNotSame($first, $second);
            $this->assertNotSame($firstWorkerLock, \RadioChatBox\WorkerLock::defaultPath('worker'));
            $this->assertNotSame($firstSupervisorLock, (new DaemonSupervisor())->ownLock()->getPath());
            $this->assertStringContainsString('another_site', \RadioChatBox\Database::getRedisPrefix());
        } finally {
            putenv('APP_INSTANCE');
        }
    }

    public function testAnInstanceNameIsSafeForAFilename(): void
    {
        putenv('APP_INSTANCE=weird/name with spaces');

        try {
            $name = \RadioChatBox\Database::getInstanceName();

            $this->assertSame('weird_name_with_spaces', $name);
            // The path has directories; the instance part must not add any.
            $this->assertStringNotContainsString('/', basename(\RadioChatBox\WorkerLock::defaultPath('worker')));
            $this->assertStringContainsString('weird_name_with_spaces', \RadioChatBox\WorkerLock::defaultPath('worker'));
        } finally {
            putenv('APP_INSTANCE');
        }
    }

    /**
     * The doubling the supervisor exists to prevent must not come from the supervisor.
     */
    public function testASecondSupervisorForTheSameInstanceIsRefused(): void
    {
        putenv('APP_INSTANCE=claim_test_' . bin2hex(random_bytes(3)));

        try {
            $first = new DaemonSupervisor();
            $second = new DaemonSupervisor();

            $this->assertTrue($first->claim());
            $this->assertFalse($second->claim($heldBy), 'two supervisors would start two workers each');
            $this->assertStringContainsString('pid', (string) $heldBy);

            $lock = $first->ownLock()->getPath();
            $first->ownLock()->release();
            @unlink($lock);
        } finally {
            putenv('APP_INSTANCE');
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function supervisor(): DaemonSupervisor
    {
        return new DaemonSupervisor(
            $this->root,
            ['bot_worker' => [
                'script' => 'worker.php',
                'args' => ['run', '--schedule'],
                'lock_path' => $this->lock,
                'description' => 'test worker',
            ]],
            function (string $command): int {
                $this->spawned[] = $command;

                return 999;
            },
            fn (int $pid): bool => $this->alive[$pid] ?? false,
            fn (): int => $this->now
        );
    }

    /**
     * @param array<string,mixed> $state
     */
    private function writeLock(array $state): void
    {
        file_put_contents($this->lock, (string) json_encode($state + ['status' => 'running']));
    }

    /**
     * @param array<string,string> $refs
     */
    private function writeGit(string $head, array $refs = []): void
    {
        mkdir($this->root . '/.git/refs/heads', 0777, true);
        file_put_contents($this->root . '/.git/HEAD', $head . "\n");

        foreach ($refs as $ref => $hash) {
            file_put_contents($this->root . '/.git/' . $ref, $hash . "\n");
        }
    }
}
