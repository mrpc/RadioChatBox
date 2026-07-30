<?php

namespace RadioChatBox\Tests\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Http\Request;
use Pramnos\Http\Response;
use Pramnos\Framework\Testing\TestDatabase;
use RadioChatBox\Controllers\AdminFakeUsersController;
use RadioChatBox\Middleware\AdminAuthMiddleware;
use RadioChatBox\Services\FakeUserService;

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
    /** @var string[] nickname fragments to clean from fake_users in tearDown */
    private array $cleanupNicks = [];

    protected function tearDown(): void
    {
        $_POST = [];
        $_GET  = [];
        if ($this->cleanupNicks !== []) {
            $pdo = TestDatabase::connection();
            foreach ($this->cleanupNicks as $n) {
                $pdo->prepare('DELETE FROM fake_users WHERE nickname LIKE ?')->execute(['%' . $n . '%']);
            }
            $this->cleanupNicks = [];
        }
        parent::tearDown();
    }

    private function body(Response $r): array
    {
        return json_decode($r->getBody(), true) ?: [];
    }

    /**
     * The full CRUD lifecycle through the controller: add → list/index/export →
     * update → updateBot → toggle → delete, each returning 200.
     */
    public function testFakeUserLifecycleThroughController(): void
    {
        $frag = substr(bin2hex(random_bytes(4)), 0, 8);
        $this->cleanupNicks[] = $frag;
        $nick = 'ctl' . $frag;

        $_POST = ['nickname' => $nick, 'age' => 25, 'sex' => 'female', 'location' => 'NYC'];
        $add = (new AdminFakeUsersController())->add();
        $this->assertSame(200, $add->getStatusCode());
        $this->assertTrue($this->body($add)['success']);

        $id = (int) (new FakeUserService())->getFakeUserByNickname($nick)['id'];
        $this->assertGreaterThan(0, $id);

        $_GET = [];
        $this->assertSame(200, (new AdminFakeUsersController())->list()->getStatusCode());
        $this->assertSame(200, (new AdminFakeUsersController())->index()->getStatusCode());
        $this->assertSame(200, (new AdminFakeUsersController())->export()->getStatusCode());

        $_POST = ['id' => $id, 'age' => 30, 'location' => 'LA'];
        $this->assertSame(200, (new AdminFakeUsersController())->update()->getStatusCode());

        $_POST = ['id' => $id, 'bot_enabled' => true, 'bot_persona' => 'friendly', 'bot_max_messages' => 4];
        $this->assertSame(200, (new AdminFakeUsersController())->updateBot()->getStatusCode());

        $_POST = ['id' => $id];
        $this->assertSame(200, (new AdminFakeUsersController())->toggle()->getStatusCode());

        $_POST = ['id' => $id];
        $this->assertSame(200, (new AdminFakeUsersController())->delete()->getStatusCode());
    }

    /**
     * import() accepts an export-shaped payload ({fake_users: [...]}) and reports
     * how many were imported.
     */
    public function testImportAddsFakeUsers(): void
    {
        $frag = substr(bin2hex(random_bytes(4)), 0, 8);
        $this->cleanupNicks[] = $frag;

        $_POST = ['fake_users' => [
            ['nickname' => 'imp' . $frag, 'age' => 22, 'sex' => 'male', 'location' => 'LA'],
        ]];
        $r = (new AdminFakeUsersController())->import();
        $this->assertSame(200, $r->getStatusCode());
        $this->assertTrue($this->body($r)['success']);
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
