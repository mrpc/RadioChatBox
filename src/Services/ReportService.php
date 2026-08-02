<?php

namespace RadioChatBox\Services;

use Pramnos\Database\Database as PramnosDatabase;

/**
 * User-driven abuse reports. A user flags a message (public or DM); admins work a
 * queue with resolve/dismiss actions. The reported content is snapshotted at
 * report time so it survives deletion of the original message.
 */
class ReportService
{
    private PramnosDatabase $db;

    /** Report categories (server-enforced whitelist). */
    public const ALLOWED_REASONS = [
        'spam',
        'harassment',
        'inappropriate',
        'offensive',
        'impersonation',
        'other',
    ];

    /** Valid statuses a report can hold. */
    public const STATUSES = ['pending', 'resolved', 'dismissed'];

    public function __construct()
    {
        $this->db = PramnosDatabase::getInstance();
    }

    public static function isValidReason(string $reason): bool
    {
        return in_array($reason, self::ALLOWED_REASONS, true);
    }

    /**
     * File a report. Returns the new report id.
     *
     * @throws \InvalidArgumentException on an empty reporter or a bad reason.
     */
    public function create(
        string $reporterUsername,
        ?string $reporterSessionId,
        ?string $messageId,
        string $messageType,
        ?string $reportedUsername,
        string $reason,
        ?string $details = null,
        ?string $contentSnapshot = null
    ): int {
        $reporterUsername = trim($reporterUsername);
        if ($reporterUsername === '') {
            throw new \InvalidArgumentException('reporter is required');
        }
        if (!self::isValidReason($reason)) {
            throw new \InvalidArgumentException('invalid reason');
        }
        $messageType = $messageType === 'private' ? 'private' : 'public';

        $result = $this->db->queryBuilder()->from('message_reports')->returning('id')->insert([
            'message_id'          => ($messageId !== null && $messageId !== '') ? $messageId : null,
            'message_type'        => $messageType,
            'reported_username'   => ($reportedUsername !== null && $reportedUsername !== '') ? $reportedUsername : null,
            'reporter_username'   => $reporterUsername,
            'reporter_session_id' => ($reporterSessionId !== null && $reporterSessionId !== '') ? $reporterSessionId : null,
            'reason'              => $reason,
            'details'             => ($details !== null && trim($details) !== '') ? mb_substr(trim($details), 0, 1000) : null,
            'content_snapshot'    => ($contentSnapshot !== null && $contentSnapshot !== '') ? mb_substr($contentSnapshot, 0, 2000) : null,
        ]);

        return ($result && isset($result->fields['id'])) ? (int) $result->fields['id'] : 0;
    }

    /**
     * A page of reports, newest first, optionally filtered by status.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(string $status = 'pending', int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min($limit, 200));
        $offset = max(0, $offset);

        $qb = $this->db->queryBuilder()->from('message_reports');
        if (in_array($status, self::STATUSES, true)) {
            $qb->where('status', '=', $status);
        }
        return $qb->orderBy('created_at', 'desc')->limit($limit)->offset($offset)->getAll();
    }

    /** Count of reports in a given status (default pending). */
    public function count(string $status = 'pending'): int
    {
        $qb = $this->db->queryBuilder()->from('message_reports');
        if (in_array($status, self::STATUSES, true)) {
            $qb->where('status', '=', $status);
        }
        return $qb->count();
    }

    /**
     * Resolve or dismiss a report, optionally with a moderator note. Returns
     * whether the input was valid (a row was targeted).
     */
    public function setStatus(int $id, string $status, string $adminUsername, ?string $note = null): bool
    {
        if ($id <= 0 || !in_array($status, ['resolved', 'dismissed'], true)) {
            return false;
        }
        $qb = $this->db->queryBuilder()->from('message_reports');
        $qb->where('id', '=', $id)->update([
            'status'          => $status,
            'resolved_by'     => $adminUsername !== '' ? $adminUsername : null,
            'resolved_at'     => $qb->raw('NOW()'),
            'resolution_note' => ($note !== null && trim($note) !== '') ? mb_substr(trim($note), 0, 1000) : null,
        ]);
        return true;
    }

    /**
     * Aggregate report statistics over the last $days: totals by status, by
     * reason, and the most-reported users. Used by the admin reports dashboard.
     *
     * @return array{
     *   window_days:int, total:int,
     *   by_status:array<string,int>, by_reason:array<string,int>,
     *   top_reported:array<int, array{username:string, count:int}>
     * }
     */
    public function stats(int $days = 30): array
    {
        $days = max(1, min($days, 365));
        $since = date('Y-m-d H:i:s', time() - $days * 86400);

        $byStatus = array_fill_keys(self::STATUSES, 0);
        foreach ($this->groupCount('status', $since) as $row) {
            $key = (string) $row['k'];
            $byStatus[$key] = (int) $row['c'];
        }

        $byReason = array_fill_keys(self::ALLOWED_REASONS, 0);
        foreach ($this->groupCount('reason', $since) as $row) {
            $key = (string) $row['k'];
            if (array_key_exists($key, $byReason)) {
                $byReason[$key] = (int) $row['c'];
            }
        }

        $topReported = [];
        $rows = $this->db->queryBuilder()
            ->from('message_reports')
            ->select(['reported_username AS k', 'COUNT(*) AS c'])
            ->whereRaw('reported_username IS NOT NULL')
            ->whereRaw('created_at >= %s', [$since])
            ->groupBy(['reported_username'])
            ->orderBy('c', 'desc')
            ->limit(10)
            ->getAll();
        foreach ($rows as $row) {
            $topReported[] = ['username' => (string) $row['k'], 'count' => (int) $row['c']];
        }

        return [
            'window_days'  => $days,
            'total'        => array_sum($byStatus),
            'by_status'    => $byStatus,
            'by_reason'    => $byReason,
            'top_reported' => $topReported,
        ];
    }

