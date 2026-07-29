<?php

namespace RadioChatBox;

use Pramnos\Cache\FlatCache;

/**
 * Registry of kicked sessions — short-lived moderation state, held behind the
 * framework Cache capability (Redis driver in production).
 *
 * When an admin kicks a user, their session id is recorded for one hour so they
 * cannot immediately rejoin or keep communicating. This is the single owner of
 * the `banned_session:<id>` cache namespace: the key shape, the 1-hour TTL and
 * the stored fields live here and nowhere else, so services ask
 * "isKicked(sessionId)" / "kick(...)" instead of hand-rolling Redis calls.
 *
 * Keys are per-install (prefixed by the Cache layer): a kick on one install does
 * not affect another that merely shares the same Redis. Enumeration for the admin
 * view uses the Cache `keys()` operation (Redis SCAN under the hood); the
 * remaining lock time is derived from the stored `kicked_at` + TTL, so no
 * separate TTL read is needed.
 */
final class KickRegistry
{
    private const KEY_PREFIX = 'banned_session:';

    /** How long a kick keeps a session locked out. */
    public const TTL = 3600;

    private FlatCache $cache;

    public function __construct(?FlatCache $cache = null)
    {
        $this->cache = $cache ?? FlatCache::default();
    }

    private function key(string $sessionId): string
    {
        return self::KEY_PREFIX . $sessionId;
    }

    /**
     * Kick a session: lock it out for {@see TTL} seconds.
     */
    public function kick(string $sessionId, string $username, string $reason = 'Kicked by admin'): void
    {
        $this->cache->set($this->key($sessionId), [
            'username'  => $username,
            'reason'    => $reason,
            'kicked_at' => time(),
        ], self::TTL);
    }

    /**
     * Whether a session is currently kicked (locked out).
     */
    public function isKicked(string $sessionId): bool
    {
        if ($sessionId === '') {
            return false;
        }
        return $this->cache->has($this->key($sessionId));
    }

    /**
     * Remove a kick early (before its TTL elapses).
     */
    public function forget(string $sessionId): void
    {
        $this->cache->delete($this->key($sessionId));
    }

    /**
     * Every currently-kicked session, for the admin moderation view.
     *
     * @return list<array{session_id:string,username:?string,reason:?string,kicked_at:mixed,expires_in:int}>
     */
    public function list(): array
    {
        $kicked = [];
        foreach ($this->cache->keys(self::KEY_PREFIX . '*') as $key) {
            $data = $this->cache->get($key);
            if (!is_array($data)) {
                continue;
            }
            $kickedAt = (int) ($data['kicked_at'] ?? 0);
            $kicked[] = [
                'session_id' => substr($key, strlen(self::KEY_PREFIX)),
                'username'   => $data['username'] ?? null,
                'reason'     => $data['reason'] ?? null,
                'kicked_at'  => $data['kicked_at'] ?? null,
                'expires_in' => $kickedAt > 0 ? max(0, $kickedAt + self::TTL - time()) : self::TTL,
            ];
        }

        return $kicked;
    }
}
