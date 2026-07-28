<?php

namespace RadioChatBox;

use Pramnos\Broadcasting\BroadcastingManager;
use Pramnos\Broadcasting\Drivers\RedisDriver;

/**
 * Application event-bus (broadcast / pub-sub) accessor.
 *
 * Exposes the framework's {@see BroadcastingManager} configured for RadioChatBox:
 * a Redis-backed driver in production, keyed with the app's Redis prefix and
 * publishing over the shared {@see Database::getRedis()} connection. Services and
 * controllers should broadcast realtime events through this instead of calling
 * Database::getRedis()->publish() directly, so the transport is uniform,
 * swappable (Redis today; Database/Pusher/Kafka later, by config) and
 * test-doubleable (inject a Null/Log/fake driver via {@see setManager()}).
 *
 * The driver applies the SAME prefix the SSE edges subscribe with
 * (Database::getRedisPrefix()), so every channel is prefixed consistently — this
 * is deliberately how the two historically-unprefixed publishers get normalized
 * (see docs/pramnos-migration/06 §8.2). Channels are passed UNPREFIXED here
 * (e.g. 'chat:updates'); the driver adds the prefix.
 *
 * The subscribe side lives in the SSE controllers, which build their own
 * RedisDriver on the dedicated blocking connection (Database::getRedisForSubscribe()).
 */
final class Broadcast
{
    private static ?BroadcastingManager $manager = null;

    /**
     * The shared broadcasting manager (lazy). Redis-backed in production.
     */
    public static function manager(): BroadcastingManager
    {
        if (self::$manager === null) {
            $manager = new BroadcastingManager();
            $manager->addDriver(new RedisDriver(
                ['prefix' => Database::getRedisPrefix()],
                static fn (): \Redis => Database::getRedis()
            ));
            $manager->setDefault('redis');
            self::$manager = $manager;
        }
        return self::$manager;
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
        self::$manager = $manager;
    }
}
