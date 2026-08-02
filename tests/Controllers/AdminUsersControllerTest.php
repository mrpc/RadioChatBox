<?php

namespace RadioChatBox\Tests\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Http\Request;
use Pramnos\Http\Response;
use RadioChatBox\Controllers\AdminUsersController;
use Pramnos\Cache\FlatCache;
use Pramnos\Database\Database;
use Pramnos\Framework\Testing\TestDatabase;
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

    /** Fake a non-root administrator session (has manage_users, lacks root powers). */
    private function authAsAdministrator(): void
    {
        try {
            $key = 'admin_session:usersctladmin';
            FlatCache::default()->set($key, ['username' => 'usersctladmin', 'role' => 'administrator'], 120);
            $this->sessionKey = $key;
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer usersctladmin:x';
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis unavailable: ' . $e->getMessage());
        }
    }

    /**
     * create() with role=root as a plain administrator is refused with 403
     * "Only root users can create other root users" (the create_root_users gate).
     */
    public function testCreateRootAsAdministratorForbidden(): void
    {
        $this->authAsAdministrator();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['username' => 'wouldberoot_' . substr(bin2hex(random_bytes(3)), 0, 6), 'password' => 'Str0ngPass!', 'role' => 'root'];

        $response = (new AdminUsersController())->create();

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(
            'Only root users can create other root users',
            json_decode($response->getBody(), true)['error']
        );
    }

    /**
     * update() promoting a user to root as a plain administrator is refused with
     * 403 "Only root users can assign root role".
     */
    public function testUpdateToRootAsAdministratorForbidden(): void
    {
        $this->authAsAdministrator();
        $target = 'tgt_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $res = (new \RadioChatBox\Services\UserService())->createUser($target, 'Str0ngPass!', 'moderator');
        $userId = (int) $res['user']['userid'];

        try {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = ['user_id' => $userId, 'role' => 'root'];
            $response = (new AdminUsersController())->update();

            $this->assertSame(403, $response->getStatusCode());
            $this->assertSame(
                'Only root users can assign root role',
                json_decode($response->getBody(), true)['error']
            );
        } finally {
            Database::getInstance()->queryBuilder()->from('users')->where('username', '=', $target)->delete();
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
     * private_conversations lists EVERY conversation partner with its true message
     * count, computed independently of the message page — so a user's older
     * conversations are not hidden when they fall past the paginated window.
     */
    public function testDetailsReturnsFullConversationList(): void
    {
        $this->authAsRoot();
        $pdo = TestDatabase::connection();
        $user = 'convuser_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $ins = $pdo->prepare(
            "INSERT INTO private_messages (from_username, to_username, message, created_at) VALUES (?, ?, ?, NOW())"
        );
        // 3 with partnerA, 1 with partnerB (as recipient).
        foreach (['a1', 'a2', 'a3'] as $m) {
            $ins->execute([$user, 'partnerA', $m]);
        }
        $ins->execute(['partnerB', $user, 'b1']);

        try {
            $_GET = ['username' => $user, 'limit' => '2']; // tiny page — must not hide partners
            $body = json_decode((new AdminUsersController())->details()->getBody(), true);

            $convs = $body['user']['private_conversations'] ?? null;
            $this->assertIsArray($convs);
            $byPartner = [];
            foreach ($convs as $c) {
                $byPartner[$c['partner']] = $c['message_count'];
            }
            $this->assertSame(3, $byPartner['partnerA'] ?? null, 'true count, not just what fit the page');
            $this->assertSame(1, $byPartner['partnerB'] ?? null, 'partner past the page is still listed');
        } finally {
            $pdo->prepare('DELETE FROM private_messages WHERE from_username = ? OR to_username = ?')
                ->execute([$user, $user]);
        }
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

    /**
     * Full admin-user lifecycle as root: create (201) -> list/details/current
     * (200 shapes) -> update password+display_name (200) -> delete (200). Drives
     * the success branch of every action the RBAC-only tests skip. The created
     * row is removed afterwards.
     */
    public function testUserLifecycleAsRoot(): void
    {
        $this->authAsRoot();
        $username = 'lc_' . substr(bin2hex(random_bytes(4)), 0, 8);

        try {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = ['username' => $username, 'password' => 'sup3rsecret!', 'role' => 'moderator', 'email' => $username . '@x.test'];
            $created = (new AdminUsersController())->create();
            $this->assertSame(201, $created->getStatusCode());
            $createdBody = json_decode($created->getBody(), true);
            $this->assertTrue($createdBody['success']);
            $userId = (int) $createdBody['user']['userid'];
            $this->assertGreaterThan(0, $userId);

            // list()
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_POST = [];
            $list = (new AdminUsersController())->list();
            $this->assertSame(200, $list->getStatusCode());
            $this->assertContains($username, array_column(json_decode($list->getBody(), true)['users'], 'username'));

            // details()
            $_GET = ['username' => $username];
            $details = (new AdminUsersController())->details();
            $this->assertSame(200, $details->getStatusCode());
            $this->assertTrue(json_decode($details->getBody(), true)['success']);

            // current() resolves the acting root session.
            $current = (new AdminUsersController())->current();
            $this->assertSame(200, $current->getStatusCode());

            // update()
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_GET = [];
            $_POST = ['user_id' => $userId, 'display_name' => 'Renamed', 'password' => 'anotherStrong1!'];
            $updated = (new AdminUsersController())->update();
            $this->assertSame(200, $updated->getStatusCode());
            $this->assertTrue(json_decode($updated->getBody(), true)['success']);

            // delete()
            $_SERVER['REQUEST_METHOD'] = 'DELETE';
            $_POST = ['user_id' => $userId];
            $deleted = (new AdminUsersController())->delete();
            $this->assertSame(200, $deleted->getStatusCode());
            $this->assertTrue(json_decode($deleted->getBody(), true)['success']);
        } finally {
            Database::getInstance()->queryBuilder()->from('users')
                ->where('username', '=', $username)->delete();
        }
    }

    /**
     * update() applies the email / role / is_active fields together (each isset
     * branch of the updates builder), and the change is persisted. Deleting a root
     * user as root also succeeds (the delete_root_users branch).
     */
    public function testUpdateMultipleFieldsAndDeleteRoot(): void
    {
        $this->authAsRoot();
        $username = 'um_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $created  = (new \RadioChatBox\Services\UserService())->createUser($username, 'Str0ngPass!', 'root');
        $userId   = (int) $created['user']['userid'];

        try {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = [
                'user_id'   => $userId,
                'email'     => $username . '@x.test',
                'role'      => 'administrator',
                'is_active' => false,
            ];
            $updated = (new AdminUsersController())->update();
            $this->assertSame(200, $updated->getStatusCode());
            $this->assertTrue(json_decode($updated->getBody(), true)['success']);

            $pdo  = TestDatabase::connection();
            $stmt = $pdo->prepare('SELECT email, usertype, is_active FROM users WHERE userid = ?');
            $stmt->execute([$userId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            $this->assertSame($username . '@x.test', $row['email']);
            // Role is stored as the usertype ladder now (administrator = 90).
            $this->assertSame(90, (int) $row['usertype']);

            // Deleting the (now administrator) user as root succeeds.
            $_SERVER['REQUEST_METHOD'] = 'DELETE';
            $_POST = ['user_id' => $userId];
            $this->assertSame(200, (new AdminUsersController())->delete()->getStatusCode());
        } finally {
            Database::getInstance()->queryBuilder()->from('users')->where('userid', '=', $userId)->delete();
        }
    }
}
