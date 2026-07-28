<?php

namespace RadioChatBox;

use Pramnos\Cache\Adapter\RedisAdapter;
use Pramnos\Cache\FlatCache;

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
 * As of Phase 8 this accessor also exposes the atomic counter capability
 * (increment/decrement/counter/swap) and the structured operations (hash/list/
 * expire/keys) — native Redis under the hood — so the recent-message history,
 * the kicked-session registry and the admin user-info cache go through the Cache
 * capability rather than raw Redis. Genuinely non-cache Redis (pub/sub — see
 * RadioChatBox\Broadcast; the job queue; the SSE blocking subscribe connection;
 * keyspace-pattern maintenance) uses the framework Redis ConnectionManager.
 */
final class Cache
{
    private static ?FlatCache $store = null;

    /**
     * The shared cache store (lazy). Redis-backed in production. Returns FlatCache
     * so the structured operations (hash/list/expire/keys) are available.
     */
    public static function store(): FlatCache
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
    public static function setStore(?FlatCache $store): void
    {
        self::$store = $store;
    }
}
