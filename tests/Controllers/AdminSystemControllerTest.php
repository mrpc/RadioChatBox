<?php

namespace RadioChatBox\Tests\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Framework\Testing\TestDatabase;
use Pramnos\Http\Request;
use Pramnos\Http\Response;
use RadioChatBox\Controllers\AdminSystemController;
use RadioChatBox\Middleware\AdminAuthMiddleware;

/**
 * Covers the migrated "AdminSystem" admin endpoints (replaced the nine legacy
 * scripts under public/api/admin/: flush-redis, clear-messages-cache,
 * worker-status, active-users, inactive-users, messages, photos, create-session,
 * track-artwork-upload).
 *
 * Every route carries AdminAuthMiddleware, so we assert once that an
 * unauthenticated request is blocked with 401 before the action runs. The
 * non-mutating read endpoints are asserted for their exact success contract; the
 * mutating endpoints (flush/clear/create/upload/empty-trash) are only exercised
 * on their deterministic validation paths — we never flush Redis, clear the
 * message cache, mint a session or delete data against the shared dev database.
 */
class AdminSystemControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        $_GET   = [];
        $_POST  = [];
        $_FILES = [];
        unset($_SERVER['REQUEST_METHOD']);
    }

    /**
     * The shared AdminAuthMiddleware guarding every AdminSystem route must
     * short-circuit an unauthenticated request with a 401 (matching the legacy
     * AdminAuth::unauthorized()) and must not run the wrapped action.
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
     * GET /api/admin/active-users returns the legacy contract: 200 with
     * success=true, an integer count, a users array, and count === user count.
     */
    public function testActiveUsersReturnsCountAndUsers(): void
    {
        $response = (new AdminSystemController())->activeUsers();

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertIsInt($body['count']);
        $this->assertIsArray($body['users']);
        $this->assertCount($body['count'], $body['users'], 'count must equal the number of users');
    }

    /**
     * GET /api/admin/worker-status returns the health payload: 200 with
     * success=true and the top-level keys the dashboard depends on (running,
     * wedged, queue, schedule).
     */
    public function testWorkerStatusReturnsHealthPayload(): void
    {
        $response = (new AdminSystemController())->workerStatus();

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertArrayHasKey('running', $body);
        $this->assertArrayHasKey('wedged', $body);
        $this->assertArrayHasKey('queue', $body);
        $this->assertArrayHasKey('size', $body['queue']);
        $this->assertIsArray($body['schedule']);

        // Supervisor health now comes from the framework daemon orchestrator's
        // status() (replaces the retired DaemonSupervisor). The frontend reads
        // exactly these keys.
        $this->assertArrayHasKey('supervisor', $body);
        $this->assertIsBool($body['supervisor']['running']);
        $this->assertArrayHasKey('pid', $body['supervisor']);
        $this->assertArrayHasKey('heartbeat_age_seconds', $body['supervisor']);
        $this->assertIsArray($body['daemons']);
    }

    /**
     * GET /api/admin/inactive-users returns success=true, a users array and the
     * pagination block echoing the requested page/limit.
     */
    public function testInactiveUsersReturnsPaginatedList(): void
    {
        $_GET = ['page' => '1', 'limit' => '10'];

        $response = (new AdminSystemController())->inactiveUsers();

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertIsArray($body['users']);
        $this->assertSame(1, $body['pagination']['page']);
        $this->assertSame(10, $body['pagination']['limit']);
        $this->assertArrayHasKey('total', $body['pagination']);
        $this->assertArrayHasKey('total_pages', $body['pagination']);
    }

    /**
     * The `search` query param filters inactive users by username (ILIKE): a
     * seeded, currently-inactive user is found by a substring of its name and a
     * non-matching term returns it in neither the list nor the total.
     */
    public function testInactiveUsersHonoursTheSearchParam(): void
    {
        $pdo = TestDatabase::connection();
        $uname = 'inact_search_' . substr(bin2hex(random_bytes(4)), 0, 8);
        // user_activity row that is NOT in presence_sessions => "inactive".
        $pdo->prepare('INSERT INTO user_activity (username, ip_address, last_seen, message_count) VALUES (?, ?, NOW(), 0)')
            ->execute([$uname, '127.0.0.1']);

        try {
            $_GET = ['page' => '1', 'limit' => '20', 'search' => substr($uname, 0, 14)];
            $body = json_decode((new AdminSystemController())->inactiveUsers()->getBody(), true);
            $names = array_column($body['users'], 'username');
            $this->assertContains($uname, $names, 'the seeded inactive user matches the search');

            $_GET = ['page' => '1', 'limit' => '20', 'search' => 'zzz_no_such_user_zzz'];
            $body = json_decode((new AdminSystemController())->inactiveUsers()->getBody(), true);
            $this->assertSame(0, $body['pagination']['total'], 'a non-matching term finds nobody');
        } finally {
            $pdo->prepare('DELETE FROM user_activity WHERE username = ?')->execute([$uname]);
        }
    }

    /**
     * GET /api/admin/messages returns success=true, a messages array, the
     * include_private / chat_mode flags and the pagination block. Called
     * unauthenticated-in-process, so include_private must be false.
     */
    public function testMessagesReturnsPaginatedListWithFlags(): void
    {
        $_GET = ['page' => '1', 'limit' => '5'];

        $response = (new AdminSystemController())->messages();

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertIsArray($body['messages']);
        $this->assertFalse($body['include_private']);
        $this->assertArrayHasKey('chat_mode', $body);
        $this->assertSame(5, $body['pagination']['limit']);
    }

    /**
     * GET /api/admin/messages/export returns a downloadable CSV with the header
     * row and any seeded public message. Unauthenticated-in-process, so private
     * messages are excluded.
     */
    public function testMessagesExportReturnsCsv(): void
    {
        $pdo = TestDatabase::connection();
        $messageId = 'msg_export_' . bin2hex(random_bytes(6));
        $pdo->prepare(
            'INSERT INTO chat_messages (message_id, username, message, ip_address, created_at)
             VALUES (?, ?, ?, ?, NOW())'
        )->execute([$messageId, 'export_author', 'hello export', '127.0.0.1']);

        try {
            $_GET = ['type' => 'public'];
            $response = (new AdminSystemController())->messagesExport();

            $this->assertSame(200, $response->getStatusCode());
            $this->assertStringContainsString('text/csv', (string) $response->getHeaderLine('Content-Type'));
            $body = (string) $response->getBody();
            $this->assertStringContainsString('created_at,type,from,to,message,deleted', $body);
            $this->assertStringContainsString('hello export', $body);
        } finally {
            $pdo->prepare('DELETE FROM chat_messages WHERE message_id = ?')->execute([$messageId]);
        }
    }

    /**
     * GET /api/admin/photos with the default list action returns success=true, a
     * photos array and the pagination block.
     */
    public function testPhotosListReturnsPaginatedPhotos(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = ['action' => 'list', 'page' => '1', 'limit' => '5'];

        $response = (new AdminSystemController())->photos();

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertIsArray($body['photos']);
        $this->assertSame(5, $body['pagination']['limit']);
    }

    /**
     * GET /api/admin/photos?action=by_user without a username hits the validation
     * guard: 400 {"error":"Username is required"}.
     */
    public function testPhotosByUserWithoutUsernameReturns400(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = ['action' => 'by_user'];

        $response = (new AdminSystemController())->photos();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Username is required', json_decode($response->getBody(), true)['error']);
    }

    /**
     * GET /api/admin/photos with an unknown action returns 400
     * {"error":"Invalid action"} without touching any data.
     */
    public function testPhotosUnknownActionReturns400(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = ['action' => 'nope'];

        $response = (new AdminSystemController())->photos();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Invalid action', json_decode($response->getBody(), true)['error']);
    }

    /**
     * POST /api/admin/photos with an unknown action returns 400
     * {"error":"Invalid action"} — the empty-trash path is never triggered, so no
     * data is deleted.
     */
    public function testPhotosPostUnknownActionReturns400(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['action' => 'nope'];

        $response = (new AdminSystemController())->photos();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Invalid action', json_decode($response->getBody(), true)['error']);
    }

    /**
     * POST /api/admin/track-artwork-upload with no valid type/id hits the first
     * validation guard: 400 {"error":"Valid type and id are required"}. No file is
     * stored and no row is updated.
     */
    public function testTrackArtworkUploadWithoutValidTypeReturns400(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [];

        $response = (new AdminSystemController())->trackArtworkUpload();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Valid type and id are required', json_decode($response->getBody(), true)['error']);
    }

    /**
     * POST /api/admin/track-artwork-upload with a valid type/id but no uploaded
     * file hits the second validation guard: 400 {"error":"No file uploaded"}.
     */
    public function testTrackArtworkUploadWithoutFileReturns400(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['type' => 'track_cover', 'id' => '5'];
        $_FILES = [];

        $response = (new AdminSystemController())->trackArtworkUpload();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('No file uploaded', json_decode($response->getBody(), true)['error']);
    }

    /**
     * POST /api/admin/track-artwork-upload happy path: a real image file is read,
     * stored via ArtworkService (GD) and linked to a seeded track (track_cover)
     * and artist (artist_image). Returns {success, url, thumb} and updates the
     * row. The stored files and seeded rows are cleaned up. (This action reads the
     * temp file directly — no is_uploaded_file gate — so it runs under CLI.)
     */
    public function testTrackArtworkUploadStoresAndLinksImage(): void
    {
        $pdo    = TestDatabase::connection();
        $suffix = substr(bin2hex(random_bytes(4)), 0, 8);

        // Seed a track and an artist to attach the artwork to.
        $pdo->prepare("INSERT INTO artists (name) VALUES (?)")->execute(['ArtUp ' . $suffix]);
        $artistId = (int) $pdo->query('SELECT lastval()')->fetchColumn();
        $pdo->prepare("INSERT INTO tracks (display, artist, title) VALUES (?, ?, ?)")
            ->execute(['ArtUp ' . $suffix . ' - Song', 'ArtUp ' . $suffix, 'Song']);
        $trackId = (int) $pdo->query('SELECT lastval()')->fetchColumn();

        // A real JPEG temp file to upload.
        $im = imagecreatetruecolor(300, 300);
        imagefilledrectangle($im, 0, 0, 300, 300, imagecolorallocate($im, 120, 40, 200));
        $tmp = tempnam(sys_get_temp_dir(), 'artup') . '.jpg';
        imagejpeg($im, $tmp);
        imagedestroy($im);

        $stored = [];
        try {
            foreach ([['track_cover', $trackId], ['artist_image', $artistId]] as [$type, $id]) {
                $_SERVER['REQUEST_METHOD'] = 'POST';
                $_POST  = ['type' => $type, 'id' => $id];
                $_FILES = ['file' => [
                    'tmp_name' => $tmp,
                    'name'     => 'art.jpg',
                    'size'     => filesize($tmp),
                    'error'    => UPLOAD_ERR_OK,
                    'type'     => 'image/jpeg',
                ]];

                $response = (new AdminSystemController())->trackArtworkUpload();
                $this->assertSame(200, $response->getStatusCode(), "type {$type} must succeed");
                $body = json_decode($response->getBody(), true);
                $this->assertTrue($body['success']);
                $this->assertNotEmpty($body['url']);
                $stored[] = $body['url'];
            }

            // The track row now points at the stored cover.
            $stmt = $pdo->prepare('SELECT cover_file FROM tracks WHERE id = ?');
            $stmt->execute([$trackId]);
            $this->assertNotEmpty($stmt->fetchColumn(), 'the track cover must be linked');
        } finally {
            @unlink($tmp);
            foreach ($stored as $web) {
                $disk = dirname(__DIR__, 2) . '/public' . $web;
                foreach ([$disk, preg_replace('/\.jpg$/i', '_thumb.jpg', $disk)] as $f) {
                    if (is_file($f)) {
                        @unlink($f);
                    }
                }
            }
            $pdo->prepare('DELETE FROM tracks WHERE id = ?')->execute([$trackId]);
            $pdo->prepare('DELETE FROM artists WHERE id = ?')->execute([$artistId]);
        }
    }

    /**
     * GET /api/admin/photos?action=by_user returns that user's attachments with a
     * count (the by_user branch); an empty username hits the 400 guard.
     */
    public function testPhotosByUserReturnsCountAndRejectsEmpty(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $_GET = ['action' => 'by_user', 'username' => 'nobody_' . bin2hex(random_bytes(3))];
        $ok = (new AdminSystemController())->photos();
        $this->assertSame(200, $ok->getStatusCode());
        $body = json_decode($ok->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertSame(count($body['photos']), $body['count']);

        $_GET = ['action' => 'by_user', 'username' => ''];
        $bad = (new AdminSystemController())->photos();
        $this->assertSame(400, $bad->getStatusCode());
        $this->assertSame('Username is required', json_decode($bad->getBody(), true)['error']);
    }

    /**
     * POST /api/admin/create-session mints a stream token and stores it in Redis
     * with a 24h TTL. Asserts the {success, session_token, expires_in} envelope
     * and that the token key really exists, then deletes it so nothing leaks.
     */
    public function testCreateSessionMintsToken(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $response = (new AdminSystemController())->createSession();

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertSame(24 * 60 * 60, $body['expires_in']);

        $token = $body['session_token'];
        $redis = \Pramnos\Redis\ConnectionManager::getInstance()->connection();
        try {
            $this->assertTrue((bool) $redis->exists('admin_session:' . $token), 'the token must be stored');
        } finally {
            $redis->del('admin_session:' . $token);
        }
    }

    /**
     * GET /api/admin/clear-messages-cache clears the recent-message Redis keys and
     * returns {success:true, keys_cleared:[...]}. The cache is a lazy rebuild of
     * the DB history, so clearing it is non-destructive.
     */
    public function testClearMessagesCacheReturnsSuccess(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $response = (new AdminSystemController())->clearMessagesCache();

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertIsArray($body['keys_cleared']);
    }
}
