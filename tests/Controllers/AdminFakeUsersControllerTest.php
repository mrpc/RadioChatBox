<?php

namespace RadioChatBox\Tests\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Http\Request;
use Pramnos\Http\Response;
use RadioChatBox\Controllers\AdminFakeUsersController;
use RadioChatBox\Middleware\AdminAuthMiddleware;

/**
 * Covers the migrated admin fake-user endpoints and their auth middleware
 * (replaced the public/api/admin/*-fake-user*.php files).
 *
 * The AdminAuthMiddleware must block unauthenticated requests with a 401
 * (matching the legacy AdminAuth::unauthorized()) without running the action;
 * the read actions keep their exact payload shapes; the mutating actions are
 * exercised only on their deterministic validation (400) and RBAC (403) paths
 * so no real data is written or deleted.
 */
class AdminFakeUsersControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        $_POST = [];
        $_GET  = [];
        parent::tearDown();
    }

    /**
     * The route middleware must reject an unauthenticated request with a 401
     * JSON error and must not run the wrapped action.
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
     * list() returns 200 with {success:true, fake_users:[...]} (list-fake-users.php).
     */
    public function testControllerReturnsFakeUsersShape(): void
    {
        $response = (new AdminFakeUsersController())->list();

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertIsArray($body['fake_users']);
    }

    /**
     * index() returns 200 with the dashboard-compatible {success:true, users:[...]}
     * payload (fake-users.php uses the 'users' key, not 'fake_users').
     */
    public function testIndexReturnsUsersKeyShape(): void
    {
        $response = (new AdminFakeUsersController())->index();

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertIsArray($body['users']);
    }

    /**
     * export() returns 200 with {success:true, count:int, fake_users:[...]} and
     * count matches the row count (export-fake-users.php).
     */
    public function testExportReturnsCountAndRows(): void
    {
        $response = (new AdminFakeUsersController())->export();

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertIsArray($body['fake_users']);
        $this->assertSame(count($body['fake_users']), $body['count']);
    }

    /**
     * add() with an empty body hits the "Invalid JSON" guard -> 400
     * (add-fake-user.php).
     */
    public function testAddRejectsEmptyBodyWith400(): void
    {
        $_POST = [];

        $response = (new AdminFakeUsersController())->add();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Invalid JSON', json_decode($response->getBody(), true)['error']);
    }

    /**
     * add() with a too-short nickname is rejected with the exact 400 message
     * (add-fake-user.php nickname length guard).
     */
    public function testAddRejectsShortNicknameWith400(): void
    {
        $_POST = ['nickname' => 'ab'];

        $response = (new AdminFakeUsersController())->add();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(
            'Nickname must be at least 3 characters',
            json_decode($response->getBody(), true)['error']
        );
    }

    /**
     * update() with an empty body hits the "Invalid JSON" guard -> 400
     * (update-fake-user.php).
     */
    public function testUpdateRejectsEmptyBodyWith400(): void
    {
        $_POST = [];

        $response = (new AdminFakeUsersController())->update();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Invalid JSON', json_decode($response->getBody(), true)['error']);
    }

    /**
     * update() with a valid id but no updatable fields returns the exact
     * "Nothing to update" 400 (update-fake-user.php).
     */
    public function testUpdateRejectsWhenNothingToUpdateWith400(): void
    {
        $_POST = ['id' => 1];

        $response = (new AdminFakeUsersController())->update();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Nothing to update', json_decode($response->getBody(), true)['error']);
    }

    /**
     * updateBot() with an empty body hits the "Invalid JSON" guard -> 400
     * (update-fake-user-bot.php).
     */
    public function testUpdateBotRejectsEmptyBodyWith400(): void
    {
        $_POST = [];

        $response = (new AdminFakeUsersController())->updateBot();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Invalid JSON', json_decode($response->getBody(), true)['error']);
    }

    /**
     * updateBot() enforces the 2000-char cap on prompt fields with the exact
     * 400 message (update-fake-user-bot.php).
     */
    public function testUpdateBotRejectsOversizePromptWith400(): void
    {
        $_POST = ['id' => 1, 'bot_persona' => str_repeat('a', 2001)];

        $response = (new AdminFakeUsersController())->updateBot();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(
            'Prompt fields are limited to 2000 characters',
            json_decode($response->getBody(), true)['error']
        );
    }

    /**
     * delete() with an empty body hits the "Invalid JSON" guard -> 400
     * (delete-fake-user.php). No real row is touched.
     */
    public function testDeleteRejectsEmptyBodyWith400(): void
    {
        $_POST = [];

        $response = (new AdminFakeUsersController())->delete();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Invalid JSON', json_decode($response->getBody(), true)['error']);
    }

    /**
     * toggle() with an empty body hits the "Invalid JSON" guard -> 400
     * (toggle-fake-user.php).
     */
    public function testToggleRejectsEmptyBodyWith400(): void
    {
        $_POST = [];

        $response = (new AdminFakeUsersController())->toggle();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Invalid JSON', json_decode($response->getBody(), true)['error']);
    }

    /**
     * import() with an empty body hits the "Invalid JSON" guard -> 400
     * (import-fake-users.php).
     */
    public function testImportRejectsEmptyBodyWith400(): void
    {
        $_POST = [];

        $response = (new AdminFakeUsersController())->import();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Invalid JSON', json_decode($response->getBody(), true)['error']);
    }

    /**
     * clearHistory() is destructive, so it keeps the legacy RBAC gate: with no
     * authenticated admin (getCurrentUser() === null) it returns 403 before any
     * input is read, so no conversation is ever cleared in this test
     * (clear-fake-user-history.php).
     */
    public function testClearHistoryForbidsWithoutPrivilegedRole(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        $_POST = ['id' => 1];

        $response = (new AdminFakeUsersController())->clearHistory();

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(
            'Forbidden: not allowed to delete conversations',
            json_decode($response->getBody(), true)['error']
        );
    }
}
