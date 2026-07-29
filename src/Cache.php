<?php

namespace RadioChatBox;

use Pramnos\Cache\FlatCache;

/**
 * Application cache accessor.
 *
 * A thin, app-named handle over the framework's default flat-key PSR-16 cache
 * ({@see FlatCache::default()}) — Redis-backed, bound to the shared framework
 * {@see \Pramnos\Redis\ConnectionManager} (host/port/per-install prefix set up in
 * bootstrap/pramnos.php), keeping colon-namespaced keys verbatim (e.g.
 * "radio:now_playing"). The framework owns the adapter wiring now; this class
 * just gives the app a stable name and a test seam.
 *
 * The store exposes the atomic counter capability (increment/decrement/counter/
 * swap) and the structured operations (hash/list/expire/keys) — native Redis
 * under the hood — so the recent-message history, the kicked-session registry and
 * the admin user-info cache go through the Cache capability rather than raw Redis.
 * Genuinely non-cache Redis (pub/sub — see RadioChatBox\Broadcast; the job queue;
 * the SSE blocking subscribe connection; keyspace maintenance) uses the framework
 * Redis ConnectionManager.
 */
final class Cache
{
    /**
     * The shared cache store (Redis-backed in production). Returns FlatCache so
     * the structured operations (hash/list/expire/keys) are available.
     */
    public static function store(): FlatCache
    {
        return FlatCache::default();
    }

    /**
     * Override the store (test seam). Pass null to reset to the default.
     */
    public static function setStore(?FlatCache $store): void
    {
        FlatCache::setDefault($store);
    }
}
