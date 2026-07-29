<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use RadioChatBox\JobQueue;

/**
 * Covers RadioChatBox\JobQueue after it became a thin client of the framework
 * delayed-queue capability (Pramnos\Queue\DelayedQueue + RedisQueueDriver) in
 * Phase 8 Step 3.
 *
 * These tests pin the public contract byte-for-byte — the same method surface,
 * the same claimed-job array shape, the same backoff/drop retry policy, and,
 * crucially, the same Redis keyspace (`<prefix><namespace>:delayed` /
 * `:data`) — so the refactor is provably behaviour-preserving and jobs scheduled
 * before the migration are still claimed after it.
 *
 * Runs against the shared dev Redis (the queue IS Redis state), using a random
 * namespace per test and flushing on teardown so live queues are never touched.
 */
class JobQueueTest extends TestCase
{
    private string $namespace;

    protected function setUp(): void
    {
        $this->namespace = 'jobqtest_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        try {
            (new JobQueue($this->namespace))->flush();
        } catch (\Throwable) {
            // best effort
        }
    }

    /**
     * A pushed, immediately-due job is returned by claimDue() with the exact
     * legacy array shape (id, type, payload, attempts, run_at) and attempts=0.
     */
    public function testPushThenClaimDueReturnsLegacyShape(): void
    {
        $queue = new JobQueue($this->namespace);

        $id = $queue->push('reply', ['message' => 'γειά', 'user' => 7], 0);
        $this->assertNotSame('', $id);

        $claimed = $queue->claimDue();
        $this->assertCount(1, $claimed);

        $job = $claimed[0];
        $this->assertSame(['id', 'type', 'payload', 'attempts', 'run_at'], array_keys($job));
        $this->assertSame($id, $job['id']);
        $this->assertSame('reply', $job['type']);
        $this->assertSame(['message' => 'γειά', 'user' => 7], $job['payload']);
        $this->assertSame(0, $job['attempts']);
        $this->assertIsInt($job['run_at']);
    }

    /**
     * A delayed job is not claimed before it is due, and claiming is
     * one-shot — a second claimDue() after the job is taken returns nothing.
     */
    public function testDelayedJobNotClaimedUntilDueAndClaimIsOneShot(): void
    {
        $queue = new JobQueue($this->namespace);

        $queue->push('reply', ['x' => 1], 3600); // far future
        $this->assertCount(0, $queue->claimDue(), 'future job must not be due yet');

        $queue->push('reply', ['x' => 2], 0);
        $this->assertCount(1, $queue->claimDue(), 'due job claimed once');
        $this->assertCount(0, $queue->claimDue(), 'already-claimed job not returned again');
    }

    /**
     * retry() re-schedules a job under the attempt ceiling (returns true) and
     * drops it once attempts reach MAX_ATTEMPTS (returns false).
     */
    public function testRetryReschedulesThenDropsAtMaxAttempts(): void
    {
        $queue = new JobQueue($this->namespace);

        // attempts 1 -> rescheduled
        $this->assertTrue($queue->retry([
            'id' => 'a', 'type' => 'reply', 'payload' => ['k' => 'v'], 'attempts' => 1, 'run_at' => time(),
        ]));

        // attempts MAX_ATTEMPTS-1 -> incremented to MAX_ATTEMPTS -> dropped
        $this->assertFalse($queue->retry([
            'id' => 'b', 'type' => 'reply', 'payload' => [], 'attempts' => JobQueue::MAX_ATTEMPTS - 1, 'run_at' => time(),
        ]));
    }

    /**
     * A retried job carries the incremented attempt count when next claimed,
     * so the attempt budget is actually consumed across retries.
     */
    public function testRetriedJobKeepsIncrementedAttempts(): void
    {
        $queue = new JobQueue($this->namespace);

        // Re-schedule with 0 backoff so it is immediately due for inspection.
        $reserved = new \Pramnos\Queue\ReservedJob('a', 'reply', ['k' => 'v'], 1, time());
        $newId = (new \Pramnos\Queue\DelayedQueue(
            new \Pramnos\Queue\Drivers\RedisQueueDriver(
                ['prefix' => \Pramnos\Redis\ConnectionManager::getInstance()->prefix(), 'namespace' => $this->namespace],
                static fn (): \Redis => \Pramnos\Redis\ConnectionManager::getInstance()->connection()
            )
        ))->retry($reserved, JobQueue::MAX_ATTEMPTS, 0);

        $this->assertNotNull($newId);
        $claimed = $queue->claimDue();
        $this->assertCount(1, $claimed);
        $this->assertSame(2, $claimed[0]['attempts'], 'attempts incremented on retry');
    }

    /**
     * size() counts scheduled jobs, secondsUntilNext() is 0 when work is due and
     * null when empty, and flush() removes everything and reports the count.
     */
    public function testSizeSecondsUntilNextAndFlush(): void
    {
        $queue = new JobQueue($this->namespace);

        $this->assertSame(0, $queue->size());
        $this->assertNull($queue->secondsUntilNext());

        $queue->push('reply', [], 0);
        $queue->push('reply', [], 120);

        $this->assertSame(2, $queue->size());
        $this->assertSame(0, $queue->secondsUntilNext());

        $this->assertSame(2, $queue->flush());
        $this->assertSame(0, $queue->size());
    }

    /**
     * The Redis keyspace is byte-identical to the pre-migration layout:
     * "<prefix><namespace>:delayed" (ZSET) and "<prefix><namespace>:data"
     * (HASH). This is the BC guarantee that in-flight jobs survive the cutover.
     */
    public function testRedisKeyspaceIsUnchanged(): void
    {
        $queue = new JobQueue($this->namespace);
        $id = $queue->push('reply', ['x' => 1], 0);

        $redis  = \Pramnos\Redis\ConnectionManager::getInstance()->connection();
        $prefix = \Pramnos\Redis\ConnectionManager::getInstance()->prefix();

        $this->assertGreaterThan(
            0,
            (float) $redis->zScore($prefix . $this->namespace . ':delayed', $id),
            'job id scored with its run-at timestamp in the delayed ZSET'
        );
        $this->assertNotFalse(
            $redis->hGet($prefix . $this->namespace . ':data', $id),
            'payload stored in the <prefix><namespace>:data hash'
        );
        $this->assertGreaterThan(
            0,
            (int) $redis->zCard($prefix . $this->namespace . ':delayed'),
            'job id scored in the <prefix><namespace>:delayed sorted set'
        );
    }

    /** getNamespace() reflects the configured namespace, defaulting to 'jobs'. */
    public function testGetNamespace(): void
    {
        $this->assertSame($this->namespace, (new JobQueue($this->namespace))->getNamespace());
        $this->assertSame('jobs', (new JobQueue())->getNamespace());
        $this->assertSame('jobs', (new JobQueue(''))->getNamespace());
    }
}
