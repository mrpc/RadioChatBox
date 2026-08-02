<?php

namespace RadioChatBox\Tests\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Http\Request;
use Pramnos\Http\Response;
use RadioChatBox\Controllers\AdminSettingsController;
use RadioChatBox\Services\SettingsService;
use Pramnos\Cache\FlatCache;
use Pramnos\Database\Database;
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
                FlatCache::default()->delete($this->sessionKey);
            } catch (\Throwable) {
                // best effort
            }
            $this->sessionKey = null;
        }
        $_POST = [];
        $_GET = [];
        // Reset the framework PUT store + method so a PUT-body test never leaks
        // into the next test (the notifications actions read this, not $_POST).
        Request::$putData = [];
        Request::$deleteData = [];
        Request::$requestMethod = 'GET';
        unset($_FILES['logo'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    }

    /**
     * Establish an administrator session so AdminAuth::getCurrentUser() returns a
     * privileged user for the notifications RBAC gate; skips if Redis is down.
     */
    private function authAsAdmin(string $role = 'administrator'): void
    {
        try {
            $key = 'admin_session:' . self::ADMIN_ID;
            FlatCache::default()->set($key, ['username' => self::ADMIN_ID, 'role' => $role], 120);
            $this->sessionKey = $key;
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::ADMIN_ID . ':x';
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis unavailable: ' . $e->getMessage());
        }
    }

    /**
     * Simulate a PUT request body the way the framework delivers it: a real PUT's
     * JSON body is decoded into the Request put store, NOT $_POST (PHP only fills
     * $_POST for POST). The notifications endpoint is PUT, so the earlier tests
     * that seeded $_POST were passing while production 400'd — these helpers keep
     * the tests faithful to the transport.
     */
    private function putBody(array $data): void
    {
        Request::$requestMethod = 'PUT';
        Request::$putData = $data;
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
        $this->putBody(['mark_all_read' => true]);

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

    /**
     * GET /api/admin/settings returns the full settings envelope: it reads every
     * row (password hash filtered out), computes the embed code / PHP upload cap
     * and folds in the bot provider/model catalog. Non-mutating.
     */
    public function testShowReturnsSettingsEnvelope(): void
    {
        $response = (new AdminSettingsController())->show();

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertIsArray($body['settings']);
        // Computed extras are always present regardless of DB contents.
        $this->assertArrayHasKey('embed_code', $body['settings']);
        $this->assertArrayHasKey('bot_providers', $body['settings']);
        $this->assertArrayNotHasKey('admin_password_hash', $body['settings'], 'the hash must never be sent');
    }

    /**
     * POST /api/admin/settings applies a whitelisted key through
     * SettingsService::updateFromAdmin and reports success. The prior page_title
     * is snapshotted and restored so shared settings state does not leak.
     */
    public function testUpdateAppliesWhitelistedSetting(): void
    {
        $settings = new \RadioChatBox\Services\SettingsService();
        $previous = (string) $settings->get('page_title', 'RadioChatBox');

        try {
            $_POST = ['page_title' => 'Coverage Title ' . substr(bin2hex(random_bytes(3)), 0, 6)];
            $response = (new AdminSettingsController())->update();

            $this->assertSame(200, $response->getStatusCode());
            $body = json_decode($response->getBody(), true);
            $this->assertTrue($body['success']);
            $this->assertSame($_POST['page_title'], $settings->get('page_title'));
        } finally {
            $settings->setMultiple(['page_title' => $previous]);
        }
    }

    /**
     * Export returns only whitelisted settings (a known editable key is present,
     * the password hash never is), and import round-trips a value through the same
     * whitelist while ignoring unknown keys.
     */
    public function testExportThenImportRoundTripsWhitelistedSettings(): void
    {
        $settings = new SettingsService();
        $previous = (string) $settings->get('chat_mode', 'both');

        try {
            $export = json_decode((new AdminSettingsController())->exportSettings()->getBody(), true);
            $this->assertTrue($export['success']);
            $this->assertArrayHasKey('chat_mode', $export['settings']);
            $this->assertArrayNotHasKey('admin_password_hash', $export['settings']);

            $_POST = ['settings' => ['chat_mode' => 'public', 'totally_unknown_key_xyz' => 'x']];
            $import = json_decode((new AdminSettingsController())->importSettings()->getBody(), true);
            $this->assertTrue($import['success']);
            $this->assertContains('totally_unknown_key_xyz', $import['ignored'], 'unknown key ignored, never written');
            $this->assertSame('public', $settings->get('chat_mode'), 'whitelisted key applied on import');
        } finally {
            $_POST = ['settings' => ['chat_mode' => $previous]];
            (new AdminSettingsController())->importSettings();
        }
    }

    /**
     * POST /api/admin/upload-logo happy path: with a valid image and the injected
     * file mover (copy, since a PHPUnit temp file is not a genuine upload) it
     * stores the file, writes the logo_url setting and returns {success, url}. The
     * stored file and the setting are cleaned up.
     */
    public function testUploadLogoStoresFileAndSetting(): void
    {
        $im = imagecreatetruecolor(64, 64);
        imagefilledrectangle($im, 0, 0, 64, 64, imagecolorallocate($im, 200, 200, 0));
        $tmp = tempnam(sys_get_temp_dir(), 'logo') . '.png';
        imagepng($im, $tmp);
        imagedestroy($im);

        $_POST  = ['type' => 'logo'];
        $_FILES = ['logo' => [
            'tmp_name' => $tmp,
            'name'     => 'brand.png',
            'size'     => filesize($tmp),
            'error'    => UPLOAD_ERR_OK,
            'type'     => 'image/png',
        ]];

        $controller = new AdminSettingsController(static fn (string $from, string $to): bool => copy($from, $to));

        $storedUrl = null;
        try {
            $response = $controller->uploadLogo();

            $this->assertSame(200, $response->getStatusCode());
            $body = json_decode($response->getBody(), true);
            $this->assertTrue($body['success']);
            $this->assertStringContainsString('/uploads/logos/', $body['url']);
            $storedUrl = $body['url'];

            // The setting was written.
            $this->assertSame($body['url'], (new \RadioChatBox\Services\SettingsService())->get('logo_url'));
        } finally {
            @unlink($tmp);
            if ($storedUrl !== null) {
                $rel  = parse_url($storedUrl, PHP_URL_PATH) ?: '';
                $disk = dirname(__DIR__, 2) . '/public' . $rel;
                if (is_file($disk)) {
                    @unlink($disk);
                }
            }
            Database::getInstance()->queryBuilder()->from('settings')
                ->where('setting', '=', 'logo_url')->delete();
        }
    }

    /**
     * update() reports ignored unknown keys and rejected (clamped/invalid) values
     * rather than silently succeeding: an unrecognised key lands in `ignored`, and
     * an out-of-range whitelisted value lands in `rejected`. Nothing valid is
     * written, so no snapshot/restore is needed.
     */
    public function testUpdateReportsIgnoredAndRejectedKeys(): void
    {
        // Unknown key → ignored.
        $_POST = ['this_is_not_a_setting_' . substr(bin2hex(random_bytes(3)), 0, 6) => 'x'];
        $ignored = (new AdminSettingsController())->update();
        $this->assertSame(200, $ignored->getStatusCode());
        $ignoredBody = json_decode($ignored->getBody(), true);
        $this->assertNotEmpty($ignoredBody['ignored']);
        $this->assertStringContainsString('ignored unknown keys', $ignoredBody['message']);

        // A whitelisted key with an invalid value → rejected (unknown provider).
        $_POST = ['bot_llm_provider' => 'nonexistent_provider'];
        $rejected = (new AdminSettingsController())->update();
        $this->assertSame(200, $rejected->getStatusCode());
        $rejectedBody = json_decode($rejected->getBody(), true);
        $this->assertNotEmpty($rejectedBody['rejected']);
        $this->assertStringContainsString('Saved, except', $rejectedBody['message']);
    }

    /**
     * PUT /api/admin/notifications with clear_read removes the caller's already-read
     * notifications and reports the count (the clear-read branch; typically 0 in a
     * clean test DB, which still exercises the DELETE + count).
     */
    public function testNotificationsUpdateClearRead(): void
    {
        $this->authAsAdmin();
        $this->putBody(['clear_read' => true]);

        $response = (new AdminSettingsController())->notificationsUpdate();

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertStringContainsString('read notification(s)', $body['message']);
    }

    /**
     * PUT /api/admin/notifications with a non-empty body carrying none of
     * notification_id/mark_all_read/clear_read hits the "required" 400 guard.
     */
    public function testNotificationsUpdateRequiresAnAction(): void
    {
        $this->authAsAdmin();
        $this->putBody(['unrelated' => 'x']);

        $response = (new AdminSettingsController())->notificationsUpdate();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(
            'notification_id, mark_all_read, or clear_read is required',
            json_decode($response->getBody(), true)['error']
        );
    }

    /**
     * Regression for the "mark all as read" 400: a real PUT never populates
     * $_POST, so seeding only $_POST (with an EMPTY put store) must NOT satisfy
     * the action — the handler reads the put store. On the old $_POST-reading code
     * this returned 200; now it correctly falls through to the "required" 400.
     */
    public function testNotificationsUpdateIgnoresPostBodyOnPut(): void
    {
        $this->authAsAdmin();
        Request::$requestMethod = 'PUT';
        Request::$putData = [];        // as a real PUT arrives
        $_POST = ['mark_all_read' => true];

        $response = (new AdminSettingsController())->notificationsUpdate();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(
            'notification_id, mark_all_read, or clear_read is required',
            json_decode($response->getBody(), true)['error']
        );
    }

    /**
     * Authz gate (role→usertype): a moderator is below administrator, so the
     * notifications update is forbidden with 403 even with a valid action body.
     */
    public function testNotificationsUpdateForbidsModeratorWith403(): void
    {
        $this->authAsAdmin('moderator');
        $this->putBody(['mark_all_read' => true]);

        $response = (new AdminSettingsController())->notificationsUpdate();

        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * Authz gate: cleanup is root-only, so an administrator (usertype below root)
     * is rejected with 403 before the cleanup stored function runs.
     */
    public function testNotificationsCleanupForbidsAdministratorWith403(): void
    {
        $this->authAsAdmin('administrator');

        $response = (new AdminSettingsController())->notificationsCleanup();

        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * POST /api/admin/update-settings writes an arbitrary key/value map via
     * SettingsService::setMultiple and reports success. A throwaway key is used
     * and removed afterwards so no real setting is disturbed.
     */
    public function testUpdateMultipleWritesSettings(): void
    {
        $key = 'coverage_probe_' . substr(bin2hex(random_bytes(4)), 0, 8);

        try {
            $_POST = ['settings' => [$key => 'on']];
            $response = (new AdminSettingsController())->updateMultiple();

            $this->assertSame(200, $response->getStatusCode());
            $this->assertTrue(json_decode($response->getBody(), true)['success']);
            $this->assertSame('on', (new \RadioChatBox\Services\SettingsService())->get($key));
        } finally {
            Database::getInstance()->queryBuilder()->from('settings')
                ->where('setting', '=', $key)->delete();
        }
    }
}
