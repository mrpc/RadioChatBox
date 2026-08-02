<?php

namespace RadioChatBox\Services;

use Pramnos\Database\Database as PramnosDatabase;

/**
 * Listener song requests + dedications. A chat user asks for a track (optionally
 * with a dedication/shout-out); admins work a queue with approve / played /
 * reject actions. One row per request — the dedication rides on the same row.
 */
class SongRequestService
{
    private PramnosDatabase $db;

    /** Statuses a request can hold. */
    public const STATUSES = ['pending', 'approved', 'played', 'rejected'];

    /** Statuses an admin action may move a request to. */
    public const ADMIN_STATUSES = ['approved', 'played', 'rejected', 'pending'];

    public function __construct()
    {
        $this->db = PramnosDatabase::getInstance();
    }

    /**
     * File a request. Returns the new request id.
     *
     * @throws \InvalidArgumentException on an empty requester or song title.
     */
    public function create(
        string $requesterUsername,
        ?string $requesterSessionId,
        string $songTitle,
        ?string $artist = null,
        ?string $dedication = null,
        ?string $ipAddress = null
    ): int {
        $requesterUsername = trim($requesterUsername);
        $songTitle = trim($songTitle);
        if ($requesterUsername === '') {
            throw new \InvalidArgumentException('requester is required');
        }
        if ($songTitle === '') {
            throw new \InvalidArgumentException('song title is required');
        }

        $artist = ($artist !== null && trim($artist) !== '') ? mb_substr(trim($artist), 0, 300) : null;
        $dedication = ($dedication !== null && trim($dedication) !== '') ? mb_substr(trim($dedication), 0, 500) : null;

        $result = $this->db->queryBuilder()->from('song_requests')->returning('id')->insert([
            'requester_username'   => mb_substr($requesterUsername, 0, 100),
            'requester_session_id' => ($requesterSessionId !== null && $requesterSessionId !== '') ? $requesterSessionId : null,
            'song_title'           => mb_substr($songTitle, 0, 300),
            'artist'               => $artist,
            'dedication'           => $dedication,
            'ip_address'           => ($ipAddress !== null && $ipAddress !== '') ? $ipAddress : null,
        ]);

        return ($result && isset($result->fields['id'])) ? (int) $result->fields['id'] : 0;
    }

    /**
     * A page of requests, newest first, optionally filtered by status.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(string $status = 'pending', int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min($limit, 200));
        $offset = max(0, $offset);

        $qb = $this->db->queryBuilder()->from('song_requests');
        if (in_array($status, self::STATUSES, true)) {
            $qb->where('status', '=', $status);
        }
        return $qb->orderBy('created_at', 'desc')->limit($limit)->offset($offset)->getAll();
    }

    /** Count of requests in a given status (default pending). */
    public function count(string $status = 'pending'): int
    {
        $qb = $this->db->queryBuilder()->from('song_requests');
        if (in_array($status, self::STATUSES, true)) {
            $qb->where('status', '=', $status);
        }
        return $qb->count();
    }

    /**
     * Move a request to a new status. Returns whether the input was valid.
     */
    public function setStatus(int $id, string $status, string $adminUsername): bool
    {
        if ($id <= 0 || !in_array($status, self::ADMIN_STATUSES, true)) {
            return false;
        }
        $qb = $this->db->queryBuilder()->from('song_requests');
        $qb->where('id', '=', $id)->update([
            'status'     => $status,
            'handled_by' => $adminUsername !== '' ? mb_substr($adminUsername, 0, 100) : null,
            'updated_at' => $qb->raw('NOW()'),
        ]);
        return true;
    }

    /**
     * How many pending requests this session has filed in the last $minutes — a
     * cheap per-user flood guard for the public submit endpoint.
     */
    public function recentCountForSession(string $sessionId, int $minutes = 10): int
    {
        if (trim($sessionId) === '') {
            return 0;
        }
        $minutes = max(1, $minutes);
        $cutoff = (new \DateTimeImmutable("-{$minutes} minutes"))->format('Y-m-d H:i:s');
        return $this->db->queryBuilder()->from('song_requests')
            ->where('requester_session_id', '=', $sessionId)
            ->where('created_at', '>', $cutoff)
            ->count();
    }
}
