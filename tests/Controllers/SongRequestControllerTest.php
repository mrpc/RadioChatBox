<?php

namespace RadioChatBox\Tests\Controllers;

use PDO;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\FlatCache;
use Pramnos\Framework\Testing\TestDatabase;
use RadioChatBox\Controllers\SongRequestController;
use RadioChatBox\Services\SettingsService;
use RadioChatBox\Services\SongRequestService;

/**
 * Contract tests for the listener song-request feature: the public submit
 * endpoint (feature gate, session verification, flood guard, persistence) and
 * the admin queue (list + status transitions), plus the service directly.
 */
class SongRequestControllerTest extends TestCase
{
    private PDO $pdo;
    private string $user;
    private string $session;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::connection();
        $suffix = substr(bin2hex(random_bytes(5)), 0, 8);
        $this->user = 'req_' . $suffix;
        $this->session = 'sess_' . $suffix;

        $this->pdo->prepare(
            'INSERT INTO presence_sessions (username, session_id, ip_address, last_heartbeat, joined_at)
             VALUES (?, ?, ?, NOW(), NOW())'
        )->execute([$this->user, $this->session, '127.0.0.1']);
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare('DELETE FROM song_requests WHERE requester_username = ?')->execute([$this->user]);
        $this->pdo->prepare('DELETE FROM presence_sessions WHERE username = ?')->execute([$this->user]);
        $this->pdo->prepare("DELETE FROM settings WHERE setting = 'song_requests_enabled'")->execute();
        FlatCache::default()->clear();
        $_POST = [];
        $_GET = [];
    }

    private function enable(bool $on = true): void
    {
        (new SettingsService())->set('song_requests_enabled', $on ? 'true' : 'false');
    }

    // ---- public submit ----------------------------------------------

    /** With the feature off, the public endpoint hides behind a 404. */
    public function testSubmitIsDisabledByDefault(): void
    {
        $this->enable(false);
        $_POST = ['username' => $this->user, 'session_id' => $this->session, 'song_title' => 'Song'];

        $response = (new SongRequestController())->create();

        $this->assertSame(404, $response->getStatusCode());
    }

    /** A request from an unverifiable session is rejected (no requesting as someone else). */
    public function testSubmitRejectsAnInvalidSession(): void
    {
        $this->enable();
        $_POST = ['username' => $this->user, 'session_id' => 'not-my-session', 'song_title' => 'Song'];

        $response = (new SongRequestController())->create();

        $this->assertSame(403, $response->getStatusCode());
    }

    /** A missing song title is a 400 before anything is stored. */
    public function testSubmitRequiresASongTitle(): void
    {
        $this->enable();
        $_POST = ['username' => $this->user, 'session_id' => $this->session, 'song_title' => '   '];

        $response = (new SongRequestController())->create();

        $this->assertSame(400, $response->getStatusCode());
    }

    /** The happy path stores a pending request carrying the dedication. */
    public function testSubmitStoresAPendingRequestWithDedication(): void
    {
        $this->enable();
        $_POST = [
            'username'    => $this->user,
            'session_id'  => $this->session,
            'song_title'  => 'Across the Oceans',
            'artist'      => 'Walk In The Darkness',
            'dedication'  => 'For the night shift crew',
        ];

        $response = (new SongRequestController())->create();
        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertGreaterThan(0, $body['request_id']);

        $row = $this->pdo->query(
            'SELECT * FROM song_requests WHERE id = ' . (int) $body['request_id']
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('pending', $row['status']);
        $this->assertSame('Across the Oceans', $row['song_title']);
        $this->assertSame('Walk In The Darkness', $row['artist']);
        $this->assertSame('For the night shift crew', $row['dedication']);
    }

    /** After FLOOD_LIMIT pending requests, the session is throttled with a 429. */
    public function testSubmitIsFloodLimited(): void
    {
        $this->enable();
        $service = new SongRequestService();
        for ($i = 0; $i < 3; $i++) {
            $service->create($this->user, $this->session, "Song {$i}");
        }

        $_POST = ['username' => $this->user, 'session_id' => $this->session, 'song_title' => 'One too many'];
        $response = (new SongRequestController())->create();

        $this->assertSame(429, $response->getStatusCode());
    }

    // ---- admin queue -------------------------------------------------

    /** The admin queue lists requests for a status and reports the pending count. */
    public function testAdminListReturnsQueueAndPendingCount(): void
    {
        $service = new SongRequestService();
        $service->create($this->user, $this->session, 'Pending One');
        $service->create($this->user, $this->session, 'Pending Two');

        $_GET = ['status' => 'pending'];
        $response = (new SongRequestController())->adminList();

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertGreaterThanOrEqual(2, $body['pending_count']);
        $titles = array_column($body['requests'], 'song_title');
        $this->assertContains('Pending One', $titles);
    }

    /** An admin action moves a request to the mapped status; a bad action is a 400. */
    public function testAdminUpdateTransitionsStatus(): void
    {
        $id = (new SongRequestService())->create($this->user, $this->session, 'To Be Played');

        $_POST = ['id' => $id, 'action' => 'played'];
        $ok = (new SongRequestController())->adminUpdate();
        $this->assertSame(200, $ok->getStatusCode());

        $status = $this->pdo->query('SELECT status FROM song_requests WHERE id = ' . (int) $id)->fetchColumn();
        $this->assertSame('played', $status);

        $_POST = ['id' => $id, 'action' => 'bogus'];
        $bad = (new SongRequestController())->adminUpdate();
        $this->assertSame(400, $bad->getStatusCode());
    }

    // ---- service directly -------------------------------------------

    /** recentCountForSession counts only this session's rows in the window. */
    public function testServiceCountsRecentRequestsPerSession(): void
    {
        $service = new SongRequestService();
        $service->create($this->user, $this->session, 'A');
        $service->create($this->user, $this->session, 'B');

        $this->assertSame(2, $service->recentCountForSession($this->session, 10));
        $this->assertSame(0, $service->recentCountForSession('some-other-session', 10));
    }
}
