<?php

namespace RadioChatBox\Tests\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Http\Request;
use Pramnos\Http\Response;
use RadioChatBox\Controllers\AdminSettingsController;
use RadioChatBox\Database;
use RadioChatBox\Middleware\AdminAuthMiddleware;

/**
 * Covers the migrated admin settings resource and its auth middleware (replaced
 * public/api/admin/{settings,update-settings,upload-logo,notifications}.php).
 *
 * These tests deliberately exercise only the non-destructive, deterministic
 * branches: the AdminAuthMiddleware 401 gate, the input-validation 400 paths of
 * the settings/update-settings/upload-logo actions (which run before any DB
 * write), and the RBAC 403 gate of the notifications actions (which fires first
 * because an unauthenticated request has no current user). No settings write,
 * file upload or notification mutation is performed against real data.
 */
class AdminSettingsControllerTest extends TestCase
{
    private const ADMIN_ID = 'settingsadmin';
    private ?string $sessionKey = null;

    protected function tearDown(): void
    {
        if ($this->sessionKey !== null) {
            try {
                Database::getRedis()->del($this->sessionKey);
            } catch (\Throwable) {
                // best effort
            }
            $this->sessionKey = null;
        }
        $_POST = [];
        $_GET = [];
        unset($_FILES['logo'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    }

    /**
     * Establish an administrator session so AdminAuth::getCurrentUser() returns a
     * privileged user for the notifications RBAC gate; skips if Redis is down.
     */
    private function authAsAdmin(string $role = 'administrator'): void
    {
        try {
            $redis  = Database::getRedis();
            $prefix = Database::getRedisPrefix();
            $key    = $prefix . 'admin_session:' . self::ADMIN_ID;
            $redis->setex($key, 120, json_encode(['username' => self::ADMIN_ID, 'role' => $role]));
            $this->sessionKey = $key;
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::ADMIN_ID . ':x';
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis unavailable: ' . $e->getMessage());
        }
    }

    /**
     * The AdminAuthMiddleware attached to every route must reject an
     * unauthenticated request with 401 {"error":"Unauthorized"} and never invoke
     * the wrapped action, matching the legacy AdminAuth::unauthorized() gate.
     */
    public function testMiddlewareBlocksUnauthenticatedWith401(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);

        $nextRan = false;
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
     * POST /api/admin/settings with an empty (unparseable) body must reproduce the
     * legacy 400 {"error":"Invalid JSON"} guard before touching SettingsService.
     */
    public function testUpdateRejectsEmptyBodyWith400(): void
    {
        $_POST = [];

        $response = (new AdminSettingsController())->update();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Invalid JSON', json_decode($response->getBody(), true)['error']);
    }

    /**
     * POST /api/admin/update-settings without a "settings" object must return the
     * legacy 400 {success:false, error:"Invalid request format"} before writing.
     */
    public function testUpdateMultipleRejectsMissingSettingsWith400(): void
    {
        $_POST = ['not_settings' => 'x'];

        $response = (new AdminSettingsController())->updateMultiple();

        $this->assertSame(400, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertFalse($body['success']);
        $this->assertSame('Invalid request format', $body['error']);
    }

    /**
     * POST /api/admin/update-settings with a non-array "settings" value must also
     * hit the 400 "Invalid request format" guard (is_array check).
     */
    public function testUpdateMultipleRejectsNonArraySettingsWith400(): void
    {
        $_POST = ['settings' => 'oops'];

        $response = (new AdminSettingsController())->updateMultiple();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Invalid request format', json_decode($response->getBody(), true)['error']);
    }

    /**
     * POST /api/admin/upload-logo without a "logo" file must return the legacy
     * 400 {"error":"No file uploaded"} before any filesystem or DB work.
     */
    public function testUploadLogoRejectsMissingFileWith400(): void
    {
        unset($_FILES['logo']);

        $response = (new AdminSettingsController())->uploadLogo();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('No file uploaded', json_decode($response->getBody(), true)['error']);
    }

    /**
     * POST /api/admin/upload-logo with a disallowed mime type must return the
     * legacy 400 "Invalid file type" guard (no move_uploaded_file is attempted).
     */
    public function testUploadLogoRejectsInvalidTypeWith400(): void
    {
        $_FILES['logo'] = [
            'name' => 'evil.txt',
            'type' => 'text/plain',
            'tmp_name' => '/tmp/nonexistent',
            'error' => 0,
            'size' => 10,
        ];

        $response = (new AdminSettingsController())->uploadLogo();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Invalid file type. Only images are allowed.', json_decode($response->getBody(), true)['error']);
    }

    /**
     * GET /api/admin/notifications must enforce the legacy RBAC gate: with no
     * authenticated admin (AdminAuth::getCurrentUser() === null) it returns 403
     * with the "Only root/administrator can view notifications" message, before
     * any query runs.
     */
    public function testNotificationsIndexForbidsUnauthenticatedWith403(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);

        $response = (new AdminSettingsController())->notificationsIndex();

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(
            'Forbidden: Only root/administrator can view notifications',
            json_decode($response->getBody(), true)['error']
        );
    }

    /**
     * PUT /api/admin/notifications must apply the same RBAC gate first, returning
     * 403 for a request with no authenticated admin.
     */
    public function testNotificationsUpdateForbidsUnauthenticatedWith403(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);

        $response = (new AdminSettingsController())->notificationsUpdate();

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(
            'Forbidden: Only root/administrator can view notifications',
            json_decode($response->getBody(), true)['error']
        );
    }

    /**
     * DELETE /api/admin/notifications must apply the RBAC gate first, returning
     * 403 for a request with no authenticated admin (root-only cleanup is never
     * reached).
     */
    public function testNotificationsCleanupForbidsUnauthenticatedWith403(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);

        $response = (new AdminSettingsController())->notificationsCleanup();

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(
            'Forbidden: Only root/administrator can view notifications',
            json_decode($response->getBody(), true)['error']
        );
    }

