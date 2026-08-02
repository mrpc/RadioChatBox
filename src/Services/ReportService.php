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
