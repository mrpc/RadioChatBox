<?php

namespace RadioChatBox\Tests\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Http\Request;
use Pramnos\Http\Response;
use RadioChatBox\Controllers\AdminImpersonationController;
use RadioChatBox\Database;
use RadioChatBox\Middleware\AdminAuthMiddleware;

/**
 * Golden-contract tests for the migrated AdminImpersonation resource controller
 * (replaces public/api/admin/impersonate-{bot,block,send,conversations}.php).
 *
 * Two auth gates guard every action: AdminAuthMiddleware (401 for anyone not
 * authenticated) and an in-action root/owner check (403 for a logged-in admin
 * without those roles). The unauthenticated paths are exercised directly (they
 * never touch Redis, since AdminAuth::getCurrentUser() short-circuits on a
 * missing Bearer header). The safe, non-mutating validation/read paths are
 * covered by faking a root session in Redis; those tests self-skip when Redis
 * is unreachable so the file still runs in a DB-only environment. No mutating
 * path (send insert, block/unblock, bot take-over) is ever exercised.
 */
class AdminImpersonationControllerTest extends TestCase
{
    /** Bearer identifier used for the faked root session. */
    private const ROOT_ID = 'imptest_root';

    /** @var string|null Redis session key to clean up in tearDown. */
    private ?string $sessionKey = null;

    protected function tearDown(): void
    {
        if ($this->sessionKey !== null) {
            try {
                Database::getRedis()->del($this->sessionKey);
            } catch (\Throwable $e) {
                // best effort cleanup
            }
            $this->sessionKey = null;
        }
        $_POST = [];
        $_GET  = [];
        unset(
            $_SERVER['REQUEST_METHOD'],
            $_SERVER['HTTP_AUTHORIZATION'],
            $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        );
    }

    /**
     * Establish a fake root admin session so AdminAuth::getCurrentUser() returns
     * a root user. Skips the calling test if Redis is not reachable.
     */
    private function authAsRoot(): void
    {
        try {
            $redis  = Database::getRedis();
            $prefix = Database::getRedisPrefix();
            $key    = $prefix . 'admin_session:' . self::ROOT_ID;
            $redis->setex($key, 120, json_encode([
                'username' => self::ROOT_ID,
                'role'     => 'root',
            ]));
            $this->sessionKey = $key;
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::ROOT_ID . ':x';
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis unavailable: ' . $e->getMessage());
        }
    }

    private function unauthenticate(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    }

    /**
     * The route middleware must reject an unauthenticated request with a 401
     * ({"error":"Unauthorized"}) and never run the wrapped action.
     */
    public function testMiddlewareBlocksUnauthenticatedWith401(): void
    {
        $this->unauthenticate();

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
     * bot() enforces the extra root/owner gate: a request without a root/owner
     * session gets 403 before any bot state is touched.
     */
    public function testBotForbiddenWithoutRootRole(): void
    {
        $this->unauthenticate();
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $response = (new AdminImpersonationController())->bot();

        $this->assertSame(403, $response->getStatusCode());
        $this->assertArrayHasKey('error', json_decode($response->getBody(), true));
    }

    /**
     * block() returns 403 (with the exact legacy message "Forbidden") when the
     * caller is not a root/owner admin.
     */
    public function testBlockForbiddenWithoutRootRole(): void
    {
        $this->unauthenticate();
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $response = (new AdminImpersonationController())->block();

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Forbidden', json_decode($response->getBody(), true)['error']);
    }

    /**
     * send() (a mutating endpoint) refuses non-root callers with 403 before any
     * database write can occur.
     */
    public function testSendForbiddenWithoutRootRole(): void
    {
        $this->unauthenticate();
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $response = (new AdminImpersonationController())->send();

        $this->assertSame(403, $response->getStatusCode());
        $this->assertArrayHasKey('error', json_decode($response->getBody(), true));
    }

    /**
     * conversations() refuses non-root callers with 403.
     */
    public function testConversationsForbiddenWithoutRootRole(): void
    {
        $this->unauthenticate();
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $response = (new AdminImpersonationController())->conversations();

        $this->assertSame(403, $response->getStatusCode());
        $this->assertArrayHasKey('error', json_decode($response->getBody(), true));
    }

    /**
     * bot() GET with missing fake_user/peer returns 400 with the required-fields
     * message (safe: no bot state is read or written).
     */
    public function testBotGetMissingParamsReturns400(): void
    {
        $this->authAsRoot();
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = [];

        $response = (new AdminImpersonationController())->bot();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('fake_user and peer are required', json_decode($response->getBody(), true)['error']);
    }

    /**
     * bot() POST with an unrecognised action returns 400 and the "Unknown action"
     * message (safe: the match falls through to the default before any service
     * call, so no take/release/force is performed).
     */
    public function testBotPostUnknownActionReturns400(): void
    {
        $this->authAsRoot();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['fake_user' => 'someone', 'peer' => 'peerx', 'action' => 'definitely_not_valid'];

        $response = (new AdminImpersonationController())->bot();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(
            'Unknown action (expected take, release, reset or force)',
            json_decode($response->getBody(), true)['error']
        );
    }

    /**
     * block() GET with missing params returns 400 (safe: read-only path never
     * reached).
     */
    public function testBlockGetMissingParamsReturns400(): void
    {
        $this->authAsRoot();
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = [];

        $response = (new AdminImpersonationController())->block();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('impersonate_as and to_username are required', json_decode($response->getBody(), true)['error']);
    }

    /**
     * block() POST with a bogus action (but valid usernames) returns 400 with the
     * action-validation message and performs neither a block nor an unblock.
     */
    public function testBlockPostInvalidActionReturns400(): void
    {
        $this->authAsRoot();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['impersonate_as' => 'fakeone', 'to_username' => 'realone', 'action' => 'nope'];

        $response = (new AdminImpersonationController())->block();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('action must be "block" or "unblock"', json_decode($response->getBody(), true)['error']);
    }

    /**
     * send() POST with no impersonate_as/to_username returns 400 before touching
     * the database (mutating path never reached).
     */
    public function testSendMissingFieldsReturns400(): void
    {
        $this->authAsRoot();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [];

        $response = (new AdminImpersonationController())->send();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Impersonate username and recipient are required', json_decode($response->getBody(), true)['error']);
    }

    /**
     * conversations() as root returns the {success, fake_users[], conversations{}}
     * read shape (read-only queries, safe to run against the dev DB).
     */
    public function testConversationsReturnsShapeForRoot(): void
    {
        $this->authAsRoot();
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $response = (new AdminImpersonationController())->conversations();

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertIsArray($body['fake_users']);
        $this->assertIsArray($body['conversations']);
    }
}
