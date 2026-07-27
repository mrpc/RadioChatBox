<?php

namespace RadioChatBox;

use Pramnos\Cache\RedisStore;

/**
 * Application cache accessor.
 *
 * Exposes the framework's flat-key PSR-16 {@see RedisStore} configured for
 * RadioChatBox: it reuses the app's existing Redis connection
 * (RadioChatBox\Database::getRedis()) and key prefix, and keeps the app's
 * colon-namespaced keys verbatim (e.g. "radio:now_playing"). Services should
 * cache through this instead of touching Database::getRedis() directly, so cache
 * access is uniform, swappable and test-doubleable.
 *
 * Non-cache Redis uses (pub/sub, the job queue, admin sessions, rate-limit
 * counters) intentionally stay on Database::getRedis() — they are state, not a
 * recomputable cache.
 */
final class Cache
{
    private static ?RedisStore $store = null;

    /**
     * The shared cache store (lazy). Backed by the app's Redis connection.
     */
    public static function store(): RedisStore
    {
        if (self::$store === null) {
            self::$store = new RedisStore(
                ['prefix' => Database::getRedisPrefix()],
                static fn (): \Redis => Database::getRedis()
            );
        }
        return self::$store;
    }

    /**
     * Override the store (test seam). Pass null to reset to the default.
     */
    public static function setStore(?RedisStore $store): void
    {
        self::$store = $store;
    }
}
