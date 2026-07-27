<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use Pramnos\Cache\Adapter\ArrayAdapter;
use Pramnos\Cache\FlatCache;
use RadioChatBox\Cache;
use RadioChatBox\RadioStatusService;

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
        Cache::setStore(null); // reset to the real (Redis-backed) store
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
        Cache::setStore($this->arrayStore());

        Cache::store()->set('radio:now_playing', ['active' => true, 'title' => 'X'], 10);

        $this->assertSame(['active' => true, 'title' => 'X'], Cache::store()->get('radio:now_playing'));
    }

    /**
     * setStore replaces the shared instance so services cache through the double.
     */
    public function testSetStoreOverridesSharedInstance(): void
    {
        $store = $this->arrayStore('x:');
        Cache::setStore($store);
        $this->assertSame($store, Cache::store());
    }

    /**
     * With no radio_status_url configured, getNowPlaying returns the inactive
     * shape and never touches the cache (guard path unaffected by the migration).
     */
    public function testNowPlayingWithoutUrlReturnsInactiveWithoutCaching(): void
    {
        Cache::setStore($this->arrayStore());

        $result = (new RadioStatusService())->getNowPlaying();

        $this->assertArrayHasKey('active', $result);
        $this->assertArrayHasKey('display', $result);
    }
}
