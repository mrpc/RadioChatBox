<?php

namespace RadioChatBox\Tests;

use Pramnos\Framework\Testing\TestDatabase;

use PHPUnit\Framework\TestCase;
use RadioChatBox\Database;
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
        $pdo = TestDatabase::connection();
        $like = '%' . $this->suffix . '%';
        // Children before parents.
        $pdo->prepare(
            'DELETE FROM track_plays WHERE track_id IN (SELECT id FROM tracks WHERE display LIKE ?)'
        )->execute([$like]);
        $pdo->prepare('DELETE FROM tracks WHERE display LIKE ?')->execute([$like]);
        $pdo->prepare('DELETE FROM artists WHERE name LIKE ?')->execute([$like]);
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
}
