<?php

namespace RadioChatBox\Services;

use Pramnos\Database\Database as PramnosDatabase;

/**
 * Moderator-pinned public chat messages. A pin snapshots the message text (so it
 * survives deletion of the original) and may carry an expiry that auto-unpins
 * it. The chat shows the active pins in a bar at the top.
 */
class PinService
{
    private PramnosDatabase $db;

    public function __construct()
    {
        $this->db = PramnosDatabase::getInstance();
    }

    /**
     * Pin a message (idempotent on message_id — re-pinning refreshes it). Returns
     * the pin id.
     *
     * @param int|null $expiresInMinutes null = no expiry.
     * @throws \InvalidArgumentException on empty id/content.
     */
    public function pin(
        string $messageId,
        string $content,
        ?string $username,
        string $pinnedBy,
        ?int $expiresInMinutes = null
    ): int {
        $messageId = trim($messageId);
        $content = trim($content);
        if ($messageId === '') {
            throw new \InvalidArgumentException('message_id is required');
        }
        if ($content === '') {
            throw new \InvalidArgumentException('content is required');
        }

        $expiresAt = null;
        if ($expiresInMinutes !== null && $expiresInMinutes > 0) {
            $expiresAt = (new \DateTimeImmutable("+{$expiresInMinutes} minutes"))->format('Y-m-d H:i:s');
        }

        $qb = $this->db->queryBuilder()->from('pinned_messages');
        $qb->upsert(
            [
                'message_id' => $messageId,
                'username'   => ($username !== null && $username !== '') ? mb_substr($username, 0, 50) : null,
                'content'    => mb_substr($content, 0, 2000),
                'pinned_by'  => mb_substr($pinnedBy !== '' ? $pinnedBy : 'admin', 0, 100),
                'created_at' => $qb->raw('NOW()'),
                'expires_at' => $expiresAt,
            ],
            ['message_id'],
            ['content', 'pinned_by', 'created_at', 'expires_at', 'username']
        );

        // compileUpsert has no RETURNING clause, so read the id back by message_id.
        $rows = $this->db->queryBuilder()->from('pinned_messages')
            ->where('message_id', '=', $messageId)->limit(1)->getAll();
        return isset($rows[0]['id']) ? (int) $rows[0]['id'] : 0;
    }

    /** Remove a pin by its id. Returns whether the id was valid. */
    public function unpin(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        $this->db->queryBuilder()->from('pinned_messages')->where('id', '=', $id)->delete();
        return true;
    }

    /** Remove a pin by the message it points at. */
    public function unpinByMessage(string $messageId): bool
    {
        $messageId = trim($messageId);
        if ($messageId === '') {
            return false;
        }
        $this->db->queryBuilder()->from('pinned_messages')->where('message_id', '=', $messageId)->delete();
        return true;
    }

    /**
     * The currently-active pins (not expired), newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function active(int $limit = 10): array
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $limit = max(1, min($limit, 50));
        // expires_at IS NULL OR expires_at > now
        $rows = $this->db->preparedQuery(
            'SELECT id, message_id, username, content, pinned_by, created_at, expires_at
             FROM pinned_messages
             WHERE expires_at IS NULL OR expires_at > :now
             ORDER BY created_at DESC
             LIMIT ' . $limit,
            ['now' => $now]
        );
        return $rows ? $rows->fetchAll() : [];
    }

    /** Delete every expired pin. Returns how many were removed. */
    public function purgeExpired(): int
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $result = $this->db->preparedQuery(
            'DELETE FROM pinned_messages WHERE expires_at IS NOT NULL AND expires_at <= :now',
            ['now' => $now]
        );
        return $result ? (int) $result->getAffectedRows() : 0;
    }
}
