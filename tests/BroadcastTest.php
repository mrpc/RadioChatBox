<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use Pramnos\Broadcasting\BroadcastingManager;
use Pramnos\Broadcasting\Drivers\DriverInterface;
use RadioChatBox\Broadcast;

/**
 * Covers the RadioChatBox\Broadcast event-bus seam introduced in Phase 8 Step 1,
 * through which every realtime publisher now routes (replacing the raw
 * Database::getRedis()->publish() calls).
 *
 * The seam must forward (channel, event, payload) verbatim to the configured
 * driver and pass channels UNPREFIXED — the driver applies the Redis prefix, so
 * that every channel (including the two publishers that were historically
 * emitted unprefixed) lands on the same prefixed channel the SSE edge subscribes
 * to (docs/pramnos-migration/06 §8.2). The default production manager must be
 * Redis-backed.
 */
class BroadcastTest extends TestCase
{
    /** Restore the default (Redis) manager so a test double never leaks. */
    protected function tearDown(): void
    {
        Broadcast::setManager(null);
    }

    /**
     * Broadcast::publish() forwards the channel, event and payload unchanged to
     * the active driver, and the channel is passed UNPREFIXED (the driver is what
     * prefixes it) — the contract that normalizes the formerly-unprefixed
     * publishers onto the subscribed channel.
     */
    public function testPublishForwardsUnprefixedChannelEventAndPayloadToDriver(): void
    {
        $recorder = new class implements DriverInterface {
            /** @var list<array{channel:string,event:string,payload:array<string,mixed>}> */
            public array $sent = [];
            public function broadcast(string $channel, string $event, array $payload): void
            {
                $this->sent[] = ['channel' => $channel, 'event' => $event, 'payload' => $payload];
            }
            public function name(): string
            {
                return 'recorder';
            }
        };

        $manager = new BroadcastingManager();
        $manager->addDriver($recorder);
        $manager->setDefault('recorder');
        Broadcast::setManager($manager);

        Broadcast::publish('chat:user_updates', 'user_kicked', ['username' => 'bob', 'type' => 'user_kicked']);

        $this->assertCount(1, $recorder->sent);
        $this->assertSame('chat:user_updates', $recorder->sent[0]['channel'], 'channel must be passed unprefixed');
        $this->assertSame('user_kicked', $recorder->sent[0]['event']);
        $this->assertSame(['username' => 'bob', 'type' => 'user_kicked'], $recorder->sent[0]['payload']);
    }

    /**
     * The default (non-injected) manager is Redis-backed, so production publishing
     * goes over Redis exactly as before the seam existed.
     */
    public function testDefaultManagerIsRedisBacked(): void
    {
        Broadcast::setManager(null);

        $manager = Broadcast::manager();
        $this->assertInstanceOf(BroadcastingManager::class, $manager);
        $this->assertContains('redis', $manager->getDriverNames());
    }

    /**
     * setManager(null) rebuilds the default manager, so a test double injected by
     * one test never bleeds into the next.
     */
    public function testSetManagerNullResetsToDefault(): void
    {
        $manager = new BroadcastingManager();
        Broadcast::setManager($manager);
        $this->assertSame($manager, Broadcast::manager());

        Broadcast::setManager(null);
        $this->assertNotSame($manager, Broadcast::manager());
        $this->assertContains('redis', Broadcast::manager()->getDriverNames());
    }
}
