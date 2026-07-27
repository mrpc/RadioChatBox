<?php

namespace RadioChatBox\Tests\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Http\Response;
use RadioChatBox\Controllers\CheckNicknameController;
use RadioChatBox\Controllers\HeartbeatController;
use RadioChatBox\Controllers\SettingsController;
use RadioChatBox\Controllers\VersionController;

/**
 * Golden-contract tests for the migrated public endpoints version / settings /
 * check-nickname / heartbeat (replaced public/api/{version,settings,
 * check-nickname,heartbeat}.php).
 *
 * Each asserts the payload contract the frontend depends on, and the POST
 * endpoints assert the empty-body -> 400 guard that the legacy files enforced.
 */
class PublicEndpointsControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        $_POST = [];
    }

    public function testVersionReturnsVersionAndTimestamp(): void
    {
        $response = (new VersionController())->show();

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertArrayHasKey('version', $body);
        $this->assertIsInt($body['timestamp']);
    }

    public function testSettingsReturnsPublicBundleAndNoCacheHeaders(): void
    {
        $response = (new SettingsController())->show();

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success']);
        // The frontend reads these keys off settings; they must always be present.
        foreach (['gif_enabled', 'seo', 'branding', 'ads', 'scripts', 'analytics', 'php_max_upload_mb'] as $key) {
            $this->assertArrayHasKey($key, $body['settings'], "settings.$key missing");
        }
    }

    public function testCheckNicknameRequiresNickname(): void
    {
        $_POST = [];
        $response = (new CheckNicknameController())->store();

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testCheckNicknameReturnsAvailability(): void
    {
        $_POST = ['nickname' => 'probe_' . substr(md5(__METHOD__), 0, 8), 'sessionId' => 'sess'];
        $response = (new CheckNicknameController())->store();

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertIsBool($body['available']);
    }

    public function testHeartbeatRequiresUsernameAndSession(): void
    {
        $_POST = ['username' => 'someone']; // missing sessionId
        $response = (new HeartbeatController())->store();

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testHeartbeatReturnsLiveCountShape(): void
    {
        $_POST = ['username' => 'probe_hb', 'sessionId' => 'sess_probe'];
        $response = (new HeartbeatController())->store();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertIsInt($body['activeUsers']);
        $this->assertArrayHasKey('user_id', $body);
        $this->assertArrayHasKey('user_role', $body);
    }
}
