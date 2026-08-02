<?php

namespace RadioChatBox\Services;

use Pramnos\Broadcasting\BroadcastingManager;
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
        $this->db = PramnosDatabase::getInstance();
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

        $action    = $this->applyToggle('message_reactions', $messageId, $username, $sessionId, $emoji);
        $reactions = $this->getReactionsForMessage($messageId, $username);
        $this->publishUpdate($messageId, $reactions);

        return [
            'message_id' => $messageId,
            'reactions' => $reactions,
            'action' => $action,
        ];
    }

    /**
     * Toggle a reaction on a DIRECT message. Reactions reuse the message_reactions
     * table with a `pm_<id>` key (distinct from public message ids). The update is
     * broadcast on chat:private_messages carrying both participants, so each side
     * (and an impersonating admin) receives it; the client tells it apart from a
     * message by `type === 'reaction'`.
     */
    public function toggleDmReaction(int $dmId, string $username, ?string $sessionId, string $emoji): array
    {
        $username = trim($username);
        if ($dmId <= 0 || $username === '') {
            throw new \InvalidArgumentException('dm id and username are required');
        }
        if (!$this->isAllowed($emoji)) {
            throw new \InvalidArgumentException('Emoji not allowed');
        }
        $dm = $this->dmMessageRow($dmId);
        if ($dm === null) {
            throw new \RuntimeException('Message not found');
        }

        $action    = $this->applyToggle('private_message_reactions', (string) $dmId, $username, $sessionId, $emoji);
        $reactions = $this->getReactionsForMessage((string) $dmId, $username, 'private_message_reactions');
        $this->publishDmUpdate($dmId, $reactions, (string) $dm['from_username'], (string) $dm['to_username']);

        return [
            'message_id' => $dmId,
            'reactions'  => $reactions,
            'action'     => $action,
        ];
    }

    /**
     * The shared insert / replace / delete toggle core (a user has at most one
     * reaction per message). Returns the action taken: added|changed|removed.
     */
    private function applyToggle(string $table, string $messageId, string $username, ?string $sessionId, string $emoji): string
    {
        $lookup = $this->db->queryBuilder()
            ->from($table)
            ->select(['id', 'emoji'])
            ->where('message_id', '=', $messageId)
            ->whereRaw('LOWER(username) = LOWER(%s)', [$username])
            ->first();
        $existing = ($lookup && $lookup->numRows > 0) ? $lookup->fields : false;

        if ($existing !== false && $existing['emoji'] === $emoji) {
            $this->db->queryBuilder()
                ->from($table)
                ->where('id', '=', $existing['id'])
                ->delete();
            return 'removed';
        }
        if ($existing !== false) {
            $qb = $this->db->queryBuilder()->from($table);
            $qb->where('id', '=', $existing['id'])->update([
                'emoji'      => $emoji,
                'session_id' => $sessionId,
                'created_at' => $qb->raw('NOW()'),
            ]);
            return 'changed';
        }
        // The ON CONFLICT branch is a race safety net; created_at is written on
        // both insert and conflict-update so EXCLUDED.created_at carries NOW().
        $qb = $this->db->queryBuilder()->from($table);
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
        return 'added';
    }

    /** The from/to row for a DM id, or null when it does not exist. */
    private function dmMessageRow(int $dmId): ?array
    {
        $r = $this->db->queryBuilder()
            ->from('private_messages')
            ->select(['id', 'from_username', 'to_username'])
            ->where('id', '=', $dmId)
            ->first();
        return ($r && $r->numRows > 0) ? $r->fields : null;
    }

    /** Broadcast a DM reaction update to both participants' private feed. */
    private function publishDmUpdate(int $dmId, array $reactions, string $from, string $to): void
    {
        try {
            $counts = [];
            foreach ($reactions as $rx) {
                $counts[$rx['emoji']] = $rx['count'];
            }
            BroadcastingManager::instance()->broadcast('chat:private_messages', 'private', [
                'type'          => 'reaction',
                'message_id'    => $dmId,
                'counts'        => $counts,
                'from_username' => $from,
                'to_username'   => $to,
            ]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('ReactionService::publishDmUpdate failed: ' . $e->getMessage(), 'radiochatbox');
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Reaction aggregate for a single message, in allowed-emoji order.
     * Only emojis with at least one reaction are returned.
     *
     * @return array<int, array{emoji:string,count:int,mine:bool}>
     */
    public function getReactionsForMessage(string $messageId, ?string $username = null, string $table = 'message_reactions'): array
    {
        $attached = $this->attachToMessages([['id' => $messageId]], $username, $table);
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
    public function attachToMessages(array $messages, ?string $username = null, string $table = 'message_reactions'): array
    {
        // Collect message ids. DM ids are an INTEGER column (returned as int or
        // string depending on the driver), while public ids are varchar — normalise
        // to string so the count/mine maps below key consistently either way. (This
        // is why DM reactions came back empty from history: an int id was skipped.)
        $ids = [];
        foreach ($messages as $msg) {
            $id = $msg['id'] ?? ($msg['message_id'] ?? null);
            if ((is_string($id) || is_int($id)) && (string) $id !== '') {
                $ids[] = (string) $id;
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
                ->from($table)
                ->select(['message_id', 'emoji', 'COUNT(*) AS cnt'])
                ->whereIn('message_id', $ids)
                ->groupBy(['message_id', 'emoji'])
                ->getAll();
            foreach ($rows as $row) {
                $counts[(string) $row['message_id']][$row['emoji']] = (int)$row['cnt'];
            }
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('ReactionService::attachToMessages counts failed: ' . $e->getMessage(), 'radiochatbox');
        }
        // @codeCoverageIgnoreEnd

        // Current user's own reactions.
        $mine = [];
        if ($username !== null && $username !== '') {
            try {
                $rows = $this->db->queryBuilder()
                    ->from($table)
                    ->select(['message_id', 'emoji'])
                    ->whereIn('message_id', $ids)
                    ->whereRaw('LOWER(username) = LOWER(%s)', [$username])
                    ->getAll();
                foreach ($rows as $row) {
                    $mine[(string) $row['message_id']][$row['emoji']] = true;
                }
            // @codeCoverageIgnoreStart
            } catch (\Throwable $e) {
                \Pramnos\Logs\Logger::log('ReactionService::attachToMessages mine failed: ' . $e->getMessage(), 'radiochatbox');
            }
            // @codeCoverageIgnoreEnd
        }

        foreach ($messages as &$m) {
            $id = (string) ($m['id'] ?? ($m['message_id'] ?? ''));
            $m['reactions'] = $this->buildReactionList(
                $counts[$id] ?? [],
                $mine[$id] ?? []
            );
        }
        unset($m);

        return $messages;
    }

    /**
     * Who reacted to a message, grouped by emoji (ordered like the reaction list).
     * Usernames within each emoji are ordered oldest-first.
     *
     * @return array<int, array{emoji:string, count:int, users:list<string>}>
     */
    public function whoReacted(string $messageId, string $table = 'message_reactions'): array
    {
        if (!in_array($table, ['message_reactions', 'private_message_reactions'], true)) {
            $table = 'message_reactions';
        }
        if (trim($messageId) === '') {
            return [];
        }

        $byEmoji = [];
        try {
            $rows = $this->db->queryBuilder()
                ->from($table)
                ->select(['emoji', 'username'])
                ->where('message_id', '=', $messageId)
                ->orderBy('created_at', 'asc')
                ->getAll();
            foreach ($rows as $row) {
                $byEmoji[(string) $row['emoji']][] = (string) $row['username'];
            }
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('ReactionService::whoReacted failed: ' . $e->getMessage(), 'radiochatbox');
            return [];
        }
        // @codeCoverageIgnoreEnd

        // Emit in the canonical emoji order so it matches the reaction pills.
        $out = [];
        foreach (self::ALLOWED_EMOJIS as $emoji) {
            if (!empty($byEmoji[$emoji])) {
                $out[] = ['emoji' => $emoji, 'count' => count($byEmoji[$emoji]), 'users' => $byEmoji[$emoji]];
            }
        }
        return $out;
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
                ->from('chat_messages')
                ->where('message_id', '=', $messageId)
                ->exists();
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('ReactionService::messageExists failed: ' . $e->getMessage(), 'radiochatbox');
            return false;
        }
        // @codeCoverageIgnoreEnd
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
        // @codeCoverageIgnoreStart
        } catch (\Exception $e) {
            \Pramnos\Logs\Logger::log('ReactionService::publishUpdate failed: ' . $e->getMessage(), 'radiochatbox');
        }
        // @codeCoverageIgnoreEnd
    }
}
