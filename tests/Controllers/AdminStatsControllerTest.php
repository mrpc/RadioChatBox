<?php

namespace RadioChatBox\Tests\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Http\Request;
use Pramnos\Http\Response;
use Pramnos\Cache\FlatCache;
use Pramnos\Http\Client;
use Pramnos\Http\ClientResponse;
use RadioChatBox\Controllers\AdminStatsController;
use Pramnos\Database\Database;
use Pramnos\Framework\Testing\TestDatabase;
use RadioChatBox\Middleware\AdminAuthMiddleware;
use RadioChatBox\Services\TrackStatsService;

/**
 * Covers the migrated AdminStats resource controller (replaced
 * public/api/admin/stats.php, aggregate-stats.php, track-stats.php,
 * record-snapshot.php, bot-activity.php and bot-llm-stats.php).
 *
 * Every route carries AdminAuthMiddleware, so we assert the shared 401 gate once
 * and then exercise the deterministic validation paths that need no authenticated
 * session and cause no destructive writes: the 400 error mappings and the extra
 * bot-activity RBAC 403. Mutating and auth-only branches are intentionally not
 * driven against the shared dev database.
 */
class AdminStatsControllerTest extends TestCase
{
    private const ADMIN_ID = 'statsadmin';
    private ?string $sessionKey = null;
    private string $suffix = '';

    protected function tearDown(): void
    {
        Client::resetFakes();
        if ($this->sessionKey !== null) {
            try {
                FlatCache::default()->delete($this->sessionKey);
            } catch (\Throwable) {
                // best effort
            }
            $this->sessionKey = null;
        }
        $_GET = [];
        $_POST = [];
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);

        if ($this->suffix !== '') {
            $pdo  = TestDatabase::connection();
            $like = '%' . $this->suffix . '%';
            $pdo->prepare('DELETE FROM track_plays WHERE track_id IN (SELECT id FROM tracks WHERE display LIKE ?)')->execute([$like]);
            $pdo->prepare('DELETE FROM tracks WHERE display LIKE ?')->execute([$like]);
            $pdo->prepare('DELETE FROM artists WHERE name LIKE ?')->execute([$like]);
            $pdo->prepare('DELETE FROM albums WHERE title LIKE ?')->execute([$like]);
            $this->suffix = '';
        }

