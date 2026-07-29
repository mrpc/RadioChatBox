<?php

namespace RadioChatBox\Services;

use Pramnos\Cache\FlatCache;
use RadioChatBox\Database;
use Pramnos\Database\Database as PramnosDatabase;

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
    private PramnosDatabase $db;

    public function __construct()
    {
        $this->db = Database::getDb();
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

        // Atomic de-dup: record only when the track differs from the last one.
        // Cache::swap() (native Redis GETSET) returns the previous value and sets
        // the new one in a single operation, so concurrent pollers can't both
        // record the same change (fixes duplicate log rows).
        try {
            $prev = FlatCache::default()->swap('radio:last_track', $display);
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
            $lastDisplay = $this->db->query(
                'SELECT t.display FROM track_plays tp
                 JOIN tracks t ON tp.track_id = t.id
                 ORDER BY tp.played_at DESC LIMIT 1'
            )->fetchColumn();
            if ($lastDisplay !== false && $lastDisplay === $display) {
                return null; // same as the previous recorded play
            }
        } catch (\Throwable $e) {
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
            $this->db->startTransaction();

            // Upsert the unique track and bump its counters (verbatim — the
            // COALESCE/EXCLUDED merge in DO UPDATE is kept as-is).
            $trackId = (int) $this->db->preparedQuery(
                'INSERT INTO tracks (artist, title, display, artist_id, first_played_at, last_played_at, play_count)
                 VALUES (:artist, :title, :display, :artist_id, NOW(), NOW(), 1)
                 ON CONFLICT (display) DO UPDATE SET
                     last_played_at = NOW(),
                     play_count = tracks.play_count + 1,
                     artist = COALESCE(EXCLUDED.artist, tracks.artist),
                     title = COALESCE(EXCLUDED.title, tracks.title),
                     artist_id = COALESCE(tracks.artist_id, EXCLUDED.artist_id)
                 RETURNING id',
                [
                    'artist' => $artist !== '' ? $artist : null,
                    'title' => $title !== '' ? $title : null,
                    'display' => mb_substr($display, 0, 500),
                    'artist_id' => $artistId,
                ]
            )->fetchColumn();

            // Record the individual play.
            $this->db->queryBuilder()->from('track_plays')->insert([
                'track_id'  => $trackId,
                'listeners' => $listeners,
            ]);

            $this->db->commitTransaction();
            return $trackId;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollbackTransaction();
            }
            \Pramnos\Logs\Logger::log('TrackStatsService::recordPlay failed: ' . $e->getMessage(), 'radiochatbox');
            return null;
        }
    }

    /** Resolve or create an artist by name, returning its id. */
    private function upsertArtist(string $name): ?int
    {
        try {
            return (int) $this->db->preparedQuery(
                'INSERT INTO artists (name) VALUES (:name)
                 ON CONFLICT (name) DO UPDATE SET updated_at = NOW()
                 RETURNING id',
                ['name' => mb_substr($name, 0, 300)]
            )->fetchColumn();
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('TrackStatsService::upsertArtist failed: ' . $e->getMessage(), 'radiochatbox');
            return null;
        }
    }

    /** Resolve or create an album (by title + artist), filling metadata. */
    private function upsertAlbum(string $title, ?int $artistId, array $meta): ?int
    {
        try {
            return (int) $this->db->preparedQuery(
                'INSERT INTO albums (title, artist_id, cover_file, release_date, genre, source, external_id)
                 VALUES (:title, :artist_id, :cover, :rd, :genre, :src, :eid)
                 ON CONFLICT (title, artist_id) DO UPDATE SET
                     cover_file = COALESCE(EXCLUDED.cover_file, albums.cover_file),
                     release_date = COALESCE(EXCLUDED.release_date, albums.release_date),
                     genre = COALESCE(EXCLUDED.genre, albums.genre),
                     source = COALESCE(EXCLUDED.source, albums.source),
                     external_id = COALESCE(EXCLUDED.external_id, albums.external_id),
                     updated_at = NOW()
                 RETURNING id',
                [
                    'title' => mb_substr($title, 0, 400),
                    'artist_id' => $artistId,
                    'cover' => $meta['cover'] ?? null,
                    'rd' => $meta['release_date'] ?? null,
                    'genre' => $meta['genre'] ?? null,
                    'src' => $meta['source'] ?? null,
                    'eid' => $meta['album_external_id'] ?? null,
                ]
            )->fetchColumn();
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('TrackStatsService::upsertAlbum failed: ' . $e->getMessage(), 'radiochatbox');
            return null;
        }
    }

    private function markEnriched(int $trackId): void
    {
        try {
            $qb = $this->db->queryBuilder()->from('tracks');
            $qb->where('id', '=', $trackId)->update(['enriched_at' => $qb->raw('NOW()')]);
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('TrackStatsService::markEnriched failed: ' . $e->getMessage(), 'radiochatbox');
        }
    }

    /**
     * Fetch and store external metadata (album, genre, release date, covers,
     * links) for one track. Marks the track enriched afterwards regardless, so
     * it is not retried automatically (admin can re-fetch manually).
     */
    public function enrichTrack(int $trackId, array $feed = []): bool
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

        // The feed sometimes provides the album and/or cover art directly —
        // more authoritative than a Deezer guess (fixes wrong artwork for songs
        // on multiple albums).
        $feedAlbum = trim((string)($feed['album'] ?? ''));
        $feedCover = trim((string)($feed['feed_cover'] ?? ''));

        $artwork = new ArtworkService();
        $meta = $artwork->getDeepMeta($artist, $title, $feedAlbum);

        // Feed-provided album/cover win over the inferred ones.
        if ($feedAlbum !== '') {
            $meta['album_title'] = $feedAlbum;
        }
        if ($feedCover !== '') {
            $stored = $artwork->storeImageFromUrl($feedCover);
            if (!empty($stored['full'])) {
                $meta['cover'] = $stored['full'];
            }
        }

        $artistId = $track['artist_id'] ?? ($artist !== '' ? $this->upsertArtist($artist) : null);

        // Enrich the artist row (image + external ids).
        if ($artistId) {
            try {
                $this->db->preparedQuery(
                    'UPDATE artists SET
                        image_file = COALESCE(:img, image_file),
                        source = COALESCE(:src, source),
                        external_id = COALESCE(:eid, external_id),
                        updated_at = NOW()
                     WHERE id = :id',
                    [
                        'img' => $meta['artist_image'] ?? null,
                        'src' => $meta['source'] ?? null,
                        'eid' => $meta['artist_external_id'] ?? null,
                        'id' => $artistId,
                    ]
                );
            } catch (\Throwable $e) {
                \Pramnos\Logs\Logger::log('enrichTrack artist update failed: ' . $e->getMessage(), 'radiochatbox');
            }
        }

        // Album row.
        $albumId = null;
        if (!empty($meta['album_title'])) {
            $albumId = $this->upsertAlbum($meta['album_title'], $artistId, $meta);
        }

        // Track row.
        try {
            $this->db->preparedQuery(
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
                 WHERE id = :id',
                [
                    'aid' => $artistId,
                    'album_id' => $albumId,
                    'genre' => $meta['genre'] ?? null,
                    'cover' => $meta['cover'] ?? null,
                    'rd' => $meta['release_date'] ?? null,
                    'src' => $meta['source'] ?? null,
                    'eid' => $meta['track_external_id'] ?? null,
                    'url' => $meta['track_url'] ?? null,
                    'id' => $trackId,
                ]
            );
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('enrichTrack track update failed: ' . $e->getMessage(), 'radiochatbox');
            $this->markEnriched($trackId);
            return false;
        }

        return true;
    }

    /**
     * Enrich up to $limit tracks that have not been enriched yet
     * (most-recently-played first). Returns how many were processed.
     */
    /**
     * Enrich a track only if it has never been enriched.
     *
     * enrichTrack() always calls the external APIs, so a track that already has its
     * album and artwork must not be sent through again - which is what would happen
     * on every replay if a track change enriched unconditionally.
     */
    public function enrichIfPending(int $trackId): bool
    {
        try {
            $result = $this->db->preparedQuery('SELECT enriched_at FROM tracks WHERE id = ?', [$trackId]);
            $row = $result ? $result->fetch() : null;
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('TrackStatsService::enrichIfPending failed: ' . $e->getMessage(), 'radiochatbox');

            return false;
        }

        if ($row === null || $row['enriched_at'] !== null) {
            return false;
        }

        return $this->enrichTrack($trackId);
    }

    public function enrichPending(int $limit = 5): int
    {
        try {
            $ids = $this->db->queryBuilder()
                ->from('tracks')
                ->whereRaw('enriched_at IS NULL')
                ->orderBy('last_played_at', 'desc')
                ->limit($limit)
                ->pluck('id');
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('TrackStatsService::enrichPending failed: ' . $e->getMessage(), 'radiochatbox');
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
            $result = $this->db->preparedQuery(
                'SELECT tp.id, t.id AS track_id, t.artist, t.title, t.display, tp.listeners, tp.played_at
                 FROM track_plays tp
                 JOIN tracks t ON tp.track_id = t.id
                 WHERE tp.played_at::date = :date
                 ORDER BY tp.played_at DESC
                 LIMIT :limit',
                ['date' => $date, 'limit' => $limit]
            );
            return $result ? $result->fetchAll() : [];
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('TrackStatsService::getLog failed: ' . $e->getMessage(), 'radiochatbox');
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
            $result = $this->db->preparedQuery(
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
                 LIMIT :limit',
                ['from' => $from, 'to' => $to, 'limit' => $limit]
            );
            return $result ? $result->fetchAll() : [];
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('TrackStatsService::getTopTracks failed: ' . $e->getMessage(), 'radiochatbox');
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
            $result = $this->db->preparedQuery(
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
                 LIMIT :limit',
                ['from' => $from, 'to' => $to, 'limit' => $limit]
            );
            return $result ? $result->fetchAll() : [];
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('TrackStatsService::getTopArtists failed: ' . $e->getMessage(), 'radiochatbox');
            return [];
        }
    }

    /** Most-played genres over a window (excludes flagged tracks/artists). */
    public function getTopGenres(string $from, string $to, int $limit = 50): array
    {
        try {
            $result = $this->db->preparedQuery(
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
                 LIMIT :limit',
                ['from' => $from, 'to' => $to, 'limit' => $limit]
            );
            return $result ? $result->fetchAll() : [];
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('TrackStatsService::getTopGenres failed: ' . $e->getMessage(), 'radiochatbox');
            return [];
        }
    }

    /** Most-played albums over a window (excludes flagged tracks/artists). */
    public function getTopAlbums(string $from, string $to, int $limit = 50): array
    {
        try {
            $result = $this->db->preparedQuery(
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
                 LIMIT :limit',
                ['from' => $from, 'to' => $to, 'limit' => $limit]
            );
            return $result ? $result->fetchAll() : [];
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('TrackStatsService::getTopAlbums failed: ' . $e->getMessage(), 'radiochatbox');
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
                    $this->db->queryBuilder()->from('tracks')
                        ->where('id', '=', $trackId)
                        ->update(['album_id' => $albumId]);
                }
            }

            $updateData = [];
            foreach (['genre', 'release_date', 'external_url'] as $col) {
                if (array_key_exists($col, $data)) {
                    $val = trim((string)$data[$col]);
                    $updateData[$col] = $val === '' ? null : $val;
                }
            }
            if (array_key_exists('excluded_from_stats', $data)) {
                // The framework binds a PHP bool as the native boolean.
                $updateData['excluded_from_stats'] = !empty($data['excluded_from_stats']);
            }
            if ($updateData) {
                $this->db->queryBuilder()->from('tracks')
                    ->where('id', '=', $trackId)
                    ->update($updateData);
            }
            return true;
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('TrackStatsService::updateTrackMeta failed: ' . $e->getMessage(), 'radiochatbox');
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
            $updateData = [];
            foreach (['genre', 'external_url'] as $col) {
                if (array_key_exists($col, $data)) {
                    $val = trim((string)$data[$col]);
                    $updateData[$col] = $val === '' ? null : $val;
                }
            }
            if (array_key_exists('excluded_from_stats', $data)) {
                $updateData['excluded_from_stats'] = !empty($data['excluded_from_stats']);
            }
            if (!$updateData) {
                return true;
            }
            $qb = $this->db->queryBuilder()->from('artists');
            $updateData['updated_at'] = $qb->raw('NOW()');
            $qb->where('id', '=', $artistId)->update($updateData);
            return true;
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('TrackStatsService::updateArtistMeta failed: ' . $e->getMessage(), 'radiochatbox');
            return false;
        }
    }

    /**
     * Update editable album metadata. Accepts: genre, release_date, external_url.
     */
    public function updateAlbumMeta(int $albumId, array $data): bool
    {
        try {
            $updateData = [];
            foreach (['genre', 'release_date', 'external_url'] as $col) {
                if (array_key_exists($col, $data)) {
                    $val = trim((string)$data[$col]);
                    $updateData[$col] = $val === '' ? null : $val;
                }
            }
            if (!$updateData) {
                return true;
            }
            $qb = $this->db->queryBuilder()->from('albums');
            $updateData['updated_at'] = $qb->raw('NOW()');
            $qb->where('id', '=', $albumId)->update($updateData);
            return true;
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('TrackStatsService::updateAlbumMeta failed: ' . $e->getMessage(), 'radiochatbox');
            return false;
        }
    }

    /**
     * Re-fetch album metadata (cover, genre, release date, link) from the
     * external API and persist any values we didn't already have. Existing
     * cover/genre/release/link are kept; only empty fields are filled.
     */
    public function enrichAlbum(int $albumId): bool
    {
        try {
            $result = $this->db->preparedQuery(
                'SELECT al.id, al.title, al.cover_file, al.release_date, al.genre,
                        al.external_url, al.external_id, ar.name AS artist_name
                 FROM albums al LEFT JOIN artists ar ON al.artist_id = ar.id
                 WHERE al.id = :id',
                ['id' => $albumId]
            );
            $al = $result ? $result->fetch() : null;
            if (!$al) {
                return false;
            }

            $meta = (new ArtworkService())->getAlbumMeta(
                (string)($al['artist_name'] ?? ''),
                (string)($al['title'] ?? ''),
                $al['external_id'] ?? null
            );

            // Fill only what's missing; downloaded cover always refreshed if we got one.
            $updateData = [];
            if (!empty($meta['cover'])) {
                $updateData['cover_file'] = $meta['cover'];
            }
            if (empty($al['genre']) && !empty($meta['genre'])) {
                $updateData['genre'] = $meta['genre'];
            }
            if (empty($al['release_date']) && !empty($meta['release_date'])) {
                $updateData['release_date'] = $meta['release_date'];
            }
            if (empty($al['external_url']) && !empty($meta['external_url'])) {
                $updateData['external_url'] = $meta['external_url'];
            }
            if (empty($al['external_id']) && !empty($meta['external_id'])) {
                $updateData['external_id'] = $meta['external_id'];
                $updateData['source'] = $meta['source'] ?? 'deezer';
            }
            if (!$updateData) {
                return true;
            }
            $qb = $this->db->queryBuilder()->from('albums');
            $updateData['updated_at'] = $qb->raw('NOW()');
            $qb->where('id', '=', $albumId)->update($updateData);
            return true;
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('TrackStatsService::enrichAlbum failed: ' . $e->getMessage(), 'radiochatbox');
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
                $qb = $this->db->queryBuilder()->from('artists');
                $qb->where('id', '=', $row['id'])
                    ->update(['image_file' => $file, 'updated_at' => $qb->raw('NOW()')]);
            } catch (\Throwable $e) {
                \Pramnos\Logs\Logger::log('TrackStatsService::ensureArtistImage failed: ' . $e->getMessage(), 'radiochatbox');
            }
        }
        return $file;
    }

    /** The artists row for a given name (id, editable meta), or null. */
    public function getArtistRowByName(string $name): ?array
    {
        try {
            $result = $this->db->preparedQuery(
                'SELECT id, name, genre, external_url, image_file, excluded_from_stats
                 FROM artists WHERE name = :name',
                ['name' => $name]
            );
            return ($result && $result->numRows > 0) ? $result->fields : null;
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('TrackStatsService::getArtistRowByName failed: ' . $e->getMessage(), 'radiochatbox');
            return null;
        }
    }

    /** All-time summary for one artist (plays, distinct tracks, first/last). */
    public function getArtistSummary(string $artist): ?array
    {
        try {
            $result = $this->db->preparedQuery(
                'SELECT COUNT(*) AS plays, COUNT(DISTINCT t.id) AS tracks,
                        MIN(tp.played_at) AS first_played, MAX(tp.played_at) AS last_played
                 FROM track_plays tp JOIN tracks t ON tp.track_id = t.id
                 WHERE t.artist = :artist',
                ['artist' => $artist]
            );
            $row = $result ? $result->fetch() : null;
            if (!$row || (int)$row['plays'] === 0) {
                return null;
            }
            return $row;
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('TrackStatsService::getArtistSummary failed: ' . $e->getMessage(), 'radiochatbox');
            return null;
        }
    }

    /** Tracks by one artist with play counts (all-time), most-played first. */
    public function getArtistTracks(string $artist, int $limit = 200): array
    {
        try {
            $result = $this->db->preparedQuery(
                'SELECT t.id AS track_id, t.display, t.title, t.genre,
                        COUNT(*) AS plays, MAX(tp.played_at) AS last_played
                 FROM track_plays tp JOIN tracks t ON tp.track_id = t.id
                 WHERE t.artist = :artist
                 GROUP BY t.id, t.display, t.title, t.genre
                 ORDER BY plays DESC, last_played DESC
                 LIMIT :limit',
                ['artist' => $artist, 'limit' => $limit]
            );
            return $result ? $result->fetchAll() : [];
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('TrackStatsService::getArtistTracks failed: ' . $e->getMessage(), 'radiochatbox');
            return [];
        }
    }

    /** A single track's metadata, or null if not found. */
    public function getTrackById(int $trackId): ?array
    {
        try {
            $result = $this->db->preparedQuery(
                'SELECT t.id, t.artist, t.title, t.display, t.first_played_at, t.last_played_at,
                        t.play_count, t.artist_id, t.album_id, t.genre, t.cover_file, t.release_date,
                        t.external_url, t.excluded_from_stats, t.enriched_at,
                        al.title AS album_title, al.cover_file AS album_cover_file,
                        ar.name AS artist_name, ar.image_file AS artist_image_file
                 FROM tracks t
                 LEFT JOIN albums al ON t.album_id = al.id
                 LEFT JOIN artists ar ON t.artist_id = ar.id
                 WHERE t.id = :id',
                ['id' => $trackId]
            );
            return ($result && $result->numRows > 0) ? $result->fields : null;
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('TrackStatsService::getTrackById failed: ' . $e->getMessage(), 'radiochatbox');
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
            $result = $this->db->queryBuilder()
                ->from('track_plays')
                ->select(['id', 'listeners', 'played_at'])
                ->where('track_id', '=', $trackId)
                ->orderBy('played_at', 'desc')
                ->limit($limit)
                ->getAll();
            return $result;
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('TrackStatsService::getTrackPlays failed: ' . $e->getMessage(), 'radiochatbox');
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
            $r = $this->db->preparedQuery(
                'SELECT COUNT(*) AS total_plays, COUNT(DISTINCT track_id) AS unique_tracks
                 FROM track_plays WHERE played_at >= :from AND played_at < :to',
                ['from' => $from, 'to' => $to]
            );
            $row = $r ? $r->fetch() : null;
            if ($row) {
                $totals['total_plays'] = (int)$row['total_plays'];
                $totals['unique_tracks'] = (int)$row['unique_tracks'];
            }
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('TrackStatsService::getSummary totals failed: ' . $e->getMessage(), 'radiochatbox');
        }

        $perDay = [];
        try {
            $r = $this->db->preparedQuery(
                'SELECT played_at::date AS day, COUNT(*) AS plays
                 FROM track_plays WHERE played_at >= :from AND played_at < :to
                 GROUP BY day ORDER BY day',
                ['from' => $from, 'to' => $to]
            );
            $perDay = $r ? $r->fetchAll() : [];
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('TrackStatsService::getSummary perDay failed: ' . $e->getMessage(), 'radiochatbox');
        }

        // The currently/most-recently played track (for a "now playing" link).
        $current = null;
        try {
            $r = $this->db->query(
                'SELECT t.id, t.display FROM track_plays tp
                 JOIN tracks t ON tp.track_id = t.id
                 ORDER BY tp.played_at DESC LIMIT 1'
            );
            $current = ($r && $r->numRows > 0) ? $r->fields : null;
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('TrackStatsService::getSummary current failed: ' . $e->getMessage(), 'radiochatbox');
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
            $result = $this->db->preparedQuery(
                'SELECT t.artist, t.genre, t.release_date, t.cover_file,
                        al.title AS album_title, al.release_date AS album_release_date, al.cover_file AS album_cover,
                        ar.image_file AS artist_image
                 FROM tracks t
                 LEFT JOIN albums al ON t.album_id = al.id
                 LEFT JOIN artists ar ON t.artist_id = ar.id
                 WHERE t.display = :d
                 LIMIT 1',
                ['d' => $display]
            );
            $row = ($result && $result->numRows > 0) ? $result->fields : null;
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
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('TrackStatsService::getCurrentTrackMeta failed: ' . $e->getMessage(), 'radiochatbox');
            return null;
        }
    }

    /** Distinct genres already present in the database (tracks + albums). */
    public function getGenreList(): array
    {
        try {
            $rows = $this->db->query(
                "SELECT DISTINCT genre FROM (
                     SELECT genre FROM tracks WHERE genre IS NOT NULL AND genre <> ''
                     UNION
                     SELECT genre FROM albums WHERE genre IS NOT NULL AND genre <> ''
                 ) g ORDER BY genre ASC"
            )->fetchAll();
            return array_column($rows, 'genre');
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('TrackStatsService::getGenreList failed: ' . $e->getMessage(), 'radiochatbox');
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
            return $this->db->query(
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
            )->fetchAll();
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('TrackStatsService::getAllArtists failed: ' . $e->getMessage(), 'radiochatbox');
            return [];
        }
    }

    /** Albums by an artist (all-time play counts), most-played first. */
    public function getAlbumsByArtist(string $artist): array
    {
        try {
            $result = $this->db->preparedQuery(
                'SELECT al.id AS album_id, al.title, al.cover_file, al.release_date,
                        COUNT(tp.id) AS plays, COUNT(DISTINCT t.id) AS tracks
                 FROM albums al
                 JOIN artists ar ON al.artist_id = ar.id
                 LEFT JOIN tracks t ON t.album_id = al.id
                 LEFT JOIN track_plays tp ON tp.track_id = t.id
                 WHERE ar.name = :artist
                 GROUP BY al.id, al.title, al.cover_file, al.release_date
                 ORDER BY plays DESC, al.title ASC',
                ['artist' => $artist]
            );
            return $result ? $result->fetchAll() : [];
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('TrackStatsService::getAlbumsByArtist failed: ' . $e->getMessage(), 'radiochatbox');
            return [];
        }
    }

    /**
     * Bulk-set the genre for every track of an artist (and the artist row).
     * Empty genre clears it. Returns the number of tracks updated.
     */
    public function bulkSetGenreByArtist(string $artist, string $genre): int
    {
        $artist = trim($artist);
        if ($artist === '') {
            return 0;
        }
        $g = trim($genre);
        $g = $g === '' ? null : mb_substr($g, 0, 200);
        try {
            $result = $this->db->queryBuilder()
                ->from('tracks')
                ->whereRaw('LOWER(artist) = LOWER(%s)', [$artist])
                ->update(['genre' => $g]);
            $count = $result ? $result->getAffectedRows() : 0;

            // Keep the artist row's genre in sync.
            $qb = $this->db->queryBuilder()->from('artists');
            $qb->whereRaw('LOWER(name) = LOWER(%s)', [$artist])
                ->update(['genre' => $g, 'updated_at' => $qb->raw('NOW()')]);

            return $count;
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('TrackStatsService::bulkSetGenreByArtist failed: ' . $e->getMessage(), 'radiochatbox');
            return 0;
        }
    }

    /**
     * Bulk-set the genre for a specific list of track ids. Empty genre clears
     * it. Returns the number of tracks updated.
     *
     * @param int[] $trackIds
     */
    public function bulkSetGenreForTracks(array $trackIds, string $genre): int
    {
        $ids = array_values(array_filter(array_map('intval', $trackIds), fn($v) => $v > 0));
        if (empty($ids)) {
            return 0;
        }
        $g = trim($genre);
        $g = $g === '' ? null : mb_substr($g, 0, 200);
        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $result = $this->db->preparedQuery(
                "UPDATE tracks SET genre = ? WHERE id IN ($placeholders)",
                array_merge([$g], $ids)
            );
            return $result ? $result->getAffectedRows() : 0;
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('TrackStatsService::bulkSetGenreForTracks failed: ' . $e->getMessage(), 'radiochatbox');
            return 0;
        }
    }

    /**
     * Bulk-reassign a genre: every track currently in $from becomes $to.
     * Returns the number of tracks updated.
     */
    public function bulkReassignGenre(string $from, string $to): int
    {
        $from = trim($from);
        if ($from === '') {
            return 0;
        }
        $to = trim($to);
        $toVal = $to === '' ? null : mb_substr($to, 0, 200);
        try {
            $result = $this->db->queryBuilder()
                ->from('tracks')
                ->where('genre', '=', $from)
                ->update(['genre' => $toVal]);
            return $result ? $result->getAffectedRows() : 0;
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('TrackStatsService::bulkReassignGenre failed: ' . $e->getMessage(), 'radiochatbox');
            return 0;
        }
    }

    /** Search tracks by title/artist/display. */
    public function searchTracks(string $query, int $limit = 100): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        try {
            $result = $this->db->preparedQuery(
                'SELECT t.id AS track_id, t.display, t.artist, t.genre,
                        COUNT(tp.id) AS plays, MAX(tp.played_at) AS last_played
                 FROM tracks t
                 LEFT JOIN track_plays tp ON tp.track_id = t.id
                 WHERE t.display ILIKE :q OR t.artist ILIKE :q OR t.title ILIKE :q
                 GROUP BY t.id, t.display, t.artist, t.genre
                 ORDER BY plays DESC, MAX(tp.played_at) DESC NULLS LAST
                 LIMIT :limit',
                ['q' => '%' . $query . '%', 'limit' => $limit]
            );
            return $result ? $result->fetchAll() : [];
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('TrackStatsService::searchTracks failed: ' . $e->getMessage(), 'radiochatbox');
            return [];
        }
    }

    /** Tracks of a given genre with play counts (excludes flagged items). */
    public function getTracksByGenre(string $genre, int $limit = 500): array
    {
        try {
            $result = $this->db->preparedQuery(
                'SELECT t.id AS track_id, t.display, t.artist, t.genre,
                        COUNT(tp.id) AS plays, MAX(tp.played_at) AS last_played
                 FROM tracks t
                 LEFT JOIN track_plays tp ON tp.track_id = t.id
                 LEFT JOIN artists ar ON t.artist_id = ar.id
                 WHERE t.genre = :genre
                   AND t.excluded_from_stats = FALSE
                   AND (ar.excluded_from_stats IS NULL OR ar.excluded_from_stats = FALSE)
                 GROUP BY t.id, t.display, t.artist, t.genre
                 ORDER BY plays DESC, t.display ASC
                 LIMIT :limit',
                ['genre' => $genre, 'limit' => $limit]
            );
            return $result ? $result->fetchAll() : [];
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('TrackStatsService::getTracksByGenre failed: ' . $e->getMessage(), 'radiochatbox');
            return [];
        }
    }

    /** Album detail: the album row + its tracks with play counts. */
    public function getAlbumDetail(int $albumId): ?array
    {
        try {
            $albumResult = $this->db->preparedQuery(
                'SELECT al.id, al.title, al.cover_file, al.release_date, al.genre, al.external_url,
                        ar.name AS artist_name
                 FROM albums al LEFT JOIN artists ar ON al.artist_id = ar.id
                 WHERE al.id = :id',
                ['id' => $albumId]
            );
            $album = ($albumResult && $albumResult->numRows > 0) ? $albumResult->fields : null;
            if (!$album) {
                return null;
            }

            $tracks = $this->db->preparedQuery(
                'SELECT t.id AS track_id, t.display, t.genre, COUNT(tp.id) AS plays, MAX(tp.played_at) AS last_played
                 FROM tracks t LEFT JOIN track_plays tp ON tp.track_id = t.id
                 WHERE t.album_id = :id
                 GROUP BY t.id, t.display, t.genre
                 ORDER BY plays DESC, t.display ASC',
                ['id' => $albumId]
            );

            return ['album' => $album, 'tracks' => $tracks ? $tracks->fetchAll() : []];
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('TrackStatsService::getAlbumDetail failed: ' . $e->getMessage(), 'radiochatbox');
            return null;
        }
    }
}
