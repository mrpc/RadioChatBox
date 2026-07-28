<?php

namespace RadioChatBox;

use Redis;

/**
 * Owner of the `chat:active_users` Redis hash (identifier => JSON descriptor).
 *
 * This consolidates the previously scattered raw-Redis writes to the active-users
 * hash (in ChatService, FakeUserService and AdminModerationController) behind one
 * boundary, so no service hand-rolls the key or the JSON envelope (Phase 8 State
 * layer, mirroring {@see KickRegistry}).
 *
 * ⚠️ NOTE: this hash is currently **write-only across the whole application** —
 * nothing reads it back. The live active-users list is served from PostgreSQL
 * (`ChatService::getActiveUsers()` reads the `sessions` table; fake users come
 * from the `fake_users` table). The writes are preserved here to keep behaviour
 * byte-for-byte for any out-of-repo consumer (e.g. an ops dashboard) that might
 * read the hash directly; if none exists, this registry can simply be dropped.
 * Keeping it behind one class makes either outcome a single-file change.
 *
 * The key is stored UNPREFIXED (`chat:active_users`), matching the primary
 * writers (ChatService) and AdminModerationController. This normalizes the one
 * historical outlier — FakeUserService, which wrote to `<prefix>active_users` —
 * onto the same key; since the hash has no reader, the normalization is
 * behaviour-neutral in-repo (analogous to the Step-1 channel-prefix fix).
 */
final class ActiveUsersRegistry
{
    /** Un-prefixed, matching the historical ChatService / AdminModeration writers. */
    private const KEY = 'chat:active_users';

    private Redis $redis;

    public function __construct(?Redis $redis = null)
    {
        $this->redis = $redis ?? Database::getRedis();
    }

    /**
     * Record a user (real or fake) as present.
     *
     * @param string              $identifier Username, nickname or session id (as the caller used historically)
     * @param array<string,mixed> $descriptor Arbitrary JSON-serialisable descriptor
     */
    public function join(string $identifier, array $descriptor): void
    {
        $this->redis->hSet(self::KEY, $identifier, (string) json_encode($descriptor));
    }

    /**
     * Remove a user from the active-users hash.
     */
    public function leave(string $identifier): void
    {
        $this->redis->hDel(self::KEY, $identifier);
    }
}
