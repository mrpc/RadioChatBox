<?php

namespace RadioChatBox\Tests\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Http\Request;
use Pramnos\Http\Response;
use RadioChatBox\Controllers\AdminStatsController;
use RadioChatBox\Middleware\AdminAuthMiddleware;

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
    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];
        unset($_SERVER['REQUEST_METHOD']);
        parent::tearDown();
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
}
