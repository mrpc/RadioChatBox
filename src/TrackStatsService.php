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
     * @return int|null The track id if a NEW play was recorded (track changed),
     *                  or null if unchanged/skipped. Lets the caller enrich the
     *                  just-recorded track promptly.
     */
    public function recordPlay(array $nowPlaying): ?int
    {
        if (empty($nowPlaying['active'])) {
            return null;
        }
        $display = trim((string)($nowPlaying['display'] ?? ''));
        if ($display === '') {
            return null;
        }

        $lastKey = $this->prefix . 'radio:last_track';

        // Atomic de-dup: record only when the track differs from the last one.
        // GETSET returns the previous value and sets the new one in a single
        // operation, so concurrent pollers can't both record the same change
        // (fixes duplicate log rows).
        try {
            $prev = $this->redis->getSet($lastKey, $display);
        } catch (\Throwable $e) {
            $prev = null;
        }
        if ($prev === $display) {
            return null; // Same track still playing (fast Redis check).
        }

        // Authoritative DB safeguard: if the most-recently recorded play is the
        // same track, do NOT record again. This does not rely on the Redis
        // pointer (which can be lost/flushed), so it prevents duplicate rows for
        // the same consecutive track regardless of Redis state.
        try {
            $stmt = $this->pdo->query(
                'SELECT t.display FROM track_plays tp
                 JOIN tracks t ON tp.track_id = t.id
                 ORDER BY tp.played_at DESC LIMIT 1'
            );
            $lastDisplay = $stmt->fetchColumn();
            if ($lastDisplay !== false && $lastDisplay === $display) {
                return null; // same as the previous recorded play
            }
        } catch (\PDOException $e) {
            // Non-fatal: fall through to record.
        }

        $listeners = isset($nowPlaying['listeners']) && $nowPlaying['listeners'] !== null
            ? (int)$nowPlaying['listeners'] : null;
        $artist = trim((string)($nowPlaying['artist'] ?? ''));
        $title = trim((string)($nowPlaying['title'] ?? ''));

        // Resolve/create the artist (by name) outside the transaction — fast,
        // no external calls. Deep metadata is filled later by enrichment.
        $artistId = $artist !== '' ? $this->upsertArtist($artist) : null;

        try {
            $this->pdo->beginTransaction();

            // Upsert the unique track and bump its counters.
            $stmt = $this->pdo->prepare(
                'INSERT INTO tracks (artist, title, display, artist_id, first_played_at, last_played_at, play_count)
                 VALUES (:artist, :title, :display, :artist_id, NOW(), NOW(), 1)
                 ON CONFLICT (display) DO UPDATE SET
                     last_played_at = NOW(),
                     play_count = tracks.play_count + 1,
                     artist = COALESCE(EXCLUDED.artist, tracks.artist),
                     title = COALESCE(EXCLUDED.title, tracks.title),
                     artist_id = COALESCE(tracks.artist_id, EXCLUDED.artist_id)
                 RETURNING id'
            );
            $stmt->execute([
                'artist' => $artist !== '' ? $artist : null,
                'title' => $title !== '' ? $title : null,
                'display' => mb_substr($display, 0, 500),
                'artist_id' => $artistId,
            ]);
            $trackId = (int)$stmt->fetchColumn();

            // Record the individual play.
            $play = $this->pdo->prepare(
                'INSERT INTO track_plays (track_id, listeners) VALUES (:track_id, :listeners)'
            );
            $play->execute(['track_id' => $trackId, 'listeners' => $listeners]);

            $this->pdo->commit();
            return $trackId;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log('TrackStatsService::recordPlay failed: ' . $e->getMessage());
            return null;
        }
    }

    /** Resolve or create an artist by name, returning its id. */
    private function upsertArtist(string $name): ?int
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO artists (name) VALUES (:name)
                 ON CONFLICT (name) DO UPDATE SET updated_at = NOW()
                 RETURNING id'
            );
            $stmt->execute(['name' => mb_substr($name, 0, 300)]);
            return (int)$stmt->fetchColumn();
        } catch (\PDOException $e) {
            error_log('TrackStatsService::upsertArtist failed: ' . $e->getMessage());
            return null;
        }
    }

    /** Resolve or create an album (by title + artist), filling metadata. */
    private function upsertAlbum(string $title, ?int $artistId, array $meta): ?int
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO albums (title, artist_id, cover_file, release_date, genre, source, external_id)
                 VALUES (:title, :artist_id, :cover, :rd, :genre, :src, :eid)
                 ON CONFLICT (title, artist_id) DO UPDATE SET
                     cover_file = COALESCE(EXCLUDED.cover_file, albums.cover_file),
                     release_date = COALESCE(EXCLUDED.release_date, albums.release_date),
                     genre = COALESCE(EXCLUDED.genre, albums.genre),
                     source = COALESCE(EXCLUDED.source, albums.source),
                     external_id = COALESCE(EXCLUDED.external_id, albums.external_id),
                     updated_at = NOW()
                 RETURNING id'
            );
            $stmt->execute([
                'title' => mb_substr($title, 0, 400),
                'artist_id' => $artistId,
                'cover' => $meta['cover'] ?? null,
                'rd' => $meta['release_date'] ?? null,
                'genre' => $meta['genre'] ?? null,
                'src' => $meta['source'] ?? null,
                'eid' => $meta['album_external_id'] ?? null,
            ]);
            return (int)$stmt->fetchColumn();
        } catch (\PDOException $e) {
            error_log('TrackStatsService::upsertAlbum failed: ' . $e->getMessage());
            return null;
        }
    }

    private function markEnriched(int $trackId): void
    {
        try {
            $this->pdo->prepare('UPDATE tracks SET enriched_at = NOW() WHERE id = :id')
                ->execute(['id' => $trackId]);
        } catch (\PDOException $e) {
            error_log('TrackStatsService::markEnriched failed: ' . $e->getMessage());
        }
    }

    /**
     * Fetch and store external metadata (album, genre, release date, covers,
     * links) for one track. Marks the track enriched afterwards regardless, so
     * it is not retried automatically (admin can re-fetch manually).
     */
    public function enrichTrack(int $trackId): bool
    {
        $track = $this->getTrackById($trackId);
        if (!$track) {
            return false;
        }
        $artist = trim((string)($track['artist'] ?? ''));
        $title = trim((string)($track['title'] ?? ''));
        if ($artist === '' && $title === '') {
            $this->markEnriched($trackId);
            return false;
        }

        $meta = (new ArtworkService())->getDeepMeta($artist, $title);
        $artistId = $track['artist_id'] ?? ($artist !== '' ? $this->upsertArtist($artist) : null);

        // Enrich the artist row (image + external ids).
        if ($artistId) {
            try {
                $this->pdo->prepare(
                    'UPDATE artists SET
                        image_file = COALESCE(:img, image_file),
                        source = COALESCE(:src, source),
                        external_id = COALESCE(:eid, external_id),
                        updated_at = NOW()
                     WHERE id = :id'
                )->execute([
                    'img' => $meta['artist_image'] ?? null,
                    'src' => $meta['source'] ?? null,
                    'eid' => $meta['artist_external_id'] ?? null,
                    'id' => $artistId,
                ]);
            } catch (\PDOException $e) {
                error_log('enrichTrack artist update failed: ' . $e->getMessage());
            }
        }

        // Album row.
        $albumId = null;
        if (!empty($meta['album_title'])) {
            $albumId = $this->upsertAlbum($meta['album_title'], $artistId, $meta);
        }

        // Track row.
        try {
            $this->pdo->prepare(
                'UPDATE tracks SET
                    artist_id = COALESCE(artist_id, :aid),
                    album_id = COALESCE(:album_id, album_id),
                    genre = COALESCE(:genre, genre),
                    cover_file = COALESCE(:cover, cover_file),
                    release_date = COALESCE(:rd, release_date),
                    source = COALESCE(:src, source),
                    external_id = COALESCE(:eid, external_id),
                    external_url = COALESCE(:url, external_url),
                    enriched_at = NOW()
                 WHERE id = :id'
            )->execute([
                'aid' => $artistId,
                'album_id' => $albumId,
                'genre' => $meta['genre'] ?? null,
                'cover' => $meta['cover'] ?? null,
                'rd' => $meta['release_date'] ?? null,
                'src' => $meta['source'] ?? null,
                'eid' => $meta['track_external_id'] ?? null,
                'url' => $meta['track_url'] ?? null,
                'id' => $trackId,
            ]);
        } catch (\PDOException $e) {
            error_log('enrichTrack track update failed: ' . $e->getMessage());
            $this->markEnriched($trackId);
            return false;
        }

        return true;
    }

    /**
     * Enrich up to $limit tracks that have not been enriched yet
     * (most-recently-played first). Returns how many were processed.
     */
    public function enrichPending(int $limit = 5): int
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id FROM tracks WHERE enriched_at IS NULL
                 ORDER BY last_played_at DESC LIMIT :limit'
            );
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (\PDOException $e) {
            error_log('TrackStatsService::enrichPending failed: ' . $e->getMessage());
            return 0;
        }

        $count = 0;
        foreach ($ids as $id) {
            if ($this->enrichTrack((int)$id)) {
                $count++;
            }
        }
        return $count;
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
                 LEFT JOIN artists ar ON t.artist_id = ar.id
                 WHERE tp.played_at >= :from AND tp.played_at < :to
                   AND t.excluded_from_stats = FALSE
                   AND (ar.excluded_from_stats IS NULL OR ar.excluded_from_stats = FALSE)
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
                        MAX(ar.image_file)     AS image_file,
                        COUNT(*)               AS plays,
                        COUNT(DISTINCT t.id)   AS tracks,
                        MAX(tp.played_at)      AS last_played
                 FROM track_plays tp
                 JOIN tracks t ON tp.track_id = t.id
                 LEFT JOIN artists ar ON t.artist_id = ar.id
                 WHERE tp.played_at >= :from AND tp.played_at < :to
                   AND t.artist IS NOT NULL AND t.artist <> \'\'
                   AND t.excluded_from_stats = FALSE
                   AND (ar.excluded_from_stats IS NULL OR ar.excluded_from_stats = FALSE)
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

    /** Most-played genres over a window (excludes flagged tracks/artists). */
    public function getTopGenres(string $from, string $to, int $limit = 50): array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT t.genre,
                        COUNT(*)             AS plays,
                        COUNT(DISTINCT t.id) AS tracks,
                        MAX(tp.played_at)    AS last_played
                 FROM track_plays tp
                 JOIN tracks t ON tp.track_id = t.id
                 LEFT JOIN artists ar ON t.artist_id = ar.id
                 WHERE tp.played_at >= :from AND tp.played_at < :to
                   AND t.genre IS NOT NULL AND t.genre <> \'\'
                   AND t.excluded_from_stats = FALSE
                   AND (ar.excluded_from_stats IS NULL OR ar.excluded_from_stats = FALSE)
                 GROUP BY t.genre
                 ORDER BY plays DESC, last_played DESC
                 LIMIT :limit'
            );
            $stmt->bindValue(':from', $from);
            $stmt->bindValue(':to', $to);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log('TrackStatsService::getTopGenres failed: ' . $e->getMessage());
            return [];
        }
    }

    /** Most-played albums over a window (excludes flagged tracks/artists). */
    public function getTopAlbums(string $from, string $to, int $limit = 50): array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT al.id AS album_id, al.title, al.cover_file, al.release_date,
                        ar.name AS artist_name,
                        COUNT(*)             AS plays,
                        COUNT(DISTINCT t.id) AS tracks,
                        MAX(tp.played_at)    AS last_played
                 FROM track_plays tp
                 JOIN tracks t ON tp.track_id = t.id
                 JOIN albums al ON t.album_id = al.id
                 LEFT JOIN artists ar ON al.artist_id = ar.id
                 WHERE tp.played_at >= :from AND tp.played_at < :to
                   AND t.excluded_from_stats = FALSE
                   AND (ar.excluded_from_stats IS NULL OR ar.excluded_from_stats = FALSE)
                 GROUP BY al.id, al.title, al.cover_file, al.release_date, ar.name
                 ORDER BY plays DESC, last_played DESC
                 LIMIT :limit'
            );
            $stmt->bindValue(':from', $from);
            $stmt->bindValue(':to', $to);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log('TrackStatsService::getTopAlbums failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Update editable track metadata from the admin panel.
     * Accepts keys: genre, release_date, external_url, excluded_from_stats,
     * album_title (upserts/links an album).
     */
    public function updateTrackMeta(int $trackId, array $data): bool
    {
        try {
            $track = $this->getTrackById($trackId);
            if (!$track) {
                return false;
            }

            if (array_key_exists('album_title', $data)) {
                $albumTitle = trim((string)$data['album_title']);
                if ($albumTitle !== '') {
                    $albumId = $this->upsertAlbum($albumTitle, $track['artist_id'] ?? null, []);
                    $this->pdo->prepare('UPDATE tracks SET album_id = :aid WHERE id = :id')
                        ->execute(['aid' => $albumId, 'id' => $trackId]);
                }
            }

            $sets = [];
            $params = ['id' => $trackId];
            foreach (['genre', 'release_date', 'external_url'] as $col) {
                if (array_key_exists($col, $data)) {
                    $val = trim((string)$data[$col]);
                    $sets[] = "$col = :$col";
                    $params[$col] = $val === '' ? null : $val;
                }
            }
            if (array_key_exists('excluded_from_stats', $data)) {
                $sets[] = 'excluded_from_stats = :excl';
                // Bind as 'true'/'false' text: PDO turns a PHP false into '' which
                // PostgreSQL rejects for a boolean column.
                $params['excl'] = !empty($data['excluded_from_stats']) ? 'true' : 'false';
            }
            if ($sets) {
                $this->pdo->prepare('UPDATE tracks SET ' . implode(', ', $sets) . ' WHERE id = :id')
                    ->execute($params);
            }
            return true;
        } catch (\PDOException $e) {
            error_log('TrackStatsService::updateTrackMeta failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Update editable artist metadata. Accepts: genre, external_url,
     * excluded_from_stats.
     */
    public function updateArtistMeta(int $artistId, array $data): bool
    {
        try {
            $sets = [];
            $params = ['id' => $artistId];
            foreach (['genre', 'external_url'] as $col) {
                if (array_key_exists($col, $data)) {
                    $val = trim((string)$data[$col]);
                    $sets[] = "$col = :$col";
                    $params[$col] = $val === '' ? null : $val;
                }
            }
            if (array_key_exists('excluded_from_stats', $data)) {
                $sets[] = 'excluded_from_stats = :excl';
                // 'true'/'false' text — PDO binds PHP false as '' which PG rejects.
                $params['excl'] = !empty($data['excluded_from_stats']) ? 'true' : 'false';
            }
            if (!$sets) {
                return true;
            }
            $sets[] = 'updated_at = NOW()';
            $this->pdo->prepare('UPDATE artists SET ' . implode(', ', $sets) . ' WHERE id = :id')
                ->execute($params);
            return true;
        } catch (\PDOException $e) {
            error_log('TrackStatsService::updateArtistMeta failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Make sure an artist has a stored image: if missing, look it up once and
     * persist it so it appears in the lists (not only the live detail view).
     * Returns the stored/looked-up image path or null.
     */
    public function ensureArtistImage(string $artist, bool $force = false): ?string
    {
        $row = $this->getArtistRowByName($artist);
        if (!$row) {
            return null;
        }
        if (!$force && !empty($row['image_file'])) {
            return $row['image_file'];
        }
        $img = (new ArtworkService())->getArtistImage($artist, $force);
        $file = $img['artist_image'] ?? null;
        if ($file) {
            try {
                $this->pdo->prepare('UPDATE artists SET image_file = :f, updated_at = NOW() WHERE id = :id')
                    ->execute(['f' => $file, 'id' => $row['id']]);
            } catch (\PDOException $e) {
                error_log('TrackStatsService::ensureArtistImage failed: ' . $e->getMessage());
            }
        }
        return $file;
    }

    /** The artists row for a given name (id, editable meta), or null. */
    public function getArtistRowByName(string $name): ?array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, name, genre, external_url, image_file, excluded_from_stats
                 FROM artists WHERE name = :name'
            );
            $stmt->execute(['name' => $name]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (\PDOException $e) {
            error_log('TrackStatsService::getArtistRowByName failed: ' . $e->getMessage());
            return null;
        }
    }

    /** All-time summary for one artist (plays, distinct tracks, first/last). */
    public function getArtistSummary(string $artist): ?array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) AS plays, COUNT(DISTINCT t.id) AS tracks,
                        MIN(tp.played_at) AS first_played, MAX(tp.played_at) AS last_played
                 FROM track_plays tp JOIN tracks t ON tp.track_id = t.id
                 WHERE t.artist = :artist'
            );
            $stmt->execute(['artist' => $artist]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || (int)$row['plays'] === 0) {
                return null;
            }
            return $row;
        } catch (\PDOException $e) {
            error_log('TrackStatsService::getArtistSummary failed: ' . $e->getMessage());
            return null;
        }
    }

    /** Tracks by one artist with play counts (all-time), most-played first. */
    public function getArtistTracks(string $artist, int $limit = 200): array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT t.id AS track_id, t.display, t.title,
                        COUNT(*) AS plays, MAX(tp.played_at) AS last_played
                 FROM track_plays tp JOIN tracks t ON tp.track_id = t.id
                 WHERE t.artist = :artist
                 GROUP BY t.id, t.display, t.title
                 ORDER BY plays DESC, last_played DESC
                 LIMIT :limit'
            );
            $stmt->bindValue(':artist', $artist);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log('TrackStatsService::getArtistTracks failed: ' . $e->getMessage());
            return [];
        }
    }

    /** A single track's metadata, or null if not found. */
    public function getTrackById(int $trackId): ?array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT t.id, t.artist, t.title, t.display, t.first_played_at, t.last_played_at,
                        t.play_count, t.artist_id, t.album_id, t.genre, t.cover_file, t.release_date,
                        t.external_url, t.excluded_from_stats, t.enriched_at,
                        al.title AS album_title, al.cover_file AS album_cover_file,
                        ar.name AS artist_name, ar.image_file AS artist_image_file
                 FROM tracks t
                 LEFT JOIN albums al ON t.album_id = al.id
                 LEFT JOIN artists ar ON t.artist_id = ar.id
                 WHERE t.id = :id'
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

        // The currently/most-recently played track (for a "now playing" link).
        $current = null;
        try {
            $stmt = $this->pdo->query(
                'SELECT t.id, t.display FROM track_plays tp
                 JOIN tracks t ON tp.track_id = t.id
                 ORDER BY tp.played_at DESC LIMIT 1'
            );
            $current = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (\PDOException $e) {
            error_log('TrackStatsService::getSummary current failed: ' . $e->getMessage());
        }

        return [
            'from' => $from,
            'to' => $to,
            'days' => $days,
            'totals' => $totals,
            'per_day' => $perDay,
            'current' => $current,
            'top_tracks' => $this->getTopTracks($from, $to, $topLimit),
        ];
    }

    /**
     * Stored metadata for the currently-playing track (by its display string),
     * for the front-end hover card. Cover falls back track → album → artist.
     *
     * @return array{artist:?string, album:?string, year:?string, genre:?string, cover:?string}|null
     */
    public function getCurrentTrackMeta(string $display): ?array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT t.artist, t.genre, t.release_date, t.cover_file,
                        al.title AS album_title, al.release_date AS album_release_date, al.cover_file AS album_cover,
                        ar.image_file AS artist_image
                 FROM tracks t
                 LEFT JOIN albums al ON t.album_id = al.id
                 LEFT JOIN artists ar ON t.artist_id = ar.id
                 WHERE t.display = :d
                 LIMIT 1'
            );
            $stmt->execute(['d' => $display]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }
            $rd = $row['release_date'] ?: $row['album_release_date'];
            $year = ($rd && preg_match('/^(\d{4})/', (string)$rd, $m)) ? $m[1] : null;
            return [
                'artist' => $row['artist'] ?: null,
                'album' => $row['album_title'] ?: null,
                'year' => $year,
                'genre' => $row['genre'] ?: null,
                'cover' => $row['cover_file'] ?: ($row['album_cover'] ?: ($row['artist_image'] ?: null)),
            ];
        } catch (\PDOException $e) {
            error_log('TrackStatsService::getCurrentTrackMeta failed: ' . $e->getMessage());
            return null;
        }
    }

    /** Distinct genres already present in the database (tracks + albums). */
    public function getGenreList(): array
    {
        try {
            $stmt = $this->pdo->query(
                "SELECT DISTINCT genre FROM (
                     SELECT genre FROM tracks WHERE genre IS NOT NULL AND genre <> ''
                     UNION
                     SELECT genre FROM albums WHERE genre IS NOT NULL AND genre <> ''
                 ) g ORDER BY genre ASC"
            );
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (\PDOException $e) {
            error_log('TrackStatsService::getGenreList failed: ' . $e->getMessage());
            return [];
        }
    }

    /** Every artist, alphabetically, with all-time play/track counts. */
    public function getAllArtists(): array
    {
        try {
            // Derived from tracks.artist so it includes every artist that has
            // played (even legacy tracks not yet linked to an artists row),
            // matching the Top Artists list. Image comes from the linked row.
            $stmt = $this->pdo->query(
                'SELECT t.artist AS name,
                        MAX(ar.image_file)   AS image_file,
                        COUNT(DISTINCT t.id) AS tracks,
                        COUNT(tp.id)         AS plays
                 FROM tracks t
                 LEFT JOIN artists ar ON t.artist_id = ar.id
                 LEFT JOIN track_plays tp ON tp.track_id = t.id
                 WHERE t.artist IS NOT NULL AND t.artist <> \'\'
                 GROUP BY t.artist
                 ORDER BY LOWER(t.artist) ASC'
            );
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log('TrackStatsService::getAllArtists failed: ' . $e->getMessage());
            return [];
        }
    }

    /** Albums by an artist (all-time play counts), most-played first. */
    public function getAlbumsByArtist(string $artist): array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT al.id AS album_id, al.title, al.cover_file, al.release_date,
                        COUNT(tp.id) AS plays, COUNT(DISTINCT t.id) AS tracks
                 FROM albums al
                 JOIN artists ar ON al.artist_id = ar.id
                 LEFT JOIN tracks t ON t.album_id = al.id
                 LEFT JOIN track_plays tp ON tp.track_id = t.id
                 WHERE ar.name = :artist
                 GROUP BY al.id, al.title, al.cover_file, al.release_date
                 ORDER BY plays DESC, al.title ASC'
            );
            $stmt->execute(['artist' => $artist]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log('TrackStatsService::getAlbumsByArtist failed: ' . $e->getMessage());
            return [];
        }
    }

    /** Album detail: the album row + its tracks with play counts. */
    public function getAlbumDetail(int $albumId): ?array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT al.id, al.title, al.cover_file, al.release_date, al.genre, al.external_url,
                        ar.name AS artist_name
                 FROM albums al LEFT JOIN artists ar ON al.artist_id = ar.id
                 WHERE al.id = :id'
            );
            $stmt->execute(['id' => $albumId]);
            $album = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$album) {
                return null;
            }

            $t = $this->pdo->prepare(
                'SELECT t.id AS track_id, t.display, COUNT(tp.id) AS plays, MAX(tp.played_at) AS last_played
                 FROM tracks t LEFT JOIN track_plays tp ON tp.track_id = t.id
                 WHERE t.album_id = :id
                 GROUP BY t.id, t.display
                 ORDER BY plays DESC, t.display ASC'
            );
            $t->execute(['id' => $albumId]);

            return ['album' => $album, 'tracks' => $t->fetchAll(PDO::FETCH_ASSOC)];
        } catch (\PDOException $e) {
            error_log('TrackStatsService::getAlbumDetail failed: ' . $e->getMessage());
            return null;
        }
    }
}
