<?php

namespace RadioChatBox\Tests;

use Pramnos\Cache\Adapter\RedisAdapter;
use Pramnos\Cache\FlatCache;
use PHPUnit\Framework\TestCase;
use RadioChatBox\Config;
use RadioChatBox\KickRegistry;

/**
 * Covers RadioChatBox\KickRegistry after Phase C moved it onto the framework
 * Cache capability (per-install, structured keys() enumeration) instead of raw
 * Redis.
 *
 * Injects a FlatCache on a RedisAdapter with a random per-test prefix (against
 * the shared dev Redis, since list() relies on SCAN, which the in-memory adapter
 * does not implement) and cleans up afterwards.
 */
class KickRegistryTest extends TestCase
{
    private FlatCache $cache;
    private KickRegistry $registry;

    protected function setUp(): void
    {
        $redis  = Config::get('redis');
        $prefix = 'kicktest_' . bin2hex(random_bytes(6)) . ':';
        $this->cache = new FlatCache(
            new RedisAdapter(
                (string) ($redis['host'] ?? '127.0.0.1'),
                (int) ($redis['port'] ?? 6379),
                0,
                $redis['password'] ?? null,
                $prefix
            ),
            $prefix
        );
        $this->registry = new KickRegistry($this->cache);
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->cache->keys('*') as $key) {
                $this->cache->delete($key);
            }
        } catch (\Throwable) {
            // best effort
        }
    }

    /**
     * kick() locks a session out, isKicked() reports it, forget() clears it.
     */
    public function testKickThenIsKickedThenForget(): void
    {
        $id = 'sess_' . bin2hex(random_bytes(4));
        $this->assertFalse($this->registry->isKicked($id), 'not kicked initially');

        $this->registry->kick($id, 'baduser', 'Kicked by admin');
        $this->assertTrue($this->registry->isKicked($id), 'kick locks the session out');

        $this->registry->forget($id);
        $this->assertFalse($this->registry->isKicked($id), 'forget clears the kick');
    }

    /** An empty session id is never considered kicked. */
    public function testEmptySessionIsNeverKicked(): void
    {
        $this->assertFalse($this->registry->isKicked(''));
    }

    /**
     * list() enumerates the kicked sessions with their metadata and a positive
     * remaining lock time derived from kicked_at + TTL.
     */
    public function testListReportsKickedSessions(): void
    {
        $this->assertSame([], $this->registry->list(), 'empty initially');

        $this->registry->kick('sA', 'alice', 'spam');
        $this->registry->kick('sB', 'bob');

        $list = $this->registry->list();
        $this->assertCount(2, $list);

        $bySession = [];
        foreach ($list as $row) {
            $bySession[$row['session_id']] = $row;
        }
        $this->assertSame('alice', $bySession['sA']['username']);
        $this->assertSame('spam', $bySession['sA']['reason']);
        $this->assertGreaterThan(0, $bySession['sA']['expires_in']);
        $this->assertLessThanOrEqual(KickRegistry::TTL, $bySession['sA']['expires_in']);

        $this->registry->forget('sA');
        $this->assertCount(1, $this->registry->list());
    }
}
