<?php

namespace RadioChatBox;

use PDO;

/**
 * Handles user-initiated DM blocking.
 *
 * Blocking is MUTUAL: a single row (blocker, blocked) prevents direct messages
 * in BOTH directions between the two users. Identity is keyed on username, with
 * an optional user_id snapshot for registered users (mirrors private_messages).
 *
 * A short-lived Redis cache of each user's "related" set (everyone they have a
 * block relationship with, in either direction) keeps the per-send check cheap,
 * following the same caching approach as ChatService::isNicknameBanned().
 */
class BlockService
{
    private \Redis $redis;
    private PDO $pdo;
    private string $prefix;

    /** Cache TTL for a user's related-set, in seconds. */
    private const CACHE_TTL = 300;

    /**
     * How long a block created by a GUEST (non-registered) blocker lasts before
     * auto-expiring. Registered blockers never expire (their username is
     * reserved, so the block stays meaningful). Guest nicknames are reusable, so
     * their blocks should not linger indefinitely.
     */
    private const GUEST_BLOCK_TTL_HOURS = 24;

    public function __construct()
    {
        $this->redis = Database::getRedis();
        $this->pdo = Database::getPDO();
        $this->prefix = Database::getRedisPrefix();
    }

    private function relatedCacheKey(string $username): string
    {
        return $this->prefix . 'dm_blocks:related:' . strtolower($username);
    }

    private function invalidate(string ...$usernames): void
    {
        foreach ($usernames as $username) {
            try {
                $this->redis->del($this->relatedCacheKey($username));
            } catch (\Exception $e) {
                // Non-fatal: cache will simply be recomputed on next read.
            }
        }
    }

