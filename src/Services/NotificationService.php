<?php

namespace RadioChatBox\Services;

use Pramnos\Database\Database as PramnosDatabase;

/**
 * Per-user notification inbox (persistent history + read/unread state). Producers
 * call add() when something happens directed at a user (a reaction to their
 * message, a mention…); the user's client reads the list and unread count and
 * marks items read. Capped per user so the table can't grow unbounded.
 */
class NotificationService
{
    /** Keep at most this many notifications per user (older ones are pruned). */
    private const MAX_PER_USER = 100;

    private PramnosDatabase $db;

    public function __construct()
    {
        $this->db = PramnosDatabase::getInstance();
    }

    /**
     * Record a notification for a user. Returns the new id (0 on no-op/failure).
     * Best-effort — never throws to the caller.
     */
    public function add(string $username, string $type, string $title, ?string $body = null, ?string $link = null): int
    {
        $username = trim($username);
        if ($username === '' || trim($title) === '') {
            return 0;
        }
        try {
            $result = $this->db->queryBuilder()->from('user_notifications')->returning('id')->insert([
                'username' => mb_substr($username, 0, 100),
                'type'     => mb_substr($type !== '' ? $type : 'system', 0, 40),
                'title'    => mb_substr($title, 0, 200),
                'body'     => ($body !== null && trim($body) !== '') ? mb_substr($body, 0, 500) : null,
                'link'     => ($link !== null && trim($link) !== '') ? mb_substr($link, 0, 255) : null,
            ]);
            $id = ($result && isset($result->fields['id'])) ? (int) $result->fields['id'] : 0;
            $this->pruneOld($username);
            return $id;
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('NotificationService::add failed: ' . $e->getMessage(), 'radiochatbox');
            return 0;
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * A user's notifications, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listFor(string $username, int $limit = 30, bool $unreadOnly = false): array
    {
        $username = trim($username);
        if ($username === '') {
            return [];
        }
        $qb = $this->db->queryBuilder()
            ->from('user_notifications')
            ->whereRaw('LOWER(username) = LOWER(%s)', [$username]);
        if ($unreadOnly) {
            $qb->where('is_read', '=', false);
        }
        return $qb->orderBy('created_at', 'desc')->limit(max(1, min($limit, 100)))->getAll();
    }

    /** Count of a user's unread notifications. */
    public function unreadCount(string $username): int
    {
        $username = trim($username);
        if ($username === '') {
            return 0;
        }
        return $this->db->queryBuilder()
            ->from('user_notifications')
            ->whereRaw('LOWER(username) = LOWER(%s)', [$username])
            ->where('is_read', '=', false)
            ->count();
    }

    /**
     * Mark a user's notifications read. With $id, only that one (and only if it's
     * theirs); without, all of them. Returns how many rows were affected.
     */
    public function markRead(string $username, ?int $id = null): int
    {
        $username = trim($username);
        if ($username === '') {
            return 0;
        }
        $qb = $this->db->queryBuilder()->from('user_notifications')
            ->whereRaw('LOWER(username) = LOWER(%s)', [$username])
            ->where('is_read', '=', false);
        if ($id !== null && $id > 0) {
            $qb->where('id', '=', $id);
        }
        $result = $qb->update(['is_read' => true]);
        return is_int($result) ? $result : ($result ? 1 : 0);
    }

    /** Keep only the newest MAX_PER_USER notifications for a user. */
    private function pruneOld(string $username): void
    {
        try {
            $this->db->preparedQuery(
                'DELETE FROM user_notifications
                 WHERE LOWER(username) = LOWER(:u)
                   AND id NOT IN (
                       SELECT id FROM user_notifications
                       WHERE LOWER(username) = LOWER(:u2)
                       ORDER BY created_at DESC LIMIT :keep
                   )',
                ['u' => $username, 'u2' => $username, 'keep' => self::MAX_PER_USER]
            );
        } catch (\Throwable $e) {
            // Non-fatal pruning.
        }
    }
}
