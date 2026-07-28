<?php

namespace RadioChatBox;

use Pramnos\Cache\FlatCache;

/**
 * Recent-message history — a cache of PostgreSQL, held behind the framework Cache
 * capability (Redis driver in production) using its structured operations.
 *
 * This owns the three message-history structures as cache keys:
 *
 *   chat:history           LIST  newest-first message objects (trimmed to the limit)
 *   chat:history:hash      HASH  messageId => {username,display_name,message} (reply quotes)
 *   chat:history:deleted   HASH  messageId => "1" tombstones for the list
 *
 * It is a cache of the `messages` table (ChatService rebuilds it via
 * loadHistoryFromDB when empty), so it depends on the Cache capability, not on
 * raw Redis — the list/hash/expire operations are the framework's structured
 * cache ops (native Redis LPUSH/LTRIM/LRANGE/HSET under the hood, an in-memory
 * fallback elsewhere). Keys are prefixed per-install by the Cache layer.
 *
 * The class keeps only the domain shape (which keys, the message quote fields,
 * the TTL and trim); storage is entirely the Cache capability's job.
 *
 * NOTE: these are new (`chat:history*`) keys, distinct from the pre-Phase-8 raw
 * `chat:messages*` keys, so the switch to serialised structured values is a clean
 * cutover — old keys simply expire; the history rebuilds from the database.
 */
final class MessageHistory
{
    private const MESSAGES_KEY = 'chat:history';
    private const HASH_KEY     = 'chat:history:hash';
    private const DELETED_KEY  = 'chat:history:deleted';

    /** How long the cached history/hash live before they expire. */
    public const TTL = 86400;

    private FlatCache $cache;
    private int $historyLimit;

    public function __construct(?int $historyLimit = null, ?FlatCache $cache = null)
    {
        $this->cache        = $cache ?? Cache::store();
        $this->historyLimit = $historyLimit ?? (int) Config::get('chat')['history_limit'];
    }

    // ── Recent-message list ─────────────────────────────────────────────────────

    /**
     * Prepend a message to the recent-history list, trim to the limit, refresh TTL.
     *
     * @param array<string,mixed> $messageData
     */
    public function append(array $messageData): void
    {
        $this->cache->listPush(self::MESSAGES_KEY, $messageData);
        $this->cache->listTrim(self::MESSAGES_KEY, 0, $this->historyLimit - 1);
        $this->cache->expire(self::MESSAGES_KEY, self::TTL);
    }

    /**
     * The most recent messages, newest-first. Returns an empty array when the
     * cache is empty (the caller then rebuilds from the database via replace()).
     *
     * @return list<array<string,mixed>>
     */
    public function recent(int $limit): array
    {
        $messages = $this->cache->listRange(self::MESSAGES_KEY, 0, $limit - 1);
        if (!is_array($messages) || $messages === []) {
            return [];
        }
        // Defensive: any non-array element means stale/foreign data — treat the
        // whole cache as empty so the caller rebuilds from the database.
        foreach ($messages as $msg) {
            if (!is_array($msg)) {
                return [];
            }
        }
        return array_values($messages);
    }

    /**
     * Replace the whole list from a newest-first set of messages (the DB rebuild):
     * clear, push oldest-first so newest lands at position 0, trim, set TTL.
     *
     * @param list<array<string,mixed>> $messagesNewestFirst
     */
    public function replace(array $messagesNewestFirst): void
    {
        $this->cache->delete(self::MESSAGES_KEY);
        foreach (array_reverse($messagesNewestFirst) as $msg) {
            $this->cache->listPush(self::MESSAGES_KEY, $msg);
        }
        $this->cache->listTrim(self::MESSAGES_KEY, 0, $this->historyLimit - 1);
        $this->cache->expire(self::MESSAGES_KEY, self::TTL);
    }

    /**
     * Drop the cached list so the next read rebuilds it from the database.
     */
    public function clear(): void
    {
        $this->cache->delete(self::MESSAGES_KEY);
    }

    // ── Reply-quote hash ────────────────────────────────────────────────────────

    /**
     * Cache the minimal quote fields for a message for O(1) reply lookups.
     *
     * @param array{username:mixed,display_name:mixed,message:mixed} $quote
     */
    public function cacheReply(string $messageId, array $quote): void
    {
        $this->cache->hashSet(self::HASH_KEY, $messageId, $quote, self::TTL);
    }

    /**
     * The cached quote fields for a message, or null when not cached.
     *
     * @return array<string,mixed>|null
     */
    public function getReply(string $messageId): ?array
    {
        $cached = $this->cache->hashGet(self::HASH_KEY, $messageId);

        return is_array($cached) ? $cached : null;
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
        $this->cache->hashSet(self::HASH_KEY, $messageId, $existing);
    }

    // ── Deleted-message tombstones ──────────────────────────────────────────────

    /**
     * Tombstone a message so it is filtered out of the cached history without a
     * database round-trip on every load.
     */
    public function markDeleted(string $messageId): void
    {
        $this->cache->hashSet(self::DELETED_KEY, $messageId, '1', self::TTL);
    }

    /**
     * Whether a message has been tombstoned as deleted.
     */
    public function isDeleted(string $messageId): bool
    {
        return $this->cache->hashGet(self::DELETED_KEY, $messageId) === '1';
    }
}
