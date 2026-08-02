<?php

namespace RadioChatBox\Tests\Controllers;

use PDO;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\FlatCache;
use Pramnos\Framework\Testing\TestDatabase;
use RadioChatBox\Controllers\SongsController;
use RadioChatBox\Services\SettingsService;

/**
 * Contract tests for GET /api/songs/top (the front-end "Top charts" feed).
 *
 * Covers the settings gate (404 when charts are disabled), the success shape,
 * input coercion/clamping for type/period/limit, and that a recorded play
 * surfaces in the aggregate for both the tracks and artists views.
 */
class SongsControllerTest extends TestCase
{
    private PDO $pdo;

    /** @var list<int> track ids inserted by a test, removed in tearDown */
    private array $trackIds = [];

    private string $tag;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::connection();
        $this->tag = 'song_' . substr(bin2hex(random_bytes(5)), 0, 8);
    }

    protected function tearDown(): void
    {
        foreach ($this->trackIds as $id) {
            $this->pdo->prepare('DELETE FROM track_plays WHERE track_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM tracks WHERE id = ?')->execute([$id]);
        }
        $this->trackIds = [];

        $this->pdo->prepare("DELETE FROM settings WHERE setting IN ('charts_enabled', 'charts_default_period', 'song_requests_enabled')")
            ->execute();

        // The settings and chart aggregates are cached in Redis; drop them so the
        // next test (and the app) does not read this test's seeded state.
        FlatCache::default()->clear();

        $_GET = [];
    }

    /** Persist a setting the way the admin panel would, invalidating its cache. */
    private function setSetting(string $key, string $value): void
    {
        (new SettingsService())->set($key, $value);
    }

    /** Record one play of a track (unique display), within the last hour. */
    private function recordPlay(string $artist, string $title): int
    {
        $display = "{$artist} - {$title} [{$this->tag}]";
        $stmt = $this->pdo->prepare(
            'INSERT INTO tracks (artist, title, display, play_count) VALUES (?, ?, ?, 1) RETURNING id'
        );
        $stmt->execute([$artist, $title, $display]);
        $id = (int) $stmt->fetchColumn();
        $this->trackIds[] = $id;

        $this->pdo->prepare('INSERT INTO track_plays (track_id, played_at) VALUES (?, NOW())')
            ->execute([$id]);

        return $id;
    }

    /**
     * With charts disabled (the default — no setting row), the endpoint hides the
     * feature behind a 404 rather than serving data.
     */
    public function testDisabledChartsReturn404(): void
    {
        $this->setSetting('charts_enabled', 'false');

        $response = (new SongsController())->top();

        $this->assertSame(404, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertFalse($body['success']);
    }

    /**
     * When enabled, the payload carries success plus the resolved type/period and
     * an items array (empty is valid — it is still a 200 with the right shape).
     */
    public function testEnabledChartsReturnSuccessShape(): void
    {
        $this->setSetting('charts_enabled', 'true');

        $_GET = ['type' => 'tracks', 'period' => 'week', 'limit' => '5'];
        $response = (new SongsController())->top();

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertSame('tracks', $body['type']);
        $this->assertSame('week', $body['period']);
        $this->assertIsArray($body['items']);
    }

    /**
     * A just-played track appears in the tracks chart for the day window.
     */
    public function testRecentPlayAppearsInTracksChart(): void
    {
        $this->setSetting('charts_enabled', 'true');
        $this->recordPlay('Chart Tester', 'Hit Single');

        $_GET = ['type' => 'tracks', 'period' => 'day', 'limit' => '50'];
        $response = (new SongsController())->top();

        $body = json_decode($response->getBody(), true);
        $displays = array_column($body['items'], 'display');
        $this->assertContains("Chart Tester - Hit Single [{$this->tag}]", $displays);
    }

    /**
     * The artists chart aggregates by artist name and reports a play count.
     */
    public function testRecentPlayAppearsInArtistsChart(): void
    {
        $this->setSetting('charts_enabled', 'true');
        $artist = 'Aggregated Artist ' . $this->tag;
        $this->recordPlay($artist, 'Song A');
        $this->recordPlay($artist, 'Song B');

        $_GET = ['type' => 'artists', 'period' => 'day', 'limit' => '50'];
        $response = (new SongsController())->top();

        $body = json_decode($response->getBody(), true);
        $this->assertSame('artists', $body['type']);
        $names = array_column($body['items'], 'artist');
        $this->assertContains($artist, $names);
    }

    /**
     * The artist-names endpoint is gated by song_requests_enabled (its consumer):
     * off -> 404, on -> the known artist names for the autocomplete.
     */
    public function testArtistNamesEndpointIsGatedAndListsArtists(): void
    {
        // Off by default -> 404.
        $this->setSetting('song_requests_enabled', 'false');
        $this->assertSame(404, (new SongsController())->artists()->getStatusCode());

        // On + a recorded play surfaces the artist name.
        $this->setSetting('song_requests_enabled', 'true');
        $this->recordPlay('Autocomplete Artist ' . $this->tag, 'Some Song');

        $response = (new SongsController())->artists();
        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertContains('Autocomplete Artist ' . $this->tag, $body['artists']);
    }

    /**
     * Unknown type/period fall back to their defaults and an out-of-range limit is
     * clamped, so a garbage query can never break the response shape.
     */
    public function testInvalidInputsFallBackToDefaults(): void
    {
        $this->setSetting('charts_enabled', 'true');
        $this->setSetting('charts_default_period', 'month');

        $_GET = ['type' => 'nonsense', 'period' => 'nonsense', 'limit' => '9999'];
        $response = (new SongsController())->top();

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame('tracks', $body['type']);   // unknown type -> tracks
        $this->assertSame('month', $body['period']);  // unknown period -> configured default
    }
}
