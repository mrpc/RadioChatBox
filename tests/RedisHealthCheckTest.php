<?php

namespace RadioChatBox\Tests;

use Mockery;
use PHPUnit\Framework\TestCase;
use Pramnos\Health\HealthStatus;
use RadioChatBox\Database;
use RadioChatBox\Health\RedisConnectivityCheck;

/**
 * Covers the RadioChatBox Redis health check registered into the framework's
 * HealthRegistry. Redis is load-bearing (SSE transport + job bus), so a reachable
 * Redis must report Ok and any failure must report Down with the error surfaced.
 */
class RedisHealthCheckTest extends TestCase
{
    protected function tearDown(): void
    {
        Database::reset();
        Mockery::close();
    }

    public function testNameIsRedis(): void
    {
        $this->assertSame('redis', (new RedisConnectivityCheck())->getName());
    }

    /**
     * A successful PING reports Ok. phpredis returns bool true (or '+PONG'); the
     * check accepts both.
     */
    public function testOkWhenPingSucceeds(): void
    {
        $redis = Mockery::mock(\Redis::class);
        $redis->shouldReceive('ping')->once()->andReturn(true);
        Database::setRedis($redis);

        $result = (new RedisConnectivityCheck())->run();

        $this->assertSame(HealthStatus::Ok, $result->status);
        $this->assertSame('PONG', $result->message);
    }

    /**
     * When Redis is unreachable the check reports Down and surfaces the error
     * message (rather than throwing).
     */
    public function testDownWhenRedisFails(): void
    {
        $redis = Mockery::mock(\Redis::class);
        $redis->shouldReceive('ping')->once()->andThrow(new \RedisException('connection refused'));
        Database::setRedis($redis);

        $result = (new RedisConnectivityCheck())->run();

        $this->assertSame(HealthStatus::Down, $result->status);
        $this->assertStringContainsString('connection refused', $result->message);
    }
}
