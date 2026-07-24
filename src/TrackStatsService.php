<?php

namespace RadioChatBox;

use PDO;

/**
 * Records and queries the radio's track play history.
 *
 * A play is recorded whenever the now-playing track changes. Recording is
 * driven by the frequently-polled now-playing endpoint, so it is de-duplicated
 * via a Redis "last track" pointer plus a short lock to avoid double inserts
 * when many clients poll at the same moment.
 */
class TrackStatsService
{
    private PDO $pdo;
    private \Redis $redis;
    private string $prefix;

    public function __construct()
    {
        $this->pdo = Database::getPDO();
        $this->redis = Database::getRedis();
        $this->prefix = Database::getRedisPrefix();
    }

    /**
     * Record the current track if it differs from the last recorded one.
     * Safe to call on every now-playing poll.
     *
     * @param array $nowPlaying Result of RadioStatusService::getNowPlaying()
     */
    public function recordPlay(array $nowPlaying): void
    {
        if (empty($nowPlaying['active'])) {
            return;
        }
        $display = trim((string)($nowPlaying['display'] ?? ''));
        if ($display === '') {
            return;
        }

        $lastKey = $this->prefix . 'radio:last_track';

        try {
            if ($this->redis->get($lastKey) === $display) {
                return; // Same track still playing.
            }

            // De-dupe concurrent pollers on a track change with a short lock.
            $lockKey = $this->prefix . 'radio:record_lock';
            if (!$this->redis->set($lockKey, '1', ['nx', 'ex' => 10])) {
                return; // Another request is handling this change.
            }

            // Re-check inside the lock.
            if ($this->redis->get($lastKey) === $display) {
                $this->redis->del($lockKey);
                return;
            }

            $listeners = isset($nowPlaying['listeners']) && $nowPlaying['listeners'] !== null
                ? (int)$nowPlaying['listeners'] : null;

            $this->pdo->beginTransaction();

            // Upsert the unique track and bump its counters.
            $stmt = $this->pdo->prepare(
                'INSERT INTO tracks (artist, title, display, first_played_at, last_played_at, play_count)
                 VALUES (:artist, :title, :display, NOW(), NOW(), 1)
                 ON CONFLICT (display) DO UPDATE SET
                     last_played_at = NOW(),
                     play_count = tracks.play_count + 1,
                     artist = COALESCE(EXCLUDED.artist, tracks.artist),
                     title = COALESCE(EXCLUDED.title, tracks.title)
                 RETURNING id'
            );
            $stmt->execute([
                'artist' => $nowPlaying['artist'] ?? null,
                'title' => $nowPlaying['title'] ?? null,
                'display' => mb_substr($display, 0, 500),
            ]);
            $trackId = (int)$stmt->fetchColumn();

            // Record the individual play.
            $play = $this->pdo->prepare(
                'INSERT INTO track_plays (track_id, listeners) VALUES (:track_id, :listeners)'
            );
            $play->execute(['track_id' => $trackId, 'listeners' => $listeners]);

            $this->pdo->commit();

            $this->redis->set($lastKey, $display);
            $this->redis->del($lockKey);
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log('TrackStatsService::recordPlay failed: ' . $e->getMessage());
        }
    }

    /**
     * Play log for a given date (YYYY-MM-DD), most recent first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getLog(string $date, int $limit = 1000): array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT tp.id, t.id AS track_id, t.artist, t.title, t.display, tp.listeners, tp.played_at
                 FROM track_plays tp
                 JOIN tracks t ON tp.track_id = t.id
                 WHERE tp.played_at::date = :date
                 ORDER BY tp.played_at DESC
                 LIMIT :limit'
            );
            $stmt->bindValue(':date', $date);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log('TrackStatsService::getLog failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Most-played tracks between two timestamps (inclusive of `from`).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTopTracks(string $from, string $to, int $limit = 50): array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT t.id AS track_id,
                        t.display,
                        t.artist,
                        t.title,
                        COUNT(*)          AS plays,
                        MAX(tp.played_at) AS last_played,
                        MIN(tp.played_at) AS first_played
                 FROM track_plays tp
                 JOIN tracks t ON tp.track_id = t.id
                 WHERE tp.played_at >= :from AND tp.played_at < :to
                 GROUP BY t.id, t.display, t.artist, t.title
                 ORDER BY plays DESC, last_played DESC
                 LIMIT :limit'
            );
            $stmt->bindValue(':from', $from);
            $stmt->bindValue(':to', $to);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log('TrackStatsService::getTopTracks failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Most-played artists between two timestamps.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTopArtists(string $from, string $to, int $limit = 50): array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT t.artist,
                        COUNT(*)               AS plays,
                        COUNT(DISTINCT t.id)   AS tracks,
                        MAX(tp.played_at)      AS last_played
                 FROM track_plays tp
                 JOIN tracks t ON tp.track_id = t.id
                 WHERE tp.played_at >= :from AND tp.played_at < :to
                   AND t.artist IS NOT NULL AND t.artist <> \'\'
                 GROUP BY t.artist
                 ORDER BY plays DESC, last_played DESC
                 LIMIT :limit'
            );
            $stmt->bindValue(':from', $from);
            $stmt->bindValue(':to', $to);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log('TrackStatsService::getTopArtists failed: ' . $e->getMessage());
            return [];
        }
    }

    /** A single track's metadata, or null if not found. */
    public function getTrackById(int $trackId): ?array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, artist, title, display, first_played_at, last_played_at, play_count
                 FROM tracks WHERE id = :id'
            );
            $stmt->execute(['id' => $trackId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\PDOException $e) {
            error_log('TrackStatsService::getTrackById failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * All the times a given track played (reverse log for one track),
     * most recent first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTrackPlays(int $trackId, int $limit = 500): array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, listeners, played_at
                 FROM track_plays
                 WHERE track_id = :id
                 ORDER BY played_at DESC
                 LIMIT :limit'
            );
            $stmt->bindValue(':id', $trackId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log('TrackStatsService::getTrackPlays failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Summary statistics for the last N days: totals + top tracks + busiest day.
     */
    public function getSummary(int $days = 7, int $topLimit = 20): array
    {
        $days = max(1, min($days, 365));
        $from = (new \DateTimeImmutable("-{$days} days"))->format('Y-m-d 00:00:00');
        $to = (new \DateTimeImmutable('+1 day'))->format('Y-m-d 00:00:00');

        $totals = ['total_plays' => 0, 'unique_tracks' => 0];
        try {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) AS total_plays, COUNT(DISTINCT track_id) AS unique_tracks
                 FROM track_plays WHERE played_at >= :from AND played_at < :to'
            );
            $stmt->execute(['from' => $from, 'to' => $to]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $totals['total_plays'] = (int)$row['total_plays'];
                $totals['unique_tracks'] = (int)$row['unique_tracks'];
            }
        } catch (\PDOException $e) {
            error_log('TrackStatsService::getSummary totals failed: ' . $e->getMessage());
        }

        $perDay = [];
        try {
            $stmt = $this->pdo->prepare(
                'SELECT played_at::date AS day, COUNT(*) AS plays
                 FROM track_plays WHERE played_at >= :from AND played_at < :to
                 GROUP BY day ORDER BY day'
            );
            $stmt->execute(['from' => $from, 'to' => $to]);
            $perDay = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log('TrackStatsService::getSummary perDay failed: ' . $e->getMessage());
        }

        return [
            'from' => $from,
            'to' => $to,
            'days' => $days,
            'totals' => $totals,
            'per_day' => $perDay,
            'top_tracks' => $this->getTopTracks($from, $to, $topLimit),
        ];
    }
}