    /**
     * COUNT(*) grouped by one column since a cutoff.
     *
     * @return array<int, array{k:mixed, c:mixed}>
     */
    private function groupCount(string $column, string $since): array
    {
        return $this->db->queryBuilder()
            ->from('message_reports')
            ->select([$column . ' AS k', 'COUNT(*) AS c'])
            ->whereRaw('created_at >= %s', [$since])
            ->groupBy([$column])
            ->getAll();
    }

    /**
     * Report-handling stats over the last $days: how many reports were resolved
     * or dismissed, the average time-to-resolution (seconds), and a per-resolver
     * breakdown. Backs the moderator performance dashboard.
     *
     * @return array{
     *   window_days:int, handled:int, avg_seconds:int,
     *   by_resolver:array<int, array{resolver:string, handled:int, avg_seconds:int}>
     * }
     */
    public function resolutionStats(int $days = 30): array
    {
        $days = max(1, min($days, 365));
        $since = date('Y-m-d H:i:s', time() - $days * 86400);

        $overall = $this->db->preparedQuery(
            "SELECT COUNT(*) AS handled,
                    COALESCE(AVG(EXTRACT(EPOCH FROM (resolved_at - created_at))), 0) AS avg_seconds
             FROM message_reports
             WHERE status IN ('resolved', 'dismissed')
               AND resolved_at IS NOT NULL
               AND resolved_at >= :since",
            ['since' => $since]
        );
        $overallRow = ($overall && $overall->numRows > 0) ? $overall->fields : ['handled' => 0, 'avg_seconds' => 0];

        $perResolver = $this->db->preparedQuery(
            "SELECT COALESCE(resolved_by, 'unknown') AS resolver,
                    COUNT(*) AS handled,
                    COALESCE(AVG(EXTRACT(EPOCH FROM (resolved_at - created_at))), 0) AS avg_seconds
             FROM message_reports
             WHERE status IN ('resolved', 'dismissed')
               AND resolved_at IS NOT NULL
               AND resolved_at >= :since
             GROUP BY resolved_by
             ORDER BY handled DESC",
            ['since' => $since]
        );
        $byResolver = [];
        foreach (($perResolver ? $perResolver->fetchAll() : []) as $row) {
            $byResolver[] = [
                'resolver'    => (string) $row['resolver'],
                'handled'     => (int) $row['handled'],
                'avg_seconds' => (int) round((float) $row['avg_seconds']),
            ];
        }

        return [
            'window_days' => $days,
            'handled'     => (int) $overallRow['handled'],
            'avg_seconds' => (int) round((float) $overallRow['avg_seconds']),
            'by_resolver' => $byResolver,
        ];
    }

    /** Count of PENDING reports filed against a user (case-insensitive). */
    public function countPendingAgainst(string $username): int
    {
        if (trim($username) === '') {
            return 0;
        }
        return $this->db->queryBuilder()
            ->from('message_reports')
            ->whereRaw('LOWER(reported_username) = LOWER(%s)', [$username])
            ->where('status', '=', 'pending')
            ->count();
    }

    /** A single report by id, or null. */
    public function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $rows = $this->db->queryBuilder()->from('message_reports')->where('id', '=', $id)->limit(1)->getAll();
        return $rows[0] ?? null;
    }

    /**
     * Mark several reports handled in one call. Invalid ids/statuses are ignored.
     * Returns the number of reports updated.
     *
     * @param array<int, int|string> $ids
     */
    public function setStatusBulk(array $ids, string $status, string $adminUsername, ?string $note = null): int
    {
        $updated = 0;
        foreach ($ids as $id) {
            if ($this->setStatus((int) $id, $status, $adminUsername, $note)) {
                $updated++;
            }
        }
        return $updated;
    }

    /**
     * All reports filed AGAINST a user (newest first), for the admin user-details
     * dossier. Capped.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forReportedUser(string $username, int $limit = 100): array
    {
        if (trim($username) === '') {
            return [];
        }
        return $this->db->queryBuilder()
            ->from('message_reports')
            ->where('reported_username', '=', $username)
            ->orderBy('created_at', 'desc')
            ->limit(max(1, min($limit, 200)))
            ->getAll();
    }
}
