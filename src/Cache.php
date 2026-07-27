<?php

namespace RadioChatBox;

use Pramnos\Cache\Adapter\RedisAdapter;
use Pramnos\Cache\FlatCache;
use Psr\SimpleCache\CacheInterface;

/**
 * Application cache accessor.
 *
 * Exposes the framework's backend-agnostic flat-key PSR-16 {@see FlatCache}
 * configured for RadioChatBox: a Redis-backed adapter in production, keyed with
 * the app's Redis prefix, keeping colon-namespaced keys verbatim (e.g.
 * "radio:now_playing"). Services should cache through this instead of touching
 * Database::getRedis() directly, so cache access is uniform, swappable (any
 * adapter) and test-doubleable (inject an ArrayAdapter-backed FlatCache).
 *
 * Non-cache Redis uses (pub/sub, the job queue, admin sessions, rate-limit
 * counters) intentionally stay on Database::getRedis() — they are state, not a
 * recomputable cache.
 */
final class Cache
{
    private static ?CacheInterface $store = null;

    /**
     * The shared cache store (lazy). Redis-backed in production.
     */
    public static function store(): CacheInterface
    {
        if (self::$store === null) {
            $redis  = (array) Config::get('redis');
            $prefix = Database::getRedisPrefix();
            self::$store = new FlatCache(
                new RedisAdapter(
                    (string) ($redis['host'] ?? '127.0.0.1'),
                    (int) ($redis['port'] ?? 6379),
                    0,
                    $redis['password'] ?? null,
                    $prefix
                ),
                $prefix
            );
        }
        return self::$store;
    }

    /**
     * Override the store (test seam). Pass null to reset to the default.
     */
    public static function setStore(?CacheInterface $store): void
    {
        self::$store = $store;
    }
}
