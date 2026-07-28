<?php

namespace RadioChatBox;

use Redis;

/**
 * Registry of kicked sessions — durable moderation state, NOT a cache.
 *
 * When an admin kicks a user, their session id is recorded here for one hour so
 * they cannot immediately rejoin or keep communicating. This is the single owner
 * of the `banned_session:<id>` keyspace: the exact key shape, the 1-hour TTL and
 * the JSON envelope live here and nowhere else, so services ask
 * "isKicked(sessionId)" / "kick(...)" instead of hand-rolling raw Redis calls.
 *
 * The keys are deliberately stored WITHOUT the per-install Redis prefix (a kick
 * is intentionally global across installs that share the Redis), preserving the
 * historical behaviour of the scattered call sites this class replaces. Because
 * enumeration ({@see list()}) needs a keyspace scan, this state store wraps the
 * raw Redis connection directly rather than the (scan-less) Cache capability;
 * keeping it behind this one boundary makes a future re-model (a SET/hash index,
 * or a DB table) a single-file change.
 */
final class KickRegistry
{
    /** Un-prefixed by design: kicks are global across installs sharing Redis. */
    private const KEY_PREFIX = 'banned_session:';

    /** How long a kick keeps a session locked out. */
    public const TTL = 3600;

    private Redis $redis;

    public function __construct(?Redis $redis = null)
    {
        $this->redis = $redis ?? Database::getRedis();
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
        $this->redis->setex($this->key($sessionId), self::TTL, (string) json_encode([
            'username'  => $username,
            'reason'    => $reason,
            'kicked_at' => time(),
        ]));
    }

    /**
     * Whether a session is currently kicked (locked out).
     */
    public function isKicked(string $sessionId): bool
    {
        if ($sessionId === '') {
            return false;
        }
        return (bool) $this->redis->exists($this->key($sessionId));
    }

    /**
     * Remove a kick early (before its TTL elapses).
     */
    public function forget(string $sessionId): void
    {
        $this->redis->del($this->key($sessionId));
    }

    /**
     * Every currently-kicked session, for the admin moderation view.
     *
     * @return list<array{session_id:string,username:?string,reason:?string,kicked_at:mixed,expires_in:int}>
     */
    public function list(): array
    {
        $pattern = self::KEY_PREFIX . '*';
        $cursor  = null;
        $kicked  = [];
        do {
            $keys = $this->redis->scan($cursor, $pattern, 100);
            if ($keys === false) {
                break;
            }
            foreach ($keys as $key) {
                $ttl  = $this->redis->ttl($key);
                $data = json_decode((string) $this->redis->get($key), true);
                if ($data) {
                    $kicked[] = [
                        'session_id' => substr($key, strlen(self::KEY_PREFIX)),
                        'username'   => $data['username'] ?? null,
                        'reason'     => $data['reason'] ?? null,
                        'kicked_at'  => $data['kicked_at'] ?? null,
                        'expires_in' => $ttl,
                    ];
                }
            }
        } while ($cursor !== 0 && $cursor !== null);

        return $kicked;
    }
}
