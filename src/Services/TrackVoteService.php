<?php

namespace RadioChatBox\Services;

use Pramnos\Database\Database as PramnosDatabase;

/**
 * Now-playing thumbs up/down. A listener votes on the currently-playing track
 * (identified by its display string); one vote per session per track, and
 * voting the same way again clears the vote (toggle).
 */
class TrackVoteService
{
    private PramnosDatabase $db;

    public function __construct()
    {
        $this->db = PramnosDatabase::getInstance();
    }

    /**
     * Register a vote for a track. Direction is 'up' or 'down'. Voting the same
     * direction again removes the vote (toggle off). Returns the fresh tally
     * including this voter's current vote.
     *
     * @return array{up:int, down:int, my_vote:int}
     * @throws \InvalidArgumentException on empty track/session or bad direction.
     */
    public function vote(string $trackDisplay, string $session, ?string $username, string $direction): array
    {
        $trackDisplay = trim($trackDisplay);
        $session = trim($session);
        if ($trackDisplay === '' || $session === '') {
            throw new \InvalidArgumentException('track and session are required');
        }
        $value = match ($direction) {
            'up'   => 1,
            'down' => -1,
            default => throw new \InvalidArgumentException('direction must be up or down'),
        };

        $trackDisplay = mb_substr($trackDisplay, 0, 500);
        $current = $this->currentVote($trackDisplay, $session);

        if ($current === $value) {
            // Same vote again → toggle it off.
            $this->db->preparedQuery(
                'DELETE FROM track_votes WHERE track_display = :d AND voter_session = :s',
                ['d' => $trackDisplay, 's' => $session]
            );
        } else {
            $qb = $this->db->queryBuilder()->from('track_votes');
            $qb->upsert(
                [
                    'track_display'  => $trackDisplay,
                    'track_id'       => $this->resolveTrackId($trackDisplay),
                    'voter_session'  => $session,
                    'voter_username' => ($username !== null && $username !== '') ? mb_substr($username, 0, 50) : null,
                    'vote'           => $value,
                    'created_at'     => $qb->raw('NOW()'),
                    'updated_at'     => $qb->raw('NOW()'),
                ],
                ['track_display', 'voter_session'],
                ['vote', 'voter_username', 'updated_at', 'track_id']
            );
        }

        return $this->tally($trackDisplay, $session);
    }

    /**
     * The up/down tally for a track, plus this session's current vote (0 if none).
     *
     * @return array{up:int, down:int, my_vote:int}
     */
    public function tally(string $trackDisplay, ?string $session = null): array
    {
        $trackDisplay = mb_substr(trim($trackDisplay), 0, 500);
        if ($trackDisplay === '') {
            return ['up' => 0, 'down' => 0, 'my_vote' => 0];
        }

        $row = $this->db->preparedQuery(
            'SELECT
                COALESCE(SUM(CASE WHEN vote > 0 THEN 1 ELSE 0 END), 0) AS up,
                COALESCE(SUM(CASE WHEN vote < 0 THEN 1 ELSE 0 END), 0) AS down
             FROM track_votes WHERE track_display = :d',
            ['d' => $trackDisplay]
        );
        $fields = $row ? $row->fetch() : null;

        return [
            'up'      => (int) ($fields['up'] ?? 0),
            'down'    => (int) ($fields['down'] ?? 0),
            'my_vote' => $session !== null ? $this->currentVote($trackDisplay, $session) : 0,
        ];
    }

    /**
     * Resolve the catalog track id for a display string (the tracker upserts
     * tracks by unique display), or null if it isn't catalogued yet. Links a vote
     * to the tracked play history so votes can join the track stats.
     */
    private function resolveTrackId(string $trackDisplay): ?int
    {
        $row = $this->db->preparedQuery(
            'SELECT id FROM tracks WHERE display = :d LIMIT 1',
            ['d' => $trackDisplay]
        );
        $id = $row ? $row->fetchColumn() : false;
        return $id === false || $id === null ? null : (int) $id;
    }

    /** This session's current vote for a track: 1, -1 or 0. */
    private function currentVote(string $trackDisplay, string $session): int
    {
        if (trim($session) === '') {
            return 0;
        }
        $row = $this->db->preparedQuery(
            'SELECT vote FROM track_votes WHERE track_display = :d AND voter_session = :s LIMIT 1',
            ['d' => $trackDisplay, 's' => $session]
        );
        $val = $row ? $row->fetchColumn() : false;
        return $val === false ? 0 : (int) $val;
    }
}