        parent::tearDown();
    }

    /** Seed one track (with a genre + album) tagged for this run; returns its id. */
    private function seedTrack(): int
    {
        $this->suffix = $this->suffix ?: substr(bin2hex(random_bytes(5)), 0, 10);
        try {
            \Pramnos\Redis\ConnectionManager::getInstance()->connection()->del(
                \Pramnos\Redis\ConnectionManager::getInstance()->prefix() . 'radio:last_track'
            );
        } catch (\Throwable) {
            // non-fatal
        }
        $service = new TrackStatsService();
        $trackId = (int) $service->recordPlay([
            'active'  => true,
            'artist'  => 'Artist ' . $this->suffix,
            'title'   => 'Title ' . $this->suffix,
            'display' => 'Artist ' . $this->suffix . ' - Title ' . $this->suffix,
            'listeners' => 7,
        ]);
        $service->updateTrackMeta($trackId, ['genre' => 'Rock ' . $this->suffix, 'album_title' => 'Album ' . $this->suffix]);

        return $trackId;
    }

    /**
     * Establish an authenticated administrator session so AdminAuth::getCurrentUser()
     * returns a privileged user; skips the test if Redis is unreachable.
     */
    private function authAsAdmin(): void
    {
        try {
            $key = 'admin_session:' . self::ADMIN_ID;
            FlatCache::default()->set($key, [
                'username' => self::ADMIN_ID,
                'role'     => 'administrator',
            ], 120);
            $this->sessionKey = $key;
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::ADMIN_ID . ':x';
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis unavailable: ' . $e->getMessage());
        }
    }

    /**
     * The AdminAuthMiddleware that guards every AdminStats route must reject an
     * unauthenticated request with 401 {"error":"Unauthorized"} without running the
     * wrapped action (matching the legacy AdminAuth::unauthorized()).
     */
    public function testMiddlewareBlocksUnauthenticatedWith401(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);

        $nextRan  = false;
        $response = (new AdminAuthMiddleware())->handle(
            Request::getInstance(),
            function ($request) use (&$nextRan) {
                $nextRan = true;
                return Response::make('should not run');
            }
        );

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(401, $response->getStatusCode());
        $this->assertFalse($nextRan, 'the action must not run for an unauthenticated request');
        $this->assertSame('Unauthorized', json_decode($response->getBody(), true)['error']);
    }

    /**
     * aggregate() maps an unknown granularity to the legacy 400
     * {"error":"Invalid granularity"} before touching any aggregation service call.
     */
    public function testAggregateRejectsInvalidGranularity(): void
    {
        $_GET['granularity'] = 'not-a-real-granularity';

        $response = (new AdminStatsController())->aggregate();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Invalid granularity', json_decode($response->getBody(), true)['error']);
    }

    /**
     * trackStats() POST with no recognised action reproduces the legacy
     * InvalidArgumentException('Unknown action') -> 400 mapping. An empty decoded
     * body ($_POST) yields action '' which falls through to "Unknown action".
     */
    public function testTrackStatsPostUnknownActionReturns400(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [];

        $response = (new AdminStatsController())->trackStats();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Unknown action', json_decode($response->getBody(), true)['error']);
    }

    /**
     * trackStats() GET mode=album without an album_id maps the legacy
     * InvalidArgumentException('album_id is required') to a 400 before any DB read.
     */
    public function testTrackStatsGetMissingAlbumIdReturns400(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET['mode'] = 'album';

        $response = (new AdminStatsController())->trackStats();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('album_id is required', json_decode($response->getBody(), true)['error']);
    }

    /**
     * bot-activity keeps its extra RBAC check on top of admin auth: with no current
     * user (AdminAuth::getCurrentUser() === null) it returns the legacy 403
     * "Forbidden: bot activity includes private conversations" before any query.
     */
    public function testBotActivityForbidsWithoutPrivilegedUser(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);

        $response = (new AdminStatsController())->botActivity();

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(
            'Forbidden: bot activity includes private conversations',
            json_decode($response->getBody(), true)['error']
        );
    }

    /**
     * bot-activity view=threads returns the paginated overview: a numeric 'total'
     * (the full conversation count, so the admin UI can page) alongside the
     * 'threads' page, and it accepts an offset. Pins the pagination fields ported
     * from the legacy endpoint. Read-only; safe against the shared dev database.
     */
    public function testBotActivityThreadsViewReturnsTotalAndAcceptsOffset(): void
    {
        $this->authAsAdmin();
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = ['view' => 'threads', 'limit' => 10, 'offset' => 0];

        $response = (new AdminStatsController())->botActivity();

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertArrayHasKey('total', $body);
        $this->assertIsInt($body['total']);
        $this->assertIsArray($body['threads']);
        // The page can never exceed the requested limit or the reported total.
        $this->assertLessThanOrEqual(10, count($body['threads']));
        $this->assertLessThanOrEqual($body['total'], count($body['threads']));
    }

    /**
     * bot-activity view=thread requires both fake_user and peer; missing either
     * yields the legacy 400 {"error":"fake_user and peer are required"} (now via
     * the shared Validate helper) before any thread lookup.
     */
    public function testBotActivityThreadRequiresFakeUserAndPeer(): void
    {
        $this->authAsAdmin();
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = ['view' => 'thread', 'fake_user' => '', 'peer' => ''];

        $response = (new AdminStatsController())->botActivity();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(
            'fake_user and peer are required',
            json_decode($response->getBody(), true)['error']
        );
    }

    // ── Happy paths (seeded track; cleaned up in tearDown) ─────────────────────

    private function body(Response $r): array
    {
        return json_decode($r->getBody(), true) ?: [];
    }

    /**
     * aggregate() runs each granularity's aggregation (hourly/daily/weekly/
     * monthly/yearly/all), reading the granularity from the query string, and
     * returns 200 {success, results} for each — covering every switch arm.
     */
    public function testAggregateAllGranularities(): void
    {
        $this->authAsAdmin();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        foreach (['hourly', 'daily', 'weekly', 'monthly', 'yearly', 'all'] as $granularity) {
            $_GET = ['granularity' => $granularity];
            $r = (new AdminStatsController())->aggregate();
            $this->assertSame(200, $r->getStatusCode(), "granularity {$granularity} must return 200");
            $this->assertTrue($this->body($r)['success']);
            $this->assertArrayHasKey('results', $this->body($r));
        }
    }

    /**
     * The track-stats POST enrichment actions (enrich track / album / artist) run
     * through the framework HTTP client, faked to no external match, and each
     * returns 200 success. Covers the enrich/enrich-album/enrich-artist branches.
     */
    public function testTrackStatsEnrichActions(): void
    {
        $trackId = $this->seedTrack();
        $service = new TrackStatsService();
        $albumId = (int) $service->getAlbumsByArtist('Artist ' . $this->suffix)[0]['album_id'];

        Client::fake([
            '*api.deezer.com*'   => ClientResponse::make(['data' => []]),
            '*itunes.apple.com*' => ClientResponse::make(['results' => []]),
        ]);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        foreach ([
            ['action' => 'enrich', 'track_id' => $trackId],
            ['action' => 'enrich-album', 'album_id' => $albumId],
            ['action' => 'enrich-artist', 'artist' => 'Artist ' . $this->suffix],
        ] as $post) {
            $_POST = $post;
            $r = (new AdminStatsController())->trackStats();
            $this->assertSame(200, $r->getStatusCode(), "action {$post['action']} must return 200");
            $this->assertTrue($this->body($r)['success']);
        }
    }

    /**
     * botActivity view=thread with fake_user+peer returns the per-thread state,
     * message list and matching LLM calls (empty is fine) — drives the thread
     * branch's getThreadState/threadMessages/log page reads.
     */
    public function testBotActivityThreadViewReturnsState(): void
    {
        $this->authAsAdmin();
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = ['view' => 'thread', 'fake_user' => 'nobody_' . $this->suffix, 'peer' => 'peer_' . $this->suffix];

        $r = (new AdminStatsController())->botActivity();
        $this->assertSame(200, $r->getStatusCode());
        $b = $this->body($r);
        $this->assertTrue($b['success']);
        $this->assertArrayHasKey('state', $b);
        $this->assertArrayHasKey('messages', $b);
        $this->assertArrayHasKey('calls', $b);
    }

    /**
     * botActivity view=call with an unknown id returns 404 "Log entry not found"
     * (the LlmLog::find miss branch).
     */
    public function testBotActivityCallViewNotFound(): void
    {
        $this->authAsAdmin();
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = ['view' => 'call', 'id' => 0];

        $r = (new AdminStatsController())->botActivity();
        $this->assertSame(404, $r->getStatusCode());
        $this->assertSame('Log entry not found', $this->body($r)['error']);
    }

    /** stats(): the summary granularity returns success. */
    public function testStatsSummaryReturnsData(): void
    {
        $this->authAsAdmin();
        $_GET['granularity'] = 'summary';
        $r = (new AdminStatsController())->stats();
        $this->assertSame(200, $r->getStatusCode());
        $this->assertTrue($this->body($r)['success']);
    }

    /** stats(): a time granularity (daily) returns rows without error. */
    public function testStatsDailyReturnsData(): void
    {
        $this->authAsAdmin();
        $_GET['granularity'] = 'daily';
        $r = (new AdminStatsController())->stats();
        $this->assertSame(200, $r->getStatusCode());
        $this->assertTrue($this->body($r)['success']);
    }

    /** stats(): every remaining granularity branch returns 200 with its data. */
    public function testStatsAllGranularitiesReturnData(): void
    {
        $this->authAsAdmin();
        foreach (['hourly', 'weekly', 'monthly', 'yearly'] as $granularity) {
            $_GET = ['granularity' => $granularity];
            $r = (new AdminStatsController())->stats();
            $this->assertSame(200, $r->getStatusCode(), "granularity {$granularity} must return 200");
            $body = $this->body($r);
            $this->assertTrue($body['success']);
            $this->assertSame($granularity, $body['granularity']);
        }
    }

    /**
     * botActivity view=balance returns the provider balance envelope. With no API
     * key configured the account is unconfigured and balance is null, but the
     * whole balance branch (LlmAccount reads) still runs.
     */
    public function testBotActivityBalanceViewReturnsEnvelope(): void
    {
        $this->authAsAdmin();
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = ['view' => 'balance'];

        $r = (new AdminStatsController())->botActivity();
        $this->assertSame(200, $r->getStatusCode());
        $body = $this->body($r);
        $this->assertTrue($body['success']);
        $this->assertArrayHasKey('configured', $body);
        $this->assertArrayHasKey('provider', $body);
        $this->assertArrayHasKey('supports_balance', $body);
    }

    /** trackStats() GET: every read-only report mode returns success. */
    public function testTrackStatsGetReportModes(): void
    {
        $this->seedTrack();
        foreach (['summary', 'log', 'top', 'artists', 'genres', 'albums', 'genre-list', 'all-artists'] as $mode) {
            $_GET = ['mode' => $mode];
            $r = (new AdminStatsController())->trackStats();
            $this->assertSame(200, $r->getStatusCode(), "mode {$mode} must return 200");
            $this->assertTrue($this->body($r)['success'], "mode {$mode} must be success");
        }
    }

    /** trackStats() GET: the parameterised lookups (artist/track/genre/album/search). */
    public function testTrackStatsGetLookups(): void
    {
        $trackId = $this->seedTrack();
        $service = new TrackStatsService();
        $albumId = (int) ($service->getAlbumsByArtist('Artist ' . $this->suffix)[0]['album_id'] ?? 0);

        $cases = [
            ['mode' => 'artist', 'artist' => 'Artist ' . $this->suffix],
            ['mode' => 'track', 'track_id' => (string) $trackId],
            ['mode' => 'genre', 'genre' => 'Rock ' . $this->suffix],
            ['mode' => 'album', 'album_id' => (string) $albumId],
            ['mode' => 'search', 'q' => 'Title ' . $this->suffix],
        ];
        foreach ($cases as $get) {
            $_GET = $get;
            $r = (new AdminStatsController())->trackStats();
            $this->assertSame(200, $r->getStatusCode(), "mode {$get['mode']} must return 200");
            $this->assertTrue($this->body($r)['success']);
        }
    }

    /** trackStats() POST: metadata update + the three bulk-genre operations. */
    public function testTrackStatsPostUpdateAndBulk(): void
    {
        $trackId = $this->seedTrack();
        $service = new \RadioChatBox\Services\TrackStatsService();
        $artistId = (int) $service->getArtistRowByName('Artist ' . $this->suffix)['id'];
        $albums   = $service->getAlbumsByArtist('Artist ' . $this->suffix);
        $albumId  = (int) ($albums[0]['album_id'] ?? 0);
        $this->assertGreaterThan(0, $artistId);
        $this->assertGreaterThan(0, $albumId);

        $_SERVER['REQUEST_METHOD'] = 'POST';

        foreach ([
            ['action' => 'update-track', 'track_id' => $trackId, 'genre' => 'Pop ' . $this->suffix],
            ['action' => 'update-artist', 'artist_id' => $artistId, 'genre' => 'Indie ' . $this->suffix],
            ['action' => 'update-album', 'album_id' => $albumId, 'genre' => 'Folk ' . $this->suffix],
            ['action' => 'bulk-genre-tracks', 'track_ids' => [$trackId], 'genre' => 'Jazz ' . $this->suffix],
            ['action' => 'bulk-genre-artist', 'artist' => 'Artist ' . $this->suffix, 'genre' => 'Blues ' . $this->suffix],
            ['action' => 'bulk-genre-reassign', 'from' => 'Blues ' . $this->suffix, 'to' => 'Soul ' . $this->suffix],
        ] as $post) {
            $_POST = $post;
            $r = (new AdminStatsController())->trackStats();
            $this->assertSame(200, $r->getStatusCode(), "action {$post['action']} must return 200");
            $this->assertTrue($this->body($r)['success']);
        }
    }

    /** botActivity(): the privileged threads + calls views return success. */
    public function testBotActivityViewsReturnData(): void
    {
        $this->authAsAdmin();
        $_SERVER['REQUEST_METHOD'] = 'GET';

        foreach (['threads', 'log', 'summary'] as $view) {
            $_GET = ['view' => $view];
            $r = (new AdminStatsController())->botActivity();
            $this->assertSame(200, $r->getStatusCode(), "view {$view} must return 200");
            $this->assertTrue($this->body($r)['success']);
        }
    }

    /** botLlmStats(): renders the LLM usage rollup for a privileged admin. */
    public function testBotLlmStatsReturnsData(): void
    {
        $this->authAsAdmin();
        $r = (new AdminStatsController())->botLlmStats();
        $this->assertSame(200, $r->getStatusCode());
        $this->assertTrue($this->body($r)['success']);
    }

    /** aggregate() + recordSnapshot(): the write endpoints succeed for an admin. */
    public function testAggregateAndRecordSnapshot(): void
    {
        $this->authAsAdmin();

        $_GET['granularity'] = 'daily';
        $agg = (new AdminStatsController())->aggregate();
        $this->assertSame(200, $agg->getStatusCode());

        $snap = (new AdminStatsController())->recordSnapshot();
        $this->assertSame(200, $snap->getStatusCode());
    }
}
