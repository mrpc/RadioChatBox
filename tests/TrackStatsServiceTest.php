<?php

namespace RadioChatBox\Tests;

use Pramnos\Framework\Testing\TestDatabase;

use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Http\Client;
use Pramnos\Http\ClientResponse;
use RadioChatBox\Services\TrackStatsService;

/**
 * Integration tests for TrackStatsService against the real (shared) database,
 * added when it moved onto the framework QueryBuilder in Phase 7.
 *
 * The core write path (recordPlay) upserts an artist and a track and appends a
 * play row inside a transaction; these tests pin the de-duplication, the
 * ON CONFLICT play-count bump, and the read helpers. Everything created is
 * tagged with a per-run suffix and removed in tearDown so the shared history is
 * untouched.
 */
class TrackStatsServiceTest extends TestCase
{
    private TrackStatsService $service;
    private string $suffix;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TrackStatsService();
        $this->suffix = substr(bin2hex(random_bytes(5)), 0, 10);
        // Clear the Redis "last track" pointer so the first recordPlay is not
        // deduped against whatever real track played last.
        try {
            \Pramnos\Redis\ConnectionManager::getInstance()->connection()->del(\Pramnos\Redis\ConnectionManager::getInstance()->prefix() . 'radio:last_track');
        } catch (\Throwable) {
            // Non-fatal in the test environment.
        }
    }

    protected function tearDown(): void
    {
        Client::resetFakes();
        $pdo = TestDatabase::connection();
        $like = '%' . $this->suffix . '%';
        // Children before parents.
        $pdo->prepare(
            'DELETE FROM track_plays WHERE track_id IN (SELECT id FROM tracks WHERE display LIKE ?)'
        )->execute([$like]);
        $pdo->prepare('DELETE FROM tracks WHERE display LIKE ?')->execute([$like]);
        $pdo->prepare('DELETE FROM artists WHERE name LIKE ?')->execute([$like]);
        $pdo->prepare('DELETE FROM albums WHERE title LIKE ?')->execute([$like]);
        parent::tearDown();
    }

    /** A now-playing payload for a track unique to this run. */
    private function nowPlaying(string $tag): array
    {
        return [
            'active'    => true,
            'artist'    => 'Artist ' . $this->suffix,
            'title'     => 'Title ' . $tag . ' ' . $this->suffix,
            'display'   => 'Artist ' . $this->suffix . ' - Title ' . $tag . ' ' . $this->suffix,
            'listeners' => 42,
        ];
    }

    /**
     * recordPlay inserts the track and a play row and returns the new track id;
     * getTrackById reads it back with play_count 1 and the artist linked.
     */
    public function testRecordPlayInsertsTrackAndPlay(): void
    {
        $trackId = $this->service->recordPlay($this->nowPlaying('A'));

        $this->assertIsInt($trackId);
        $this->assertGreaterThan(0, $trackId);

        $track = $this->service->getTrackById($trackId);
        $this->assertIsArray($track);
        $this->assertSame(1, (int) $track['play_count']);
        $this->assertSame('Artist ' . $this->suffix, $track['artist']);
        $this->assertNotNull($track['artist_id'], 'the artist must be upserted and linked');
    }

    /**
     * The same track playing twice in a row is recorded once: the second call is
     * de-duplicated (Redis pointer + the most-recent-play DB safeguard).
     */
    public function testConsecutiveSameTrackIsDeduped(): void
    {
        $first  = $this->service->recordPlay($this->nowPlaying('A'));
        $second = $this->service->recordPlay($this->nowPlaying('A'));

        $this->assertIsInt($first);
        $this->assertNull($second, 'the same consecutive track must not be recorded again');
    }

    /**
     * Replaying a track after a different one bumps its play_count via the
     * ON CONFLICT (display) DO UPDATE ... play_count = play_count + 1 path.
     */
    public function testReplayBumpsPlayCountOnConflict(): void
    {
        $a1 = $this->service->recordPlay($this->nowPlaying('A'));
        $this->service->recordPlay($this->nowPlaying('B'));
        $a2 = $this->service->recordPlay($this->nowPlaying('A'));

        // Same track row reused, not a duplicate.
        $this->assertSame($a1, $a2);

        $track = $this->service->getTrackById((int) $a1);
        $this->assertSame(2, (int) $track['play_count'], 'the play counter must increment on re-play');
    }

    /**
     * getTopTracks and getLog surface a just-recorded play within the window.
     */
    public function testTopTracksAndLogIncludeTheRecordedPlay(): void
    {
        $trackId = $this->service->recordPlay($this->nowPlaying('A'));
        $display = $this->nowPlaying('A')['display'];

        $from = (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s');
        $to   = (new \DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s');
        $top  = $this->service->getTopTracks($from, $to, 500);
        $this->assertContains($display, array_column($top, 'display'));

        $log = $this->service->getLog(date('Y-m-d'), 1000);
        $this->assertContains((string) $trackId, array_map(
            static fn($r) => (string) $r['track_id'],
            $log
        ));
    }

    /**
     * getArtistSummary aggregates all-time plays for an artist (verbatim SQL).
     */
    public function testArtistSummaryCountsPlays(): void
    {
        $this->service->recordPlay($this->nowPlaying('A'));
        $this->service->recordPlay($this->nowPlaying('B'));

        $summary = $this->service->getArtistSummary('Artist ' . $this->suffix);
        $this->assertIsArray($summary);
        $this->assertSame(2, (int) $summary['plays']);
        $this->assertSame(2, (int) $summary['tracks']);
    }

    /** The artist name used by nowPlaying(), for the query helpers below. */
    private function artist(): string
    {
        return 'Artist ' . $this->suffix;
    }

    /**
     * updateTrackMeta sets the genre, creates + links an album (album_title) and
     * stores release/url; getTrackById, getCurrentTrackMeta, getAlbumsByArtist and
     * getAlbumDetail read them back.
     */
    public function testTrackAndAlbumMetadataUpdates(): void
    {
        $trackId = (int) $this->service->recordPlay($this->nowPlaying('A'));
        $genre   = 'Rock ' . $this->suffix;

        $this->assertTrue($this->service->updateTrackMeta($trackId, [
            'genre'        => $genre,
            'album_title'  => 'Album ' . $this->suffix,
            'release_date' => '2020-01-01',
            'external_url' => 'https://example.com/t',
        ]));

        $track = $this->service->getTrackById($trackId);
        $this->assertSame($genre, $track['genre']);
        $this->assertNotNull($track['album_id'], 'album must be created and linked');

        $meta = $this->service->getCurrentTrackMeta($this->nowPlaying('A')['display']);
        $this->assertIsArray($meta);
        $this->assertSame($genre, $meta['genre']);
        $this->assertSame('Album ' . $this->suffix, $meta['album']);

        $albums = $this->service->getAlbumsByArtist($this->artist());
        $this->assertNotEmpty($albums);
        $albumId = (int) $albums[0]['album_id'];
        $this->assertIsArray($this->service->getAlbumDetail($albumId));
        $this->assertTrue($this->service->updateAlbumMeta($albumId, ['genre' => $genre]));
    }

    /**
     * The all-time top-N aggregations (artists / genres / albums) include a
     * just-recorded, genre- and album-tagged play.
     */
    public function testTopAggregations(): void
    {
        $trackId = (int) $this->service->recordPlay($this->nowPlaying('A'));
        $genre   = 'Rock ' . $this->suffix;
        $this->service->updateTrackMeta($trackId, ['genre' => $genre, 'album_title' => 'Album ' . $this->suffix]);

        $from = (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s');
        $to   = (new \DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s');

        $this->assertContains($this->artist(), array_column($this->service->getTopArtists($from, $to, 500), 'artist'));
        $this->assertContains($genre, array_column($this->service->getTopGenres($from, $to, 500), 'genre'));
        // Albums aggregate is keyed by album title.
        $this->assertNotEmpty($this->service->getTopAlbums($from, $to, 500));
    }

    /**
     * The discovery/read helpers return the seeded track and artist without error.
     */
    public function testDiscoveryQueries(): void
    {
        $trackId = (int) $this->service->recordPlay($this->nowPlaying('A'));
        $genre   = 'Rock ' . $this->suffix;
        $this->service->updateTrackMeta($trackId, ['genre' => $genre]);

        $this->assertContains($genre, $this->service->getGenreList());
        $this->assertContains($this->artist(), array_column($this->service->getAllArtists(), 'name'));
        $this->assertContains($trackId, array_map(static fn ($r) => (int) $r['track_id'], $this->service->searchTracks('Title A ' . $this->suffix, 50)));
        $this->assertContains($trackId, array_map(static fn ($r) => (int) $r['track_id'], $this->service->getTracksByGenre($genre, 500)));
        $this->assertNotEmpty($this->service->getArtistTracks($this->artist(), 200));
        $this->assertIsArray($this->service->getArtistRowByName($this->artist()));
        $this->assertNotEmpty($this->service->getTrackPlays($trackId, 500));
        $this->assertIsArray($this->service->getSummary(7, 20));
    }

    /**
     * The bulk genre operations rewrite tracks by artist, by id list, and by
     * from→to genre; updateArtistMeta updates the artist row.
     */
    public function testBulkGenreOperations(): void
    {
        $trackId = (int) $this->service->recordPlay($this->nowPlaying('A'));

        $this->assertGreaterThanOrEqual(1, $this->service->bulkSetGenreForTracks([$trackId], 'Jazz ' . $this->suffix));
        $this->assertSame('Jazz ' . $this->suffix, $this->service->getTrackById($trackId)['genre']);

        $this->assertGreaterThanOrEqual(1, $this->service->bulkSetGenreByArtist($this->artist(), 'Blues ' . $this->suffix));
        $this->assertSame('Blues ' . $this->suffix, $this->service->getTrackById($trackId)['genre']);

        $this->assertGreaterThanOrEqual(1, $this->service->bulkReassignGenre('Blues ' . $this->suffix, 'Soul ' . $this->suffix));
        $this->assertSame('Soul ' . $this->suffix, $this->service->getTrackById($trackId)['genre']);

        $artistId = (int) $this->service->getArtistRowByName($this->artist())['id'];
        $this->assertTrue($this->service->updateArtistMeta($artistId, ['genre' => 'Funk ' . $this->suffix]));
    }

    /** A valid in-memory JPEG for faked image downloads. */
    private function jpeg(): string
    {
        $im = imagecreatetruecolor(60, 60);
        imagefilledrectangle($im, 0, 0, 60, 60, imagecolorallocate($im, 90, 30, 150));
        ob_start();
        imagejpeg($im);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);
        return $bytes;
    }

    /**
     * enrichAlbum fills missing album metadata (genre/release/external ids) and
     * refreshes the cover from a faked Deezer album lookup, then persists it.
     */
    public function testEnrichAlbumFillsMetadataFromDeezer(): void
    {
        // Seed a track + album (no genre/release) to enrich.
        $trackId = (int) $this->service->recordPlay($this->nowPlaying('A'));
        $this->service->updateTrackMeta($trackId, ['album_title' => 'Bare Album ' . $this->suffix]);
        $albumId = (int) $this->service->getAlbumsByArtist($this->artist())[0]['album_id'];

        Client::fake([
            '*api.deezer.com/search/album*' => ClientResponse::make(['data' => [['id' => 777]]]),
            '*api.deezer.com/album/*'       => ClientResponse::make([
                'release_date' => '2015-09-09',
                'genres'       => ['data' => [['name' => 'Soul ' . $this->suffix]]],
                'link'         => 'https://deezer.test/album/777',
                'cover_xl'     => 'https://img.test/albumcover.jpg',
            ]),
            '*img.test*' => ClientResponse::make($this->jpeg()),
        ]);

        $this->assertTrue($this->service->enrichAlbum($albumId));

        $album = $this->service->getAlbumDetail($albumId)['album'];
        $this->assertSame('Soul ' . $this->suffix, $album['genre']);
        $this->assertSame('2015-09-09', substr((string) $album['release_date'], 0, 10));
    }

    /**
     * ensureArtistImage looks up and stores an artist photo when missing, updating
     * the artist row's image_file. Driven by a faked Deezer artist search.
     */
    public function testEnsureArtistImageStoresPhoto(): void
    {
        $this->service->recordPlay($this->nowPlaying('A')); // creates the artist row

        Client::fake([
            '*api.deezer.com/search/artist*' => ClientResponse::make(['data' => [
                ['picture_xl' => 'https://img.test/artist.jpg'],
            ]]),
            '*img.test*' => ClientResponse::make($this->jpeg()),
        ]);

        $file = $this->service->ensureArtistImage($this->artist(), true);

        $this->assertNotNull($file);
        $this->assertSame($file, $this->service->getArtistRowByName($this->artist())['image_file']);

        // Clean up the stored artist image + thumb.
        $disk = dirname(__DIR__) . '/public' . $file;
        foreach ([$disk, preg_replace('/\.jpg$/i', '_thumb.jpg', $disk)] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }

    /**
     * enrichTrack with a feed that carries the album title and cover URL: the
     * external providers are faked to return no match, so the feed values win —
     * the cover image is downloaded and stored, an album row is created and
     * linked, and the track is stamped enriched. Exercises the enrichment DB
     * flow (upsertAlbum + artist/track updates) without any live HTTP.
     */
    public function testEnrichTrackWithFeedAlbumAndCover(): void
    {
        // A valid in-memory JPEG for the feed cover download.
        $img = imagecreatetruecolor(80, 80);
        imagefilledrectangle($img, 0, 0, 80, 80, imagecolorallocate($img, 200, 60, 30));
        ob_start();
        imagejpeg($img);
        $jpeg = (string) ob_get_clean();
        imagedestroy($img);

        Client::fake([
            '*api.deezer.com*'   => ClientResponse::make(['data' => []]),
            '*itunes.apple.com*' => ClientResponse::make(['results' => []]),
            '*feedcover.test*'   => ClientResponse::make($jpeg),
        ]);

        $trackId = (int) $this->service->recordPlay($this->nowPlaying('A'));
        $coverWeb = null;

        try {
            $ok = $this->service->enrichTrack($trackId, [
                'album'      => 'Feed Album ' . $this->suffix,
                'feed_cover' => 'https://feedcover.test/cover.jpg',
            ]);
            $this->assertTrue($ok);

            $track = $this->service->getTrackById($trackId);
            $this->assertNotNull($track['enriched_at'], 'the track must be marked enriched');
            $this->assertNotNull($track['album_id'], 'the feed album must be created and linked');
            $this->assertNotEmpty($track['cover_file'], 'the feed cover must be downloaded and stored');
            $coverWeb = $track['cover_file'];
        } finally {
            if ($coverWeb) {
                $disk = dirname(__DIR__) . '/public' . $coverWeb;
                foreach ([$disk, preg_replace('/\.jpg$/i', '_thumb.jpg', $disk)] as $f) {
                    if (is_file($f)) {
                        @unlink($f);
                    }
                }
            }
        }
    }
}
