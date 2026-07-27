<?php

namespace RadioChatBox\Tests\Http;

use PHPUnit\Framework\TestCase;
use Pramnos\Http\Request;
use Pramnos\Http\Response;
use RadioChatBox\Http\Controllers\AdminFakeUsersController;
use RadioChatBox\Http\Middleware\AdminAuthMiddleware;

/**
 * Covers the migrated admin endpoint GET /api/admin/list-fake-users and its auth
 * middleware (replaced public/api/admin/list-fake-users.php).
 *
 * The AdminAuthMiddleware must block unauthenticated requests with a 401 (matching
 * the legacy AdminAuth::unauthorized()) without running the action; the controller
 * itself keeps the {success, fake_users} payload.
 */
class AdminFakeUsersControllerTest extends TestCase
{
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

    public function testControllerReturnsFakeUsersShape(): void
    {
        $response = (new AdminFakeUsersController())->list();

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertIsArray($body['fake_users']);
    }
}
