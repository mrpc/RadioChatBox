<?php

namespace RadioChatBox\Health;

use Pramnos\Health\HealthCheck;
use Pramnos\Health\HealthCheckResult;
use RadioChatBox\Database;

/**
 * Health check for RadioChatBox's Redis connection.
 *
 * Redis is load-bearing here — it is the SSE pub/sub transport and the bot-reply
 * job bus, not just a cache — so an unreachable Redis is a hard "down". Registered
 * alongside the framework's built-in database/disk/memory checks so
 * `bin/rcb health:check` reports the full picture.
 */
final class RedisConnectivityCheck implements HealthCheck
{
    public function getName(): string
    {
        return 'redis';
    }

    public function run(): HealthCheckResult
    {
        try {
            $redis = Database::getRedis();
            $pong  = $redis->ping();
            // phpredis returns true or '+PONG' depending on version/mode.
            if ($pong === true || $pong === '+PONG' || $pong === 'PONG') {
                return HealthCheckResult::ok('redis', 'PONG');
            }
            return HealthCheckResult::down('redis', 'Unexpected PING reply');
        } catch (\Throwable $e) {
            return HealthCheckResult::down('redis', $e->getMessage());
        }
    }
}
