<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use Pramnos\Redis\ConnectionManager;

/**
 * Verifies the Redis ConnectionManager that bootstrap/pramnos.php installs for
 * RadioChatBox — the single connection source the Cache/Broadcast/JobQueue
 * capabilities, the SSE subscribe edges, the health check and the state repos
 * all share after the #14 migration off Database::getRedis*().
 *
 * These pin the behaviours the old Database Redis accessors used to guarantee:
 * a consistent per-install key prefix (radiochatbox:<db>:) and a live, reusable
 * shared connection.
 */
class ConnectionManagerBootstrapTest extends TestCase
{
    /**
     * The bootstrap-configured prefix is namespaced per install: it starts with
     * "radiochatbox:", ends with ":", and carries exactly the database segment in
     * between (radiochatbox:<db>:) — the layout every app keyspace depends on.
     */
    public function testPrefixIsPerInstallAndWellFormed(): void
    {
        $prefix = ConnectionManager::getInstance()->prefix();

        $this->assertStringStartsWith('radiochatbox:', $prefix);
        $this->assertStringEndsWith(':', $prefix);
        $this->assertMatchesRegularExpression('/^radiochatbox:[^:]+:$/', $prefix);
    }

    /**
     * The shared connection is opened once and reused (singleton), and it is a
     * live connection that answers PING — the guarantee the retired
     * Database::getRedis() used to provide to every caller.
     */
    public function testSharedConnectionIsReusedAndLive(): void
    {
        $manager = ConnectionManager::getInstance();
        $a = $manager->connection();
        $b = $manager->connection();

        $this->assertSame($a, $b, 'connection() should return the same shared instance');
        $this->assertInstanceOf(\Redis::class, $a);
        $this->assertTrue((bool) $a->ping());
    }

    /**
     * newConnection() hands out a fresh, dedicated connection each call (used for
     * the blocking SSE subscribe, which cannot share the pooled connection).
     */
    public function testNewConnectionIsDedicatedPerCall(): void
    {
        $manager = ConnectionManager::getInstance();
        $a = $manager->newConnection();
        $b = $manager->newConnection();

        $this->assertNotSame($a, $b, 'newConnection() should never return the shared/pooled connection');
        $this->assertNotSame($manager->connection(), $a);
    }
}
