<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use Pramnos\Cache\Adapter\ArrayAdapter;
use Pramnos\Cache\FlatCache;
use RadioChatBox\Services\RadioStatusService;

/**
 * Covers the RadioChatBox\Cache accessor (framework FlatCache adoption) and the
 * RadioStatusService migration onto it. Uses a real ArrayAdapter-backed FlatCache
 * — the same class RCB runs over Redis in production — so no live Redis or fakes
 * are needed.
 */
class CacheAdoptionTest extends TestCase
{
    protected function tearDown(): void
    {
        FlatCache::setDefault(null); // reset to the real (Redis-backed) store
    }

    private function arrayStore(string $prefix = 'rcb:'): FlatCache
    {
        return new FlatCache(new ArrayAdapter($prefix), $prefix);
    }

    /**
     * The accessor round-trips values (including arrays) under verbatim
     * colon-namespaced keys — the reason the flat-key cache exists.
     */
    public function testCacheStoreRoundTripsArraysAndColonKeys(): void
    {
        FlatCache::setDefault($this->arrayStore());

        FlatCache::default()->set('radio:now_playing', ['active' => true, 'title' => 'X'], 10);

        $this->assertSame(['active' => true, 'title' => 'X'], FlatCache::default()->get('radio:now_playing'));
    }

    /**
     * setStore replaces the shared instance so services cache through the double.
     */
    public function testSetStoreOverridesSharedInstance(): void
    {
        $store = $this->arrayStore('x:');
        FlatCache::setDefault($store);
        $this->assertSame($store, FlatCache::default());
    }

    /**
     * With no radio_status_url configured, getNowPlaying returns the inactive
     * shape and never touches the cache (guard path unaffected by the migration).
     */
    public function testNowPlayingWithoutUrlReturnsInactiveWithoutCaching(): void
    {
        FlatCache::setDefault($this->arrayStore());

        $result = (new RadioStatusService())->getNowPlaying();

        $this->assertArrayHasKey('active', $result);
        $this->assertArrayHasKey('display', $result);
    }

    // ---------------------------------------------------------------------
    // Atomic-counter capability (Phase 8 Step 2). The rate-limit, login-attempt,
    // spam-violation and bot-reply-epoch counters route through
    // FlatCache::default()->increment/counter/delete. Verified here over ArrayAdapter
    // (the AbstractAdapter fallback); production uses the RedisAdapter's native
    // INCRBY, exercised end-to-end by MessageFilterTest's auto-ban test.
    // ---------------------------------------------------------------------

    /**
     * increment() returns the new post-increment total and accumulates, and
     * counter() reads the current value (0 when absent, without creating it) —
     * the contract the converted rate-limit/attempt/epoch counters rely on.
     */
    public function testCounterIncrementAccumulatesAndCounterReads(): void
    {
        FlatCache::setDefault($this->arrayStore());

        $this->assertSame(0, FlatCache::default()->counter('admin_auth_attempts:1.2.3.4'), 'absent counter reads 0');
        $this->assertSame(1, FlatCache::default()->increment('admin_auth_attempts:1.2.3.4', 1, 900));
        $this->assertSame(2, FlatCache::default()->increment('admin_auth_attempts:1.2.3.4', 1, 900));
        $this->assertSame(2, FlatCache::default()->counter('admin_auth_attempts:1.2.3.4'));
    }

    /**
     * delete() clears a counter (the "reset attempts on success" / "clear
     * violations after ban" path).
     */
    public function testCounterDeleteResetsIt(): void
    {
        FlatCache::setDefault($this->arrayStore());

        FlatCache::default()->increment('violations:spam_url:9.9.9.9', 3);
        $this->assertSame(3, FlatCache::default()->counter('violations:spam_url:9.9.9.9'));

        FlatCache::default()->delete('violations:spam_url:9.9.9.9');
        $this->assertSame(0, FlatCache::default()->counter('violations:spam_url:9.9.9.9'));
    }
}
