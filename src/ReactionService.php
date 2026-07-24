<?php

namespace RadioChatBox;

use PDO;

/**
 * Emoji reactions on public chat messages.
 *
 * A user may react with each allowed emoji at most once per message; reacting
 * again with the same emoji removes it (toggle). Reactions reference the
 * app-level messages.message_id string.
 */
class ReactionService
{
    private \Redis $redis;
    private PDO $pdo;
    private string $prefix;

    /** Redis pub/sub channel reused for real-time chat updates. */
    private const PUBSUB_CHANNEL = 'chat:updates';

    /** Allowed reaction emojis (server-enforced whitelist). */
    private const ALLOWED_EMOJIS = ['👍', '❤️', '😂', '😮', '😢', '🔥'];

    public function __construct()
    {
        $this->redis = Database::getRedis();
        $this->pdo = Database::getPDO();
        $this->prefix = Database::getRedisPrefix();
    }

    /**
     * @return string[] The allowed emoji set, in display order.
     */
    public static function getAllowedEmojis(): array
    {
        return self::ALLOWED_EMOJIS;
    }

    private function isAllowed(string $emoji): bool
    {
        return in_array($emoji, self::ALLOWED_EMOJIS, true);
    }

    /**
     * Toggle a reaction. Returns the message's reaction state after the change:
     *   ['message_id' => ..., 'reactions' => [ ['emoji'=>..,'count'=>..,'mine'=>bool], ... ],
     *    'action' => 'added'|'removed']
     * and publishes a real-time update to subscribers.
     */
    public function toggleReaction(string $messageId, string $username, ?string $sessionId, string $emoji): array
    {
        $messageId = trim($messageId);
        $username = trim($username);

        if ($messageId === '' || $username === '') {
            throw new \InvalidArgumentException('message_id and username are required');
        }
        if (!$this->isAllowed($emoji)) {
            throw new \InvalidArgumentException('Emoji not allowed');
        }
        if (!$this->messageExists($messageId)) {
            throw new \RuntimeException('Message not found');
        }

        // A user has at most one reaction per message. Look up their current one.
        $stmt = $this->pdo->prepare(
            'SELECT id, emoji FROM message_reactions
             WHERE message_id = :m AND LOWER(username) = LOWER(:u)'
        );
        $stmt->execute(['m' => $messageId, 'u' => $username]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing !== false && $existing['emoji'] === $emoji) {
            // Same emoji again → remove (toggle off).
            $del = $this->pdo->prepare('DELETE FROM message_reactions WHERE id = :id');
            $del->execute(['id' => $existing['id']]);
            $action = 'removed';
        } elseif ($existing !== false) {
            // Different emoji → replace the existing reaction.
            $upd = $this->pdo->prepare(
                'UPDATE message_reactions SET emoji = :e, session_id = :s, created_at = NOW() WHERE id = :id'
            );
            $upd->execute(['e' => $emoji, 's' => $sessionId, 'id' => $existing['id']]);
            $action = 'changed';
        } else {
            // No reaction yet → add one.
            $ins = $this->pdo->prepare(
                'INSERT INTO message_reactions (message_id, username, session_id, emoji)
                 VALUES (:m, :u, :s, :e)
                 ON CONFLICT (message_id, username) DO UPDATE SET emoji = EXCLUDED.emoji, created_at = NOW()'
            );
            $ins->execute(['m' => $messageId, 'u' => $username, 's' => $sessionId, 'e' => $emoji]);
            $action = 'added';
        }

        $reactions = $this->getReactionsForMessage($messageId, $username);
        $this->publishUpdate($messageId, $reactions);

        return [
            'message_id' => $messageId,
            'reactions' => $reactions,
            'action' => $action,
        ];
    }

    /**
     * Reaction aggregate for a single message, in allowed-emoji order.
     * Only emojis with at least one reaction are returned.
     *
     * @return array<int, array{emoji:string,count:int,mine:bool}>
     */
    public function getReactionsForMessage(string $messageId, ?string $username = null): array
    {
        $attached = $this->attachToMessages([['id' => $messageId]], $username);
        return $attached[0]['reactions'] ?? [];
    }