    /**
     * Look up a registered user's id by username (NULL for guests).
     */
    private function resolveUserId(string $username): ?int
    {
        try {
            $stmt = $this->pdo->prepare('SELECT id FROM users WHERE username = :username');
            $stmt->execute(['username' => $username]);
            $id = $stmt->fetchColumn();
            return $id !== false ? (int)$id : null;
        } catch (\PDOException $e) {
            error_log('BlockService::resolveUserId failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Create a block. Idempotent (no error if it already exists).
     *
     * @param bool $forcePermanent When true, the block never expires regardless
     *        of whether the blocker is a guest. Used for admin/impersonation
     *        blocks (a fake user isn't a registered user but its block should
     *        stick).
     */
    public function blockUser(string $blockerUsername, string $blockedUsername, bool $forcePermanent = false): bool
    {
        $blockerUsername = trim($blockerUsername);
        $blockedUsername = trim($blockedUsername);

        if ($blockerUsername === '' || $blockedUsername === '') {
            throw new \InvalidArgumentException('Both usernames are required');
        }
        if (strtolower($blockerUsername) === strtolower($blockedUsername)) {
            throw new \InvalidArgumentException('You cannot block yourself');
        }

        $blockerUserId = $this->resolveUserId($blockerUsername);

        // Guest blockers get an expiring block; registered blockers (and forced
        // permanent admin/impersonation blocks) never expire.
        $expiresAt = (!$forcePermanent && $blockerUserId === null)
            ? (new \DateTimeImmutable('+' . self::GUEST_BLOCK_TTL_HOURS . ' hours'))->format('Y-m-d H:i:sP')
            : null;

        try {
            // ON CONFLICT DO UPDATE so re-blocking refreshes the expiry window and
            // the stored user_id snapshots.
            $stmt = $this->pdo->prepare(
                'INSERT INTO dm_blocks (blocker_username, blocker_user_id, blocked_username, blocked_user_id, created_at, expires_at)
                 VALUES (:blocker_username, :blocker_user_id, :blocked_username, :blocked_user_id, NOW(), :expires_at)
                 ON CONFLICT (blocker_username, blocked_username) DO UPDATE SET
                     blocker_user_id = EXCLUDED.blocker_user_id,
                     blocked_user_id = EXCLUDED.blocked_user_id,
                     created_at = NOW(),
                     expires_at = EXCLUDED.expires_at'
            );
            $stmt->execute([
                'blocker_username' => $blockerUsername,
                'blocker_user_id' => $blockerUserId,
                'blocked_username' => $blockedUsername,
                'blocked_user_id' => $this->resolveUserId($blockedUsername),
                'expires_at' => $expiresAt,
            ]);

            $this->invalidate($blockerUsername, $blockedUsername);
            return true;
        } catch (\PDOException $e) {
            error_log('BlockService::blockUser failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Remove a block created by $blockerUsername against $blockedUsername.
     */
    public function unblockUser(string $blockerUsername, string $blockedUsername): bool
    {
        $blockerUsername = trim($blockerUsername);
        $blockedUsername = trim($blockedUsername);

        if ($blockerUsername === '' || $blockedUsername === '') {
            throw new \InvalidArgumentException('Both usernames are required');
        }

        try {
            $stmt = $this->pdo->prepare(
                'DELETE FROM dm_blocks
                 WHERE blocker_username = :blocker_username AND blocked_username = :blocked_username'
            );
            $stmt->execute([
                'blocker_username' => $blockerUsername,
                'blocked_username' => $blockedUsername,
            ]);

            $this->invalidate($blockerUsername, $blockedUsername);
            return true;
        } catch (\PDOException $e) {
            error_log('BlockService::unblockUser failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Everyone $username has a block relationship with, in EITHER direction
     * (people they blocked + people who blocked them). Lowercased. Cached.
     *
     * @return string[]
     */
    public function getRelatedUsernames(string $username): array
    {
        $cacheKey = $this->relatedCacheKey($username);

        try {
            $cached = $this->redis->get($cacheKey);
            if ($cached !== false) {
                return json_decode($cached, true) ?: [];
            }
        } catch (\Exception $e) {
            // Fall through to DB.
        }

        try {
            $stmt = $this->pdo->prepare(
                'SELECT LOWER(blocked_username) AS other FROM dm_blocks
                     WHERE LOWER(blocker_username) = LOWER(:u) AND (expires_at IS NULL OR expires_at > NOW())
                 UNION
                 SELECT LOWER(blocker_username) AS other FROM dm_blocks
                     WHERE LOWER(blocked_username) = LOWER(:u) AND (expires_at IS NULL OR expires_at > NOW())'
            );
            $stmt->execute(['u' => $username]);
            $related = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (\PDOException $e) {
            error_log('BlockService::getRelatedUsernames failed: ' . $e->getMessage());
            return [];
        }

        try {
            $this->redis->setex($cacheKey, self::CACHE_TTL, json_encode($related));
        } catch (\Exception $e) {
            // Non-fatal.
        }

        return $related;
    }

    /**
     * True if a block exists between $a and $b in either direction.
     */
    public function isBlockedBetween(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }
        return in_array(strtolower($b), $this->getRelatedUsernames($a), true);
    }

    /**
     * Usernames that $blockerUsername has actively blocked (one direction),
     * for showing an unblock list in the UI.
     *
     * @return string[]
     */
    public function getBlockedUsers(string $blockerUsername): array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT blocked_username FROM dm_blocks
                 WHERE LOWER(blocker_username) = LOWER(:u) AND (expires_at IS NULL OR expires_at > NOW())
                 ORDER BY created_at DESC'
            );
            $stmt->execute(['u' => $blockerUsername]);
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (\PDOException $e) {
            error_log('BlockService::getBlockedUsers failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * True if $blockerUsername has actively blocked $blockedUsername (one direction).
     * Used to render the correct Block/Unblock button state.
     */
    public function hasBlocked(string $blockerUsername, string $blockedUsername): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM dm_blocks
                 WHERE LOWER(blocker_username) = LOWER(:blocker) AND LOWER(blocked_username) = LOWER(:blocked)
                   AND (expires_at IS NULL OR expires_at > NOW())
                 LIMIT 1'
            );
            $stmt->execute(['blocker' => $blockerUsername, 'blocked' => $blockedUsername]);
            return $stmt->fetchColumn() !== false;
        } catch (\PDOException $e) {
            error_log('BlockService::hasBlocked failed: ' . $e->getMessage());
            return false;
        }
    }
}
