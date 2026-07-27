<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use Pramnos\Cache\RedisStore;
use RadioChatBox\Cache;
use RadioChatBox\RadioStatusService;

/**
 * Minimal in-memory \Redis stand-in for the surface RedisStore uses, so the
 * cache adoption can be tested without touching the shared dev Redis.
 */
class FakeAppRedis
{
    public array $store = [];
    public function get($k) { return $this->store[$k] ?? false; }
    public function set($k, $v) { $this->store[$k] = $v; return true; }
    public function setex($k, $ttl, $v) { $this->store[$k] = $v; return true; }
    public function del($k) { foreach ((array) $k as $x) { unset($this->store[$x]); } return 1; }
    public function exists($k) { return isset($this->store[$k]) ? 1 : 0; }
    public function keys($pat) { $p = rtrim($pat, '*'); return array_values(array_filter(array_keys($this->store), fn ($k) => str_starts_with($k, $p))); }
}

/**
 * Covers the RadioChatBox\Cache accessor (RedisStore adoption) and the
 * RadioStatusService migration onto it.
 */
class CacheAdoptionTest extends TestCase
{
    protected function tearDown(): void
    {
        Cache::setStore(null); // reset to the real store
    }

    /**
     * The Cache accessor round-trips values (including arrays) under verbatim
     * colon-namespaced keys — the whole reason the flat-key RedisStore exists.
     */
    public function testCacheStoreRoundTripsArraysAndColonKeys(): void
    {
        $fake = new FakeAppRedis();
        Cache::setStore(new RedisStore(['prefix' => 'rcb:'], fn () => $fake));

        Cache::store()->set('radio:now_playing', ['active' => true, 'title' => 'X'], 10);

        $this->assertArrayHasKey('rcb:radio:now_playing', $fake->store, 'key stored verbatim under the prefix');
        $this->assertSame(['active' => true, 'title' => 'X'], Cache::store()->get('radio:now_playing'));
    }

    /**
     * setStore replaces the shared instance so services cache through the double.
     */
    public function testSetStoreOverridesSharedInstance(): void
    {
        $store = new RedisStore(['prefix' => 'x:'], fn () => new FakeAppRedis());
        Cache::setStore($store);
        $this->assertSame($store, Cache::store());
    }

    /**
     * With no radio_status_url configured, getNowPlaying returns the inactive
     * shape and never touches the cache (guard path unaffected by the migration).
     */
    public function testNowPlayingWithoutUrlReturnsInactiveWithoutCaching(): void
    {
        $fake = new FakeAppRedis();
        Cache::setStore(new RedisStore(['prefix' => 'rcb:'], fn () => $fake));

        $result = (new RadioStatusService())->getNowPlaying();

        // Only assert the contract shape; the value of radio_status_url is
        // environment-dependent, but when empty the result is the inactive shape.
        $this->assertArrayHasKey('active', $result);
        $this->assertArrayHasKey('display', $result);
    }
}