    /**
     * Attach a `reactions` array to each message (keyed by its 'id' == message_id).
     * Batches DB access: one query for counts, one for the current user's own
     * reactions. Messages without reactions get an empty array.
     *
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, array<string, mixed>>
     */
    public function attachToMessages(array $messages, ?string $username = null): array
    {
        // Collect message ids.
        $ids = [];
        foreach ($messages as $msg) {
            $id = $msg['id'] ?? ($msg['message_id'] ?? null);
            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }
        $ids = array_values(array_unique($ids));

        if (empty($ids)) {
            foreach ($messages as &$m) {
                $m['reactions'] = [];
            }
            return $messages;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        // Counts per (message_id, emoji).
        $counts = [];
        try {
            $stmt = $this->pdo->prepare(
                "SELECT message_id, emoji, COUNT(*) AS cnt
                 FROM message_reactions
                 WHERE message_id IN ($placeholders)
                 GROUP BY message_id, emoji"
            );
            $stmt->execute($ids);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $counts[$row['message_id']][$row['emoji']] = (int)$row['cnt'];
            }
        } catch (\PDOException $e) {
            error_log('ReactionService::attachToMessages counts failed: ' . $e->getMessage());
        }

        // Current user's own reactions.
        $mine = [];
        if ($username !== null && $username !== '') {
            try {
                $params = $ids;
                $params[] = $username;
                $stmt = $this->pdo->prepare(
                    "SELECT message_id, emoji FROM message_reactions
                     WHERE message_id IN ($placeholders) AND LOWER(username) = LOWER(?)"
                );
                $stmt->execute($params);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $mine[$row['message_id']][$row['emoji']] = true;
                }
            } catch (\PDOException $e) {
                error_log('ReactionService::attachToMessages mine failed: ' . $e->getMessage());
            }
        }

        foreach ($messages as &$m) {
            $id = $m['id'] ?? ($m['message_id'] ?? null);
            $m['reactions'] = $this->buildReactionList(
                $counts[$id] ?? [],
                $mine[$id] ?? []
            );
        }
        unset($m);

        return $messages;
    }

    /**
     * Build the ordered reaction list for one message from raw count/mine maps.
     *
     * @param array<string,int>  $countMap emoji => count
     * @param array<string,bool> $mineMap  emoji => true
     * @return array<int, array{emoji:string,count:int,mine:bool}>
     */
    private function buildReactionList(array $countMap, array $mineMap): array
    {
        $list = [];
        foreach (self::ALLOWED_EMOJIS as $emoji) {
            $count = $countMap[$emoji] ?? 0;
            if ($count > 0) {
                $list[] = [
                    'emoji' => $emoji,
                    'count' => $count,
                    'mine' => isset($mineMap[$emoji]),
                ];
            }
        }
        return $list;
    }

    private function messageExists(string $messageId): bool
    {
        try {
            $stmt = $this->pdo->prepare('SELECT 1 FROM messages WHERE message_id = :m LIMIT 1');
            $stmt->execute(['m' => $messageId]);
            return $stmt->fetchColumn() !== false;
        } catch (\PDOException $e) {
            error_log('ReactionService::messageExists failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Publish a real-time reaction update on the shared chat channel.
     * `mine` is intentionally omitted from the broadcast (it is per-viewer);
     * clients merge counts and keep their own mine-state locally.
     */
    private function publishUpdate(string $messageId, array $reactions): void
    {
        try {
            $counts = [];
            foreach ($reactions as $r) {
                $counts[$r['emoji']] = $r['count'];
            }
            $payload = json_encode([
                'type' => 'reaction',
                'message_id' => $messageId,
                'counts' => $counts,
            ]);
            $this->redis->publish($this->prefix . self::PUBSUB_CHANNEL, $payload);
        } catch (\Exception $e) {
            error_log('ReactionService::publishUpdate failed: ' . $e->getMessage());
        }
    }
}
