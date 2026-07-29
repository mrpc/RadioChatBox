<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use Pramnos\Health\Checks\RedisConnectivityCheck;
use Pramnos\Health\HealthStatus;
use Pramnos\Redis\ConnectionManager;
use RadioChatBox\Config;
use RadioChatBox\Database;

/**
 * Integration test for Redis health reporting after the migration to the
 * framework-native check (Phase 8 / Phase C).
 *
 * RadioChatBox no longer ships its own RedisConnectivityCheck; it registers the
 * framework's Pramnos\Health\Checks\RedisConnectivityCheck, which pings through
 * the shared ConnectionManager. This verifies that check reports the app's
 * (reachable) dev Redis as up when the manager is configured exactly as the
 * bootstrap configures it.
 */
class RedisHealthCheckTest extends TestCase
{
    private function manager(): ConnectionManager
    {
        $redis = Config::get('redis');
        return new ConnectionManager([
            'host'         => $redis['host'],
            'port'         => $redis['port'],
            'prefix'       => \Pramnos\Redis\ConnectionManager::getInstance()->prefix(),
            'timeout'      => 0.5,
            'read_timeout' => 1,
        ]);
    }

    /**
     * The framework Redis check reports up (PONG) against the app's dev Redis.
     */
    public function testFrameworkCheckReportsUpAgainstAppRedis(): void
    {
        $result = (new RedisConnectivityCheck($this->manager()))->run();

        $this->assertSame('redis', $result->name);
        $this->assertSame(HealthStatus::Ok, $result->status, 'dev Redis should be reachable');
    }

    /**
     * A misconfigured manager (unreachable port) reports down — not fatal.
     */
    public function testReportsDownWhenUnreachable(): void
    {
        $bad = new ConnectionManager(['host' => '127.0.0.1', 'port' => 6399, 'timeout' => 0.2]);
        $result = (new RedisConnectivityCheck($bad))->run();

        $this->assertSame(HealthStatus::Down, $result->status);
    }
}