    // ---------------------------------------------------------------------
    // DB-path coverage for the Phase-7 conversion (getPDO -> getDb). The
    // notifications actions run the converted preparedQuery reads, stored-function
    // calls and getAffectedRows() count; drive them as an administrator against
    // the dev DB. All are read-only or scoped to a throwaway admin username.
    // ---------------------------------------------------------------------

    /**
     * GET /api/admin/notifications as an administrator returns the converted
     * payload: a notifications list plus an integer unread_count from the
     * get_unread_notification_count() stored function. Read-only.
     */
    public function testNotificationsIndexReturnsPayloadForAdmin(): void
    {
        $this->authAsAdmin();
        $_GET = ['limit' => 10, 'offset' => 0];

        $response = (new AdminSettingsController())->notificationsIndex();

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertIsArray($body['notifications']);
        $this->assertIsInt($body['unread_count']);
        $this->assertSame(self::ADMIN_ID, $body['admin']);
    }

    /**
     * PUT /api/admin/notifications {mark_all_read} as an administrator drives the
     * mark_all_notifications_read() stored function and returns the count message.
     * The throwaway admin username has no notifications, so this is effectively a
     * no-op against real data.
     */
    public function testNotificationsMarkAllReadReturnsSuccessForAdmin(): void
    {
        $this->authAsAdmin();
        $_POST = ['mark_all_read' => true];

        $response = (new AdminSettingsController())->notificationsUpdate();

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertStringContainsString('as read', $body['message']);
    }

    /**
     * DELETE /api/admin/notifications as root (cleanup is root-only) drives the
     * cleanup_old_notifications() stored function and returns the deleted-count
     * message. Only already-old notifications are affected.
     */
    public function testNotificationsCleanupReturnsSuccessForRoot(): void
    {
        $this->authAsAdmin('root');

        $response = (new AdminSettingsController())->notificationsCleanup();

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertStringContainsString('old notification(s)', $body['message']);
    }
}
