<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use RadioChatBox\BlockService;
use RadioChatBox\Database;

class BlockServiceTest extends TestCase
{
    private BlockService $service;
    private \PDO $pdo;

    private string $blocker = '__test_blocker__';
    private string $blocked = '__test_blocked__';

    protected function setUp(): void
    {
        $this->service = new BlockService();
        $this->pdo = Database::getPDO();
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    private function cleanup(): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM dm_blocks WHERE blocker_username IN (?, ?) OR blocked_username IN (?, ?)'
        );
        $stmt->execute([$this->blocker, $this->blocked, $this->blocker, $this->blocked]);

        // Clear cached related-sets so each test sees fresh state.
        $redis = Database::getRedis();
        $prefix = Database::getRedisPrefix();
        $redis->del($prefix . 'dm_blocks:related:' . strtolower($this->blocker));
        $redis->del($prefix . 'dm_blocks:related:' . strtolower($this->blocked));
    }

    public function testBlockIsMutual(): void
    {
        $this->assertFalse($this->service->isBlockedBetween($this->blocker, $this->blocked));

        $this->assertTrue($this->service->blockUser($this->blocker, $this->blocked));

        // Blocked in both directions.
        $this->assertTrue($this->service->isBlockedBetween($this->blocker, $this->blocked));
        $this->assertTrue($this->service->isBlockedBetween($this->blocked, $this->blocker));
    }

    public function testUnblockRemovesTheBlock(): void
    {
        $this->service->blockUser($this->blocker, $this->blocked);
        $this->assertTrue($this->service->isBlockedBetween($this->blocker, $this->blocked));

        $this->assertTrue($this->service->unblockUser($this->blocker, $this->blocked));

        $this->assertFalse($this->service->isBlockedBetween($this->blocker, $this->blocked));
        $this->assertFalse($this->service->isBlockedBetween($this->blocked, $this->blocker));
    }

    public function testHasBlockedIsDirectional(): void
    {
        $this->service->blockUser($this->blocker, $this->blocked);

        // Only the blocker "has blocked" the other; not the reverse.
        $this->assertTrue($this->service->hasBlocked($this->blocker, $this->blocked));
        $this->assertFalse($this->service->hasBlocked($this->blocked, $this->blocker));
    }

    public function testGetBlockedUsersListsTarget(): void
    {
        $this->service->blockUser($this->blocker, $this->blocked);
        $list = $this->service->getBlockedUsers($this->blocker);
        $this->assertContains($this->blocked, $list);
    }

    public function testCannotBlockYourself(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->blockUser($this->blocker, $this->blocker);
    }

    public function testBlockIsCaseInsensitive(): void
    {
        $this->service->blockUser($this->blocker, $this->blocked);
        $this->assertTrue(
            $this->service->isBlockedBetween(strtoupper($this->blocker), strtoupper($this->blocked))
        );
    }

    public function testGuestBlockGetsExpiry(): void
    {
        // Test usernames are not registered users, so they are treated as guests
        // and the block must carry a future expires_at.
        $this->service->blockUser($this->blocker, $this->blocked);

        $stmt = $this->pdo->prepare(
            'SELECT expires_at FROM dm_blocks WHERE blocker_username = ? AND blocked_username = ?'
        );
        $stmt->execute([$this->blocker, $this->blocked]);
        $expiresAt = $stmt->fetchColumn();

        $this->assertNotNull($expiresAt, 'Guest-created block should have an expiry');
        $this->assertGreaterThan(time(), strtotime($expiresAt), 'Expiry should be in the future');
    }

    public function testExpiredBlockIsNotEnforced(): void
    {
        $this->service->blockUser($this->blocker, $this->blocked);

        // Force the block into the past.
        $stmt = $this->pdo->prepare(
            "UPDATE dm_blocks SET expires_at = NOW() - INTERVAL '1 hour'
             WHERE blocker_username = ? AND blocked_username = ?"
        );
        $stmt->execute([$this->blocker, $this->blocked]);

        // Bust the cache so the next check hits the DB.
        $redis = Database::getRedis();
        $prefix = Database::getRedisPrefix();
        $redis->del($prefix . 'dm_blocks:related:' . strtolower($this->blocker));

        $this->assertFalse($this->service->isBlockedBetween($this->blocker, $this->blocked));
    }
}
