<?php

namespace RadioChatBox;

use Pramnos\Cache\Adapter\RedisAdapter;
use Pramnos\Cache\FlatCache;
use Pramnos\Redis\ConnectionManager;

/**
 * Application cache accessor.
 *
 * Exposes the framework's backend-agnostic flat-key PSR-16 {@see FlatCache}
 * configured for RadioChatBox: a Redis-backed adapter in production, sharing the
 * framework Redis {@see ConnectionManager}'s host/port/prefix (set up in
 * bootstrap/pramnos.php), keeping colon-namespaced keys verbatim (e.g.
 * "radio:now_playing"). Services cache through this instead of opening Redis
 * themselves, so cache access is uniform, swappable (any adapter) and
 * test-doubleable (inject an ArrayAdapter-backed FlatCache).
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
            $manager = ConnectionManager::getInstance();
            $prefix  = $manager->prefix();
            self::$store = new FlatCache(
                new RedisAdapter(
                    $manager->host(),
                    $manager->port(),
                    $manager->database(),
                    $manager->password(),
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
