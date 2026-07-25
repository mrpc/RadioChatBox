<?php

namespace RadioChatBox;

use Redis;

/**
 * Minimal delayed job queue on top of Redis.
 *
 * The project has no framework queue, so this provides the "run this later"
 * primitive the bot needs: a sorted set holds job ids scored by their run-at
 * timestamp, and a hash holds the payloads. bot-worker.php claims due jobs.
 *
 *   <prefix>jobs:delayed  ZSET  jobId => runAt (unix seconds)
 *   <prefix>jobs:data     HASH  jobId => json payload
 *
 * Claiming is atomic per job: the worker only processes a job if its own ZREM
 * removed it, so multiple workers never run the same job twice.
 */
class JobQueue
{
    private const ZSET_KEY = 'jobs:delayed';
    private const HASH_KEY = 'jobs:data';

    /** Jobs that fail are retried up to this many times before being dropped. */
    public const MAX_ATTEMPTS = 3;

    private Redis $redis;
    private string $prefix;

    public function __construct()
    {
        $this->redis = Database::getRedis();
        $this->prefix = Database::getRedisPrefix();
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
        $jobId = bin2hex(random_bytes(12));
        $runAt = time() + max(0, $delaySeconds);

        $job = [
            'id' => $jobId,
            'type' => $type,
            'payload' => $payload,
            'attempts' => (int) ($payload['__attempts'] ?? 0),
            'created_at' => time(),
            'run_at' => $runAt,
        ];
        unset($job['payload']['__attempts']);

        $this->redis->hSet($this->prefixKey(self::HASH_KEY), $jobId, (string) json_encode($job, JSON_UNESCAPED_UNICODE));
        $this->redis->zAdd($this->prefixKey(self::ZSET_KEY), $runAt, $jobId);

        return $jobId;
    }

    /**
     * Claim jobs whose run-at time has passed.
     *
     * @return list<array{id:string,type:string,payload:array<string,mixed>,attempts:int,run_at:int}>
     */
    public function claimDue(int $limit = 20): array
    {
        $ids = $this->redis->zRangeByScore(
            $this->prefixKey(self::ZSET_KEY),
            '0',
            (string) time(),
            ['limit' => [0, max(1, $limit)]]
        );

        if (!is_array($ids) || empty($ids)) {
            return [];
        }

        $claimed = [];

        foreach ($ids as $jobId) {
            // Whoever's ZREM returns 1 owns the job.
            if ((int) $this->redis->zRem($this->prefixKey(self::ZSET_KEY), $jobId) !== 1) {
                continue;
            }

            $raw = $this->redis->hGet($this->prefixKey(self::HASH_KEY), $jobId);
            $this->redis->hDel($this->prefixKey(self::HASH_KEY), $jobId);

            if (!is_string($raw)) {
                continue;
            }

            $job = json_decode($raw, true);
            if (!is_array($job) || !isset($job['type'])) {
                continue;
            }

            $claimed[] = [
                'id' => (string) ($job['id'] ?? $jobId),
                'type' => (string) $job['type'],
                'payload' => is_array($job['payload'] ?? null) ? $job['payload'] : [],
                'attempts' => (int) ($job['attempts'] ?? 0),
                'run_at' => (int) ($job['run_at'] ?? time()),
            ];
        }

        return $claimed;
    }

    /**
     * Re-schedule a failed job with backoff. Returns false when the job has
     * exhausted its attempts and should be dropped.
     *
     * @param array{type:string,payload:array<string,mixed>,attempts:int} $job
     */
    public function retry(array $job): bool
    {
        $attempts = (int) $job['attempts'] + 1;

        if ($attempts >= self::MAX_ATTEMPTS) {
            return false;
        }

        $payload = $job['payload'];
        $payload['__attempts'] = $attempts;

        // 10s, 20s, ...
        $this->push((string) $job['type'], $payload, 10 * $attempts);

        return true;
    }

    /**
     * Number of jobs currently scheduled (including ones already due).
     */
    public function size(): int
    {
        return (int) $this->redis->zCard($this->prefixKey(self::ZSET_KEY));
    }

    /**
     * Seconds until the next job is due; null when the queue is empty and
     * 0 when work is already pending.
     */
    public function secondsUntilNext(): ?int
    {
        $next = $this->redis->zRange($this->prefixKey(self::ZSET_KEY), 0, 0, true);

        if (!is_array($next) || empty($next)) {
            return null;
        }

        $runAt = (int) reset($next);

        return max(0, $runAt - time());
    }

    /**
     * Remove every scheduled job. Used by the admin panel / tests.
     */
    public function flush(): int
    {
        $count = $this->size();
        $this->redis->del($this->prefixKey(self::ZSET_KEY));
        $this->redis->del($this->prefixKey(self::HASH_KEY));

        return $count;
    }

    private function prefixKey(string $key): string
    {
        return $this->prefix . $key;
    }
}
