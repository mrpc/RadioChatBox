<?php

namespace RadioChatBox\Tests\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Http\Request;
use Pramnos\Http\Response;
use RadioChatBox\Controllers\AdminUsersController;
use Pramnos\Cache\FlatCache;
use RadioChatBox\Database;
use RadioChatBox\Middleware\AdminAuthMiddleware;

/**
 * Covers the migrated admin user-management endpoints (replaces
 * public/api/admin/{create-user,update-user,delete-user,users,user-details,current-user}.php).
 *
 * Every route carries AdminAuthMiddleware, so an unauthenticated request is
 * rejected with 401 before the action runs. Beyond authentication the actions
 * keep the legacy RBAC: the manage_users gate returns a 403
 * "Forbidden: Insufficient permissions" (from AdminAuth::hasPermission) and
 * current-user/user-details enforce their own session/argument checks. These
 * deterministic guard paths are asserted here without performing any
 * destructive create/update/delete against real data (guide rule: never run
 * destructive ops in tests).
 */
class AdminUsersControllerTest extends TestCase
{
    private const ROOT_ID = 'usersctlroot';
    private ?string $sessionKey = null;

    protected function tearDown(): void
    {
        if ($this->sessionKey !== null) {
            try {
                FlatCache::default()->delete($this->sessionKey);
            } catch (\Throwable) {
                // best effort
            }
            $this->sessionKey = null;
        }
        $_POST = [];
        $_GET  = [];
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    }

    /**
     * Establish a root admin session so AdminAuth::getCurrentUser()/hasPermission()
     * return a privileged user; skips the test if Redis is unreachable.
     */
    private function authAsRoot(): void
    {
        try {
            $key = 'admin_session:' . self::ROOT_ID;
            FlatCache::default()->set($key, ['username' => self::ROOT_ID, 'role' => 'root'], 120);
            $this->sessionKey = $key;
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::ROOT_ID . ':x';
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis unavailable: ' . $e->getMessage());
        }
    }

    /**
     * The AdminAuthMiddleware guarding the routes must short-circuit an
     * unauthenticated request with a 401 {"error":"Unauthorized"} and never
     * invoke the wrapped action.
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
     * create() enforces the legacy manage_users permission: with no
     * authenticated admin session, AdminAuth::hasPermission('manage_users') is
     * false, so the action returns 403 "Forbidden: Insufficient permissions"
     * before touching the JSON body or creating anything.
     */
    public function testCreateForbiddenWithoutManageUsersPermission(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        $_POST = ['username' => 'x', 'password' => 'y', 'role' => 'moderator'];

        $response = (new AdminUsersController())->create();

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Forbidden: Insufficient permissions', json_decode($response->getBody(), true)['error']);
    }

    /**
     * update() is likewise gated by manage_users and returns the same 403 for
     * an unauthenticated caller, so no update is attempted.
     */
    public function testUpdateForbiddenWithoutManageUsersPermission(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        $_POST = ['user_id' => 1, 'email' => 'a@b.c'];

        $response = (new AdminUsersController())->update();

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Forbidden: Insufficient permissions', json_decode($response->getBody(), true)['error']);
    }

    /**
     * delete() is gated by manage_users and returns the same 403 for an
     * unauthenticated caller — the permission check runs before the body is
     * read, so the destructive deleteUser() is never reached.
     */
    public function testDeleteForbiddenWithoutManageUsersPermission(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        $_POST = ['user_id' => 1];

        $response = (new AdminUsersController())->delete();

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Forbidden: Insufficient permissions', json_decode($response->getBody(), true)['error']);
    }

    /**
     * list() (GET /api/admin/users) is gated by manage_users and returns 403
     * for an unauthenticated caller.
     */
    public function testListForbiddenWithoutManageUsersPermission(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);

        $response = (new AdminUsersController())->list();

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Forbidden: Insufficient permissions', json_decode($response->getBody(), true)['error']);
    }

    /**
     * details() has no manage_users gate (matching the legacy file), so an
     * authenticated caller with no ?username query parameter reaches the
     * argument check and gets 400 "Username parameter required" without running
     * any user-specific queries.
     */
    public function testDetailsRequiresUsernameParameter(): void
    {
        $_GET = [];

        $response = (new AdminUsersController())->details();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Username parameter required', json_decode($response->getBody(), true)['error']);
    }

    /**
     * details() with a username runs every converted read (profile, message
     * count + page, IP list, active session and — as root, which holds
     * view_private_messages — the private-message count + page). Driving it as
     * root against a username with no data pins that all nine preparedQuery reads
     * execute and assemble the exact success shape (empty collections, not an
     * error). This is the DB-path coverage the Phase-7 conversion needed.
     */
    public function testDetailsAsRootReturnsFullShapeForUnknownUser(): void
    {
        $this->authAsRoot();
        $_GET = ['username' => 'nouser_' . substr(bin2hex(random_bytes(4)), 0, 8)];

        $response = (new AdminUsersController())->details();

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertNull($body['user']['profile'], 'no profile row => null');
        $this->assertSame([], $body['user']['messages']);
        $this->assertSame([], $body['user']['ip_addresses']);
        $this->assertArrayHasKey('private_messages', $body['user']);
        $this->assertSame([], $body['user']['private_messages']);
    }

    /**
     * current() resolves the caller's session via AdminAuth::getCurrentUser();
     * with no authenticated session that is null, so the action returns 401
     * "Unauthorized" exactly as the legacy endpoint did.
     */
    public function testCurrentReturns401WhenNoSession(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);

        $response = (new AdminUsersController())->current();

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Unauthorized', json_decode($response->getBody(), true)['error']);
    }

    /**
     * create() with a non-empty body missing a required field returns the legacy
     * 400 {"error":"Missing required fields: username, password, role"} (now via
     * the shared Validate helper) — one combined message regardless of which
     * field is absent. No user is created (validation precedes the service call).
     */
    public function testCreateRejectsMissingRequiredFields(): void
    {
        $this->authAsRoot();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        // Non-empty body (so it is not the "Invalid JSON" guard) but password/role absent.
        $_POST = ['username' => 'someone'];

        $response = (new AdminUsersController())->create();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(
            'Missing required fields: username, password, role',
            json_decode($response->getBody(), true)['error']
        );
    }
}
