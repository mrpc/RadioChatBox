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

    /**
     * Per-moderator action counts over the last $days, most active first. Each row
     * is {moderator, total, by_action:{action=>count}}. The synthetic 'auto-mod'
     * and 'system' actors are included so automated actions are visible too.
     *
     * @return array<int, array{moderator:string, total:int, by_action:array<string,int>}>
     */
    public function statsByModerator(int $days = 30): array
    {
        $days = max(1, min($days, 365));
        $since = date('Y-m-d H:i:s', time() - $days * 86400);

        $rows = $this->db->queryBuilder()
            ->from('moderation_log')
            ->select(['admin_username', 'action', 'COUNT(*) AS c'])
            ->whereRaw('created_at >= %s', [$since])
            ->groupBy(['admin_username', 'action'])
            ->getAll();

        $byMod = [];
        foreach ($rows as $row) {
            $mod = (string) ($row['admin_username'] ?? '') ?: 'unknown';
            $action = (string) $row['action'];
            $count = (int) $row['c'];
            if (!isset($byMod[$mod])) {
                $byMod[$mod] = ['moderator' => $mod, 'total' => 0, 'by_action' => []];
            }
            $byMod[$mod]['total'] += $count;
            $byMod[$mod]['by_action'][$action] = $count;
        }

        $out = array_values($byMod);
        usort($out, static fn ($a, $b): int => $b['total'] <=> $a['total']);
        return $out;
    }
}
