<?php

namespace RadioChatBox\Tests\Controllers;

use PDO;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\FlatCache;
use Pramnos\Framework\Testing\TestDatabase;
use RadioChatBox\Controllers\TrackVoteController;
use RadioChatBox\Services\SettingsService;
use RadioChatBox\Services\TrackVoteService;

/**
 * Tests for now-playing voting: the TrackVoteService (up/down tally, toggle-off,
 * switch) and the controller (feature gate, session verification, current-track
 * resolution from the seeded now-playing cache, and the nothing-playing case).
 */
class TrackVoteControllerTest extends TestCase
{
    private const NOW_PLAYING_KEY = 'radio:now_playing';

    private PDO $pdo;
    private string $user;
    private string $session;
    private string $display;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::connection();
        $suffix = substr(bin2hex(random_bytes(5)), 0, 8);
        $this->user = 'voter_' . $suffix;
        $this->session = 'vsess_' . $suffix;
        $this->display = 'Test Artist - Test Song [' . $suffix . ']';

        $this->pdo->prepare(
            'INSERT INTO presence_sessions (username, session_id, ip_address, last_heartbeat, joined_at)
             VALUES (?, ?, ?, NOW(), NOW())'
        )->execute([$this->user, $this->session, '127.0.0.1']);
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare('DELETE FROM track_votes WHERE track_display = ?')->execute([$this->display]);
        $this->pdo->prepare('DELETE FROM tracks WHERE display = ?')->execute([$this->display]);
        $this->pdo->prepare('DELETE FROM presence_sessions WHERE username = ?')->execute([$this->user]);
        $this->pdo->prepare("DELETE FROM settings WHERE setting IN ('track_voting_enabled', 'radio_status_url')")->execute();
        FlatCache::default()->clear();
        $_POST = [];
        $_GET = [];
    }

    private function enable(): void
    {
        (new SettingsService())->set('track_voting_enabled', 'true');
    }

    /** Seed a live now-playing track so the controller resolves a current track. */
    private function seedNowPlaying(): void
    {
        (new SettingsService())->set('radio_status_url', 'https://example.com/status.json');
        FlatCache::default()->set(self::NOW_PLAYING_KEY, [
            'active' => true, 'display' => $this->display,
            'artist' => 'Test Artist', 'title' => 'Test Song', 'listeners' => 3,
        ], 120);
    }

    // ---- service ----------------------------------------------------

    /** An up-vote counts once; the same vote again toggles it off. */
    public function testUpVoteAndToggleOff(): void
    {
        $service = new TrackVoteService();

        $t1 = $service->vote($this->display, $this->session, $this->user, 'up');
        $this->assertSame(1, $t1['up']);
        $this->assertSame(1, $t1['my_vote']);

        $t2 = $service->vote($this->display, $this->session, $this->user, 'up'); // toggle off
        $this->assertSame(0, $t2['up']);
        $this->assertSame(0, $t2['my_vote']);
    }

    /** Switching from up to down moves the count across, not doubles it. */
    public function testSwitchFromUpToDown(): void
    {
        $service = new TrackVoteService();
        $service->vote($this->display, $this->session, $this->user, 'up');
        $t = $service->vote($this->display, $this->session, $this->user, 'down');

        $this->assertSame(0, $t['up']);
        $this->assertSame(1, $t['down']);
        $this->assertSame(-1, $t['my_vote']);
    }

    /** A vote links to the catalog track id when the display is catalogued. */
    public function testVoteLinksToCatalogTrackId(): void
    {
        // The tracker upserts tracks by unique display; simulate that row.
        $stmt = $this->pdo->prepare(
            'INSERT INTO tracks (display, play_count) VALUES (?, 1) RETURNING id'
        );
        $stmt->execute([$this->display]);
        $trackId = (int) $stmt->fetchColumn();

        (new TrackVoteService())->vote($this->display, $this->session, $this->user, 'up');

        $stored = $this->pdo->prepare('SELECT track_id FROM track_votes WHERE track_display = ? AND voter_session = ?');
        $stored->execute([$this->display, $this->session]);
        $this->assertSame($trackId, (int) $stored->fetchColumn());
    }

    /** A bad direction is rejected. */
    public function testInvalidDirectionThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new TrackVoteService())->vote($this->display, $this->session, $this->user, 'sideways');
    }

    // ---- controller -------------------------------------------------

    /** With voting off, both verbs 404. */
    public function testDisabledReturns404(): void
    {
        $this->assertSame(404, (new TrackVoteController())->tally()->getStatusCode());
        $_POST = ['username' => $this->user, 'session_id' => $this->session, 'direction' => 'up'];
        $this->assertSame(404, (new TrackVoteController())->cast()->getStatusCode());
    }

    /** Enabled but nothing playing -> 409 on cast. */
    public function testCastWithNothingPlayingIs409(): void
    {
        $this->enable(); // radio_status_url stays empty -> nothing playing
        $_POST = ['username' => $this->user, 'session_id' => $this->session, 'direction' => 'up'];

        $this->assertSame(409, (new TrackVoteController())->cast()->getStatusCode());
    }

    /** An invalid session cannot vote. */
    public function testCastRejectsInvalidSession(): void
    {
        $this->enable();
        $this->seedNowPlaying();
        $_POST = ['username' => $this->user, 'session_id' => 'not-mine', 'direction' => 'up'];

        $this->assertSame(403, (new TrackVoteController())->cast()->getStatusCode());
    }

    /** The happy path: cast an up-vote on the current track, then read it back. */
    public function testCastAndTallyCurrentTrack(): void
    {
        $this->enable();
        $this->seedNowPlaying();

        $_POST = ['username' => $this->user, 'session_id' => $this->session, 'direction' => 'up'];
        $cast = (new TrackVoteController())->cast();
        $this->assertSame(200, $cast->getStatusCode());
        $body = json_decode($cast->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertSame($this->display, $body['track']);
        $this->assertSame(1, $body['up']);
        $this->assertSame(1, $body['my_vote']);

        $_GET = ['session_id' => $this->session];
        $tally = (new TrackVoteController())->tally();
        $tbody = json_decode($tally->getBody(), true);
        $this->assertSame(1, $tbody['up']);
        $this->assertSame(1, $tbody['my_vote']);
    }
}
