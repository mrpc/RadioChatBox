<?php

namespace RadioChatBox\Services;

use Pramnos\Cache\FlatCache;
use RadioChatBox\Database;
use Pramnos\Database\Database as PramnosDatabase;

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
    private PramnosDatabase $db;

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
        $this->db = Database::getDb();
    }

    private function relatedCacheKey(string $username): string
    {
        // Bare key — RadioChatBox\Cache (FlatCache) applies the Redis prefix.
        return 'dm_blocks:related:' . strtolower($username);
    }

    private function invalidate(string ...$usernames): void
    {
        foreach ($usernames as $username) {
            FlatCache::default()->delete($this->relatedCacheKey($username));
        }
    }

    /**
     * Look up a registered user's id by username (NULL for guests).
     */
    private function resolveUserId(string $username): ?int
    {
        try {
            $id = $this->db->queryBuilder()
                ->from('users')
                ->where('username', '=', $username)
                ->value('id');
            return $id === null ? null : (int)$id;
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('BlockService::resolveUserId failed: ' . $e->getMessage(), 'radiochatbox');
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
            $qb = $this->db->queryBuilder()->from('dm_blocks');
            $qb->upsert(
                [
                    'blocker_username' => $blockerUsername,
                    'blocker_user_id'  => $blockerUserId,
                    'blocked_username' => $blockedUsername,
                    'blocked_user_id'  => $this->resolveUserId($blockedUsername),
                    'created_at'       => $qb->raw('NOW()'),
                    'expires_at'       => $expiresAt,
                ],
                ['blocker_username', 'blocked_username'],
                [
                    'blocker_user_id' => $qb->raw('EXCLUDED.blocker_user_id'),
                    'blocked_user_id' => $qb->raw('EXCLUDED.blocked_user_id'),
                    'created_at'      => $qb->raw('NOW()'),
                    'expires_at'      => $qb->raw('EXCLUDED.expires_at'),
                ]
            );

            $this->invalidate($blockerUsername, $blockedUsername);
            return true;
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('BlockService::blockUser failed: ' . $e->getMessage(), 'radiochatbox');
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
            $this->db->queryBuilder()
                ->from('dm_blocks')
                ->where('blocker_username', '=', $blockerUsername)
                ->where('blocked_username', '=', $blockedUsername)
                ->delete();

            $this->invalidate($blockerUsername, $blockedUsername);
            return true;
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('BlockService::unblockUser failed: ' . $e->getMessage(), 'radiochatbox');
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

        $cached = FlatCache::default()->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            // People this user blocked …
            $outgoing = $this->db->queryBuilder()
                ->from('dm_blocks')
                ->select(['LOWER(blocked_username) AS other'])
                ->whereRaw('LOWER(blocker_username) = LOWER(%s)', [$username])
                ->whereRaw('(expires_at IS NULL OR expires_at > NOW())');
            // … UNION people who blocked this user.
            $incoming = $this->db->queryBuilder()
                ->from('dm_blocks')
                ->select(['LOWER(blocker_username) AS other'])
                ->whereRaw('LOWER(blocked_username) = LOWER(%s)', [$username])
                ->whereRaw('(expires_at IS NULL OR expires_at > NOW())');

            $rows = $outgoing->union($incoming)->getAll();
            $related = array_column($rows, 'other');
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('BlockService::getRelatedUsernames failed: ' . $e->getMessage(), 'radiochatbox');
            return [];
        }

        FlatCache::default()->set($cacheKey, $related, self::CACHE_TTL);

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
            return $this->db->queryBuilder()
                ->from('dm_blocks')
                ->whereRaw('LOWER(blocker_username) = LOWER(%s)', [$blockerUsername])
                ->whereRaw('(expires_at IS NULL OR expires_at > NOW())')
                ->orderBy('created_at', 'desc')
                ->pluck('blocked_username');
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('BlockService::getBlockedUsers failed: ' . $e->getMessage(), 'radiochatbox');
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
            return $this->db->queryBuilder()
                ->from('dm_blocks')
                ->whereRaw('LOWER(blocker_username) = LOWER(%s)', [$blockerUsername])
                ->whereRaw('LOWER(blocked_username) = LOWER(%s)', [$blockedUsername])
                ->whereRaw('(expires_at IS NULL OR expires_at > NOW())')
                ->exists();
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('BlockService::hasBlocked failed: ' . $e->getMessage(), 'radiochatbox');
            return false;
        }
    }
}
