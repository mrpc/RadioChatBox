<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use RadioChatBox\DaemonSupervisor;
use RadioChatBox\Installation;

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
    // Several installations on one server
    // ------------------------------------------------------------------

    /**
     * The discriminator is the installation *directory*, not the database: two copies of
     * the code can use identically named databases, and when logs/ is not writable the
     * lock lands in the shared temp directory - where one installation's lock would keep
     * the other's worker from ever starting.
     */
    public function testTheIdentityIsTheInstallationDirectory(): void
    {
        Installation::reset();
        $id = Installation::id();

        // Readable label plus a hash of the absolute path, so two directories with the
        // same name (…/current on two release paths) still differ.
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_.-]+-[0-9a-f]{8}$/', $id);
        $this->assertStringStartsWith(basename(Installation::root()), $id);
        $this->assertSame($id, Installation::id(), 'it must not change between calls');
        $this->assertStringContainsString(Installation::root(), Installation::label());
    }

    public function testTheLockFilesCarryTheInstallationIdentity(): void
    {
        Installation::reset();

        foreach (['worker', 'daemon-supervisor'] as $name) {
            $this->assertStringContainsString(
                $name . '-' . Installation::id() . '.lock',
                \RadioChatBox\WorkerLock::defaultPath($name)
            );
        }
    }

    /**
     * Data is a different scope: two installations pointed at one database share
     * sessions and caches on purpose, so the Redis prefix stays keyed by database.
     */
    public function testTheDataScopeIsNotTheInstallationScope(): void
    {
        Installation::reset();

        $this->assertStringNotContainsString(
            Installation::id(),
            \Pramnos\Redis\ConnectionManager::getInstance()->prefix(),
            'changing the Redis prefix would orphan sessions, caches and history'
        );
    }

    public function testTheIdentityIsFilenameSafe(): void
    {
        Installation::reset();

        // Derived from a path, so it must survive being put in a filename.
        $this->assertSame(basename(\RadioChatBox\WorkerLock::defaultPath('worker')), basename(
            \RadioChatBox\WorkerLock::defaultPath('worker')
        ));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_.-]+$/', Installation::id());
    }

    public function testNothingHasToBeConfiguredForTheIdentity(): void
    {
        Installation::reset();
        $first = Installation::id();

        // The point of deriving it from the path: no setting to forget, and none that
        // travels with a copied directory and recreates the collision.
        Installation::reset();
        $this->assertSame($first, Installation::id());
        $this->assertStringContainsString(basename(Installation::root()), $first);
    }

    /**
     * The doubling the supervisor exists to prevent must not come from the supervisor.
     */
    public function testASecondSupervisorForTheSameInstallationIsRefused(): void
    {
        // Its own lock name, so this never fights a supervisor actually running here.
        $name = 'supervisor-test-' . bin2hex(random_bytes(3));
        $first = new DaemonSupervisor(null, null, null, null, null, $name);
        $second = new DaemonSupervisor(null, null, null, null, null, $name);

        try {
            $this->assertTrue($first->claim());
            $this->assertFalse($second->claim($heldBy), 'two supervisors would start two workers each');
            $this->assertStringContainsString('pid', (string) $heldBy);
        } finally {
            $lock = $first->ownLock()->getPath();
            $first->ownLock()->release();
            @unlink($lock);
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
