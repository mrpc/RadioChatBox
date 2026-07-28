<?php

namespace RadioChatBox;

use Redis;

/**
 * Recent-message history state — the Redis structures that back the live chat.
 *
 * This is the single owner of the three message-history keyspaces, which are a
 * cache of PostgreSQL (ChatService rebuilds them from the `messages` table when
 * they are empty) rather than the source of truth:
 *
 *   <prefix>chat:messages          LIST  newest-first JSON message objects (lTrim'd)
 *   <prefix>chat:messages:hash     HASH  messageId => {username,display_name,message}
 *                                        for O(1) reply-quote lookups
 *   <prefix>chat:deleted_messages  HASH  messageId => "1" tombstones for the list
 *
 * The exact key shapes, the per-install prefix, the 24-hour TTLs and the JSON
 * envelopes live here and nowhere else, so services ask this repository to
 * append/fetch/trim/tombstone instead of hand-rolling raw Redis calls scattered
 * across ChatService and the controllers (Phase 8 State layer, mirroring
 * {@see KickRegistry}). Because the list uses native LPUSH/LTRIM (atomic append +
 * bounded trim) and the hashes are field-addressed, this state store wraps the
 * raw Redis connection directly rather than the flat-key Cache capability; keeping
 * it behind this one boundary makes a future re-model a single-file change.
 *
 * Keys ARE prefixed with the per-install Redis prefix — byte-identical to the
 * historical `ChatService::prefixKey()` call sites this class replaces — so the
 * cache carries over unchanged and in-flight history survives the refactor.
 */
final class MessageHistory
{
    private const MESSAGES_KEY = 'chat:messages';
    private const HASH_KEY     = 'chat:messages:hash';
    private const DELETED_KEY  = 'chat:deleted_messages';

    /** How long the cached history/hash live before Redis lets them expire. */
    public const TTL = 86400;

    private Redis $redis;
    private string $prefix;
    private int $historyLimit;

    public function __construct(?Redis $redis = null, ?string $prefix = null, ?int $historyLimit = null)
    {
        $this->redis        = $redis ?? Database::getRedis();
        $this->prefix       = $prefix ?? Database::getRedisPrefix();
        $this->historyLimit = $historyLimit ?? (int) Config::get('chat')['history_limit'];
    }

    private function listKey(): string
    {
        return $this->prefix . self::MESSAGES_KEY;
    }

    private function hashKey(): string
    {
        return $this->prefix . self::HASH_KEY;
    }

    private function deletedKey(): string
    {
        return $this->prefix . self::DELETED_KEY;
    }

    // ── Recent-message list ─────────────────────────────────────────────────────

    /**
     * Prepend a message to the recent-history list, trim to the history limit and
     * refresh the TTL. Newest message ends up at position 0.
     *
     * @param array<string,mixed> $messageData
     */
    public function append(array $messageData): void
    {
        $key = $this->listKey();
        $this->redis->lPush($key, (string) json_encode($messageData));
        $this->redis->lTrim($key, 0, $this->historyLimit - 1);
        $this->redis->expire($key, self::TTL);
    }

    /**
     * The most recent messages, newest-first, as decoded arrays (as stored).
     *
     * Returns an empty array when the cache is empty — the caller then rebuilds
     * from the database via {@see replace()}.
     *
     * @return list<array<string,mixed>>
     */
    public function recent(int $limit): array
    {
        $raw = $this->redis->lRange($this->listKey(), 0, $limit - 1);
        if (!is_array($raw) || $raw === []) {
            return [];
        }

        $messages = [];
        foreach ($raw as $json) {
            $decoded = json_decode((string) $json, true);
            if (is_array($decoded)) {
                $messages[] = $decoded;
            }
        }

        return $messages;
    }

    /**
     * Replace the whole list from a newest-first set of messages (the database
     * rebuild path): clear, push oldest-first so newest lands at position 0, trim
     * and set the TTL.
     *
     * @param list<array<string,mixed>> $messagesNewestFirst
     */
    public function replace(array $messagesNewestFirst): void
    {
        $key = $this->listKey();
        $this->redis->del($key);

        foreach (array_reverse($messagesNewestFirst) as $msg) {
            $this->redis->lPush($key, (string) json_encode($msg));
        }
        $this->redis->lTrim($key, 0, $this->historyLimit - 1);
        $this->redis->expire($key, self::TTL);
    }

    /**
     * Drop the cached list so the next read rebuilds it from the database.
     */
    public function clear(): void
    {
        $this->redis->del($this->listKey());
    }

    // ── Reply-quote hash ────────────────────────────────────────────────────────

    /**
     * Cache the minimal quote fields for a message for O(1) reply lookups.
     *
     * @param array{username:mixed,display_name:mixed,message:mixed} $quote
     */
    public function cacheReply(string $messageId, array $quote): void
    {
        $key = $this->hashKey();
        $this->redis->hSet($key, $messageId, (string) json_encode($quote));
        $this->redis->expire($key, self::TTL);
    }

    /**
     * The cached quote fields for a message, or null when not cached.
     *
     * @return array<string,mixed>|null
     */
    public function getReply(string $messageId): ?array
    {
        $cached = $this->redis->hGet($this->hashKey(), $messageId);
        if ($cached === false) {
            return null;
        }
        $decoded = json_decode((string) $cached, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Update the cached message text of an already-cached quote (after an edit).
     * No-op when the message is not in the hash.
     */
    public function updateReplyMessage(string $messageId, string $message): void
    {
        $existing = $this->getReply($messageId);
        if ($existing === null) {
            return;
        }
        $existing['message'] = $message;
        $this->redis->hSet($this->hashKey(), $messageId, (string) json_encode($existing));
    }

    // ── Deleted-message tombstones ──────────────────────────────────────────────

    /**
     * Tombstone a message so it is filtered out of the cached history without a
     * database round-trip on every load.
     */
    public function markDeleted(string $messageId): void
    {
        $key = $this->deletedKey();
        $this->redis->hSet($key, $messageId, '1');
        $this->redis->expire($key, self::TTL);
    }

    /**
     * Whether a message has been tombstoned as deleted.
     */
    public function isDeleted(string $messageId): bool
    {
        return $this->redis->hGet($this->deletedKey(), $messageId) === '1';
    }
}
