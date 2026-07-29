<?php

namespace RadioChatBox\Tests\Controllers;

use PHPUnit\Framework\TestCase;
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
        $_GET  = [];
        $_POST = [];
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
}
