<?php

namespace RadioChatBox;

use Pramnos\Broadcasting\BroadcastingManager;

/**
 * Application event-bus (broadcast / pub-sub) accessor.
 *
 * A thin, app-named handle over the framework's default broadcasting manager
 * ({@see BroadcastingManager::instance()}) — Redis-backed, bound to the shared
 * framework {@see \Pramnos\Redis\ConnectionManager} (its per-install prefix and
 * pooled connection). The framework owns the driver wiring now; this class adds
 * the app's publish() convenience + a test seam.
 *
 * The driver applies the SAME prefix the SSE edges subscribe with, so every
 * channel is prefixed consistently. Channels are passed UNPREFIXED here (e.g.
 * 'chat:updates'); the driver adds the prefix — this is deliberately how the two
 * historically-unprefixed publishers get normalized (see docs/pramnos-migration/06
 * §8.2). The subscribe side lives in the SSE controllers, which build their own
 * RedisDriver on a dedicated blocking connection (ConnectionManager::newConnection()).
 */
final class Broadcast
{
    /**
     * The shared broadcasting manager (Redis-backed in production).
     */
    public static function manager(): BroadcastingManager
    {
        return BroadcastingManager::instance();
    }

    /**
     * Publish an event to a channel (channel UNPREFIXED — the driver prefixes it).
     *
     * @param array<string,mixed> $payload
     */
    public static function publish(string $channel, string $event, array $payload): void
    {
        self::manager()->broadcast($channel, $event, $payload);
    }

    /**
     * Override the manager (test seam). Pass null to reset to the default.
     */
    public static function setManager(?BroadcastingManager $manager): void
    {
        BroadcastingManager::setInstance($manager);
    }
}
