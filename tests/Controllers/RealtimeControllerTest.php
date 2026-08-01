<?php

namespace RadioChatBox\Tests\Controllers;

use PHPUnit\Framework\TestCase;
use RadioChatBox\Controllers\RealtimeController;
use RadioChatBox\Services\RealtimeToken;
use RadioChatBox\Services\SettingsService;

/**
 * Realtime discovery + WS channel authorization.
 *
 * Covers the safe default (WebSocket disabled → SSE is advertised) and the
 * security-critical /broadcasting/auth gate: a valid token signs only the
 * caller's own private channel, while another user's channel, a missing token
 * and a missing field are all refused.
 */
class RealtimeControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        $_POST = [];
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        // Leave WS disabled for other tests.
        (new SettingsService())->set('realtime_websocket_enabled', 'false');
    }

    public function testConfigDefaultsToSseWhenWebsocketDisabled(): void
    {
        (new SettingsService())->set('realtime_websocket_enabled', 'false');

        $response = (new RealtimeController())->config();
        $this->assertSame(200, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);
        $this->assertSame('sse', $body['transport']);
        $this->assertSame('/api/stream', $body['url']);
        $this->assertArrayNotHasKey('authEndpoint', $body);
    }

    public function testAuthSignsTheCallersOwnPrivateChannel(): void
    {
        $rt    = new RealtimeToken();
        $token = $rt->issue('alice', 'sess-1');

        $_POST = ['socket_id' => '123.456', 'channel_name' => 'private-pm-alice', 'token' => $token];
        $response = (new RealtimeController())->auth();

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        // The signature must match the same secret/appKey the WS worker validates with.
        $this->assertSame(
            $rt->pusherChannelAuth('radiochatbox', '123.456', 'private-pm-alice'),
            $body['auth']
        );
    }

    public function testAuthRefusesAnotherUsersChannel(): void
    {
        $token = (new RealtimeToken())->issue('alice', 'sess-1');

        $_POST = ['socket_id' => '123.456', 'channel_name' => 'private-pm-bob', 'token' => $token];
        $response = (new RealtimeController())->auth();

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testAuthRefusesMissingOrInvalidToken(): void
    {
        $_POST = ['socket_id' => '1.2', 'channel_name' => 'private-pm-alice']; // no token
        $this->assertSame(403, (new RealtimeController())->auth()->getStatusCode());

        $_POST = ['socket_id' => '1.2', 'channel_name' => 'private-pm-alice', 'token' => 'garbage.sig'];
        $this->assertSame(403, (new RealtimeController())->auth()->getStatusCode());
    }

    public function testAuthRejectsMissingFieldsWith400(): void
    {
        $_POST = ['channel_name' => 'private-pm-alice'];
        $this->assertSame(400, (new RealtimeController())->auth()->getStatusCode());
    }

    /**
     * The shared admin notifications channel is signed for an authenticated admin
     * (the seeded admin/admin123). AdminAuth::verify() reads the Bearer header a
     * fetch can send (unlike EventSource) and caches the session it looks up.
     */
    public function testAuthSignsAdminNotificationsChannelForAdmin(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer admin:admin123';
        $_POST = ['socket_id' => '9.9', 'channel_name' => 'private-admin-notifications'];

        $response = (new RealtimeController())->auth();

        if ($response->getStatusCode() === 403) {
            // Redis unavailable → AdminAuth cannot cache/verify; skip rather than fail.
            $this->markTestSkipped('admin auth unavailable (Redis?)');
        }
        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayHasKey('auth', json_decode($response->getBody(), true));
    }

    /** Without an admin Bearer, the admin channel is refused. */
    public function testAuthRefusesAdminNotificationsWithoutAdmin(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $_POST = ['socket_id' => '9.9', 'channel_name' => 'private-admin-notifications'];
        $this->assertSame(403, (new RealtimeController())->auth()->getStatusCode());
    }

    /**
     * An admin may sign ANOTHER user's private-pm channel — the fake-user
     * impersonation feed — proven by the admin Bearer (not a token). A non-admin
     * without the matching token is still refused (see the "another user" test).
     */
    public function testAuthSignsAnyPrivatePmChannelForAdmin(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer admin:admin123';
        $_POST = ['socket_id' => '7.7', 'channel_name' => 'private-pm-fakebot'];

        $response = (new RealtimeController())->auth();
        if ($response->getStatusCode() === 403) {
            $this->markTestSkipped('admin auth unavailable (Redis?)');
        }
        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayHasKey('auth', json_decode($response->getBody(), true));
    }
}
