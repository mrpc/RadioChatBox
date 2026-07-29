<?php

namespace RadioChatBox;

use Pramnos\Queue\DelayedQueue;

/**
 * Delayed job queue for the bot — the "run this later" primitive.
 *
 * This is a thin application client of the framework delayed-queue capability
 * ({@see DelayedQueue}). The driver + connection wiring lives in the framework:
 * {@see DelayedQueue::redis()} builds a Redis-backed queue for our namespace,
 * bound to the shared Redis ConnectionManager (its per-install prefix + pooled
 * connection). The app depends on the capability, not on Redis directly, and this
 * class keeps only its own policy (attempts, backoff, namespace guard).
 *
 * The Redis layout is unchanged from the previous direct implementation:
 *
 *   <prefix><namespace>:delayed  ZSET  jobId => runAt (unix seconds)
 *   <prefix><namespace>:data     HASH  jobId => json payload
 *
 * so jobs scheduled before the migration are still claimed after it. Claiming is
 * atomic per job (the framework driver only yields a job whose ZREM it won), so
 * multiple workers never run the same job twice. The public contract of this
 * class is unchanged.
 */
class JobQueue
{
    private const DEFAULT_NAMESPACE = 'jobs';

    /** Jobs that fail are retried up to this many times before being dropped. */
    public const MAX_ATTEMPTS = 3;

    private DelayedQueue $queue;
    private string $namespace;

    /**
     * @param string            $namespace Key namespace, so a separate queue (or a
     *                                     test) cannot claim or flush the live one
     * @param DelayedQueue|null $queue     Injected queue (test seam); defaults to a
     *                                     Redis-backed queue on the app connection
     */
    public function __construct(string $namespace = self::DEFAULT_NAMESPACE, ?DelayedQueue $queue = null)
    {
        $this->namespace = $namespace !== '' ? $namespace : self::DEFAULT_NAMESPACE;
        // The framework builds the Redis-backed queue for our namespace from the
        // shared ConnectionManager; this class keeps only the app policy below
        // (attempts, backoff, namespace guard).
        $this->queue = $queue ?? DelayedQueue::redis($this->namespace);
    }

    public function getNamespace(): string
    {
        return $this->namespace;
    }

    /**
     * Schedule a job to run after $delaySeconds.
     *
     * @param array<string,mixed> $payload
     *
     * @return string The job id
     */
    public function push(string $type, array $payload, int $delaySeconds = 0): string
    {
        return $this->queue->push($type, $payload, $delaySeconds);
    }

    /**
     * Claim jobs whose run-at time has passed.
     *
     * @return list<array{id:string,type:string,payload:array<string,mixed>,attempts:int,run_at:int}>
     */
    public function claimDue(int $limit = 20): array
    {
        return array_map(
            static fn ($job): array => $job->toArray(),
            $this->queue->claimDue($limit)
        );
    }

    /**
     * Re-schedule a failed job with backoff. Returns false when the job has
     * exhausted its attempts and should be dropped.
     *
     * @param array{type:string,payload:array<string,mixed>,attempts:int} $job
     */
    public function retry(array $job): bool
    {
        $reserved = new \Pramnos\Queue\ReservedJob(
            (string) ($job['id'] ?? ''),
            (string) $job['type'],
            is_array($job['payload'] ?? null) ? $job['payload'] : [],
            (int) ($job['attempts'] ?? 0),
            (int) ($job['run_at'] ?? time())
        );

        // 10s, 20s, ... backoff; dropped once attempts reach MAX_ATTEMPTS.
        return $this->queue->retry($reserved, self::MAX_ATTEMPTS, 10) !== null;
    }

    /**
     * Number of jobs currently scheduled (including ones already due).
     */
    public function size(): int
    {
        return $this->queue->size();
    }

    /**
     * Seconds until the next job is due; null when the queue is empty and
     * 0 when work is already pending.
     */
    public function secondsUntilNext(): ?int
    {
        return $this->queue->secondsUntilNext();
    }

    /**
     * Remove every scheduled job. Used by the admin panel / tests.
     */
    public function flush(): int
    {
        return $this->queue->flush();
    }
}
