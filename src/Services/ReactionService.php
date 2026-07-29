<?php

namespace RadioChatBox\Services;

use Pramnos\Broadcasting\BroadcastingManager;
use RadioChatBox\Database;
use Pramnos\Database\Database as PramnosDatabase;

/**
 * Emoji reactions on public chat messages.
 *
 * A user may react with each allowed emoji at most once per message; reacting
 * again with the same emoji removes it (toggle). Reactions reference the
 * app-level messages.message_id string.
 */
class ReactionService
{
    private PramnosDatabase $db;

    /** Redis pub/sub channel reused for real-time chat updates. */
    private const PUBSUB_CHANNEL = 'chat:updates';

    /** Allowed reaction emojis (server-enforced whitelist). */
    private const ALLOWED_EMOJIS = ['👍', '❤️', '😂', '😮', '😢', '🔥', '🤘'];

    public function __construct()
    {
        $this->db = Database::getDb();
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
        $lookup = $this->db->queryBuilder()
            ->from('message_reactions')
            ->select(['id', 'emoji'])
            ->where('message_id', '=', $messageId)
            ->whereRaw('LOWER(username) = LOWER(%s)', [$username])
            ->first();
        $existing = ($lookup && $lookup->numRows > 0) ? $lookup->fields : false;

        if ($existing !== false && $existing['emoji'] === $emoji) {
            // Same emoji again → remove (toggle off).
            $this->db->queryBuilder()
                ->from('message_reactions')
                ->where('id', '=', $existing['id'])
                ->delete();
            $action = 'removed';
        } elseif ($existing !== false) {
            // Different emoji → replace the existing reaction.
            $qb = $this->db->queryBuilder()->from('message_reactions');
            $qb->where('id', '=', $existing['id'])->update([
                'emoji'      => $emoji,
                'session_id' => $sessionId,
                'created_at' => $qb->raw('NOW()'),
            ]);
            $action = 'changed';
        } else {
            // No reaction yet → add one. The ON CONFLICT branch is a race safety
            // net; created_at is written on both insert and conflict-update so
            // EXCLUDED.created_at carries NOW() forward (session_id is not).
            $qb = $this->db->queryBuilder()->from('message_reactions');
            $qb->upsert(
                [
                    'message_id' => $messageId,
                    'username'   => $username,
                    'session_id' => $sessionId,
                    'emoji'      => $emoji,
                    'created_at' => $qb->raw('NOW()'),
                ],
                ['message_id', 'username'],
                ['emoji', 'created_at']
            );
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

        // Counts per (message_id, emoji).
        $counts = [];
        try {
            $rows = $this->db->queryBuilder()
                ->from('message_reactions')
                ->select(['message_id', 'emoji', 'COUNT(*) AS cnt'])
                ->whereIn('message_id', $ids)
                ->groupBy(['message_id', 'emoji'])
                ->getAll();
            foreach ($rows as $row) {
                $counts[$row['message_id']][$row['emoji']] = (int)$row['cnt'];
            }
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('ReactionService::attachToMessages counts failed: ' . $e->getMessage(), 'radiochatbox');
        }

        // Current user's own reactions.
        $mine = [];
        if ($username !== null && $username !== '') {
            try {
                $rows = $this->db->queryBuilder()
                    ->from('message_reactions')
                    ->select(['message_id', 'emoji'])
                    ->whereIn('message_id', $ids)
                    ->whereRaw('LOWER(username) = LOWER(%s)', [$username])
                    ->getAll();
                foreach ($rows as $row) {
                    $mine[$row['message_id']][$row['emoji']] = true;
                }
            } catch (\Throwable $e) {
                \Pramnos\Logs\Logger::log('ReactionService::attachToMessages mine failed: ' . $e->getMessage(), 'radiochatbox');
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
            return $this->db->queryBuilder()
                ->from('messages')
                ->where('message_id', '=', $messageId)
                ->exists();
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('ReactionService::messageExists failed: ' . $e->getMessage(), 'radiochatbox');
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
            BroadcastingManager::instance()->broadcast(self::PUBSUB_CHANNEL, 'reaction', [
                'type' => 'reaction',
                'message_id' => $messageId,
                'counts' => $counts,
            ]);
        } catch (\Exception $e) {
            \Pramnos\Logs\Logger::log('ReactionService::publishUpdate failed: ' . $e->getMessage(), 'radiochatbox');
        }
    }
}
