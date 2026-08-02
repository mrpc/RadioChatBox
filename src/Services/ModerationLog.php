<?php

namespace RadioChatBox\Services;

use Pramnos\Database\Database as PramnosDatabase;

/**
 * Moderator audit trail. Records who did what moderation action; recording is
 * best-effort (a logging failure must never break the moderation action itself).
 */
class ModerationLog
{
    private PramnosDatabase $db;

    public function __construct()
    {
        $this->db = PramnosDatabase::getInstance();
    }

    /**
     * Record a moderation action. Best-effort — swallows its own errors.
     */
    public function record(string $adminUsername, string $action, ?string $target = null, ?string $details = null): void
    {
        try {
            $this->db->queryBuilder()->from('moderation_log')->insert([
                'admin_username' => $adminUsername !== '' ? mb_substr($adminUsername, 0, 100) : null,
                'action'         => mb_substr($action, 0, 50),
                'target'         => ($target !== null && $target !== '') ? mb_substr($target, 0, 150) : null,
                'details'        => ($details !== null && $details !== '') ? mb_substr($details, 0, 1000) : null,
            ]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('ModerationLog::record failed: ' . $e->getMessage(), 'radiochatbox');
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Recent moderation actions, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recent(int $limit = 100, int $offset = 0, string $action = ''): array
    {
        $limit = max(1, min($limit, 500));
        $offset = max(0, $offset);
        $qb = $this->db->queryBuilder()->from('moderation_log');
        if ($action !== '') {
            $qb->where('action', '=', $action);
        }
        return $qb->orderBy('created_at', 'desc')->limit($limit)->offset($offset)->getAll();
    }
}
