<?php

namespace RadioChatBox\Tests\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Http\Request;
use Pramnos\Http\Response;
use RadioChatBox\Controllers\AdminModerationController;
use RadioChatBox\Middleware\AdminAuthMiddleware;

/**
 * Covers the migrated admin moderation endpoints (replaced the eight
 * public/api/admin/*.php files: ban-ip, ban-nickname, kick-user,
 * list-kicked-users, clear-chat, delete-message, url-blacklist, url-whitelist).
 *
 * Per the authoring guide, destructive operations (ban/kick/clear/delete) are
 * never executed against real data here: only the AdminAuthMiddleware 401 path,
 * the deterministic validation (400) paths, and the safe non-mutating GET read
 * (list-kicked-users) are exercised.
 */
class AdminModerationControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        $_POST = [];
        $_GET  = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    /**
     * The shared AdminAuthMiddleware guarding every route must short-circuit an
     * unauthenticated request with a 401 {"error":"Unauthorized"} and never run
     * the wrapped action (matching the legacy AdminAuth::unauthorized()).
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
     * ban-ip POST with no ip_address must return the legacy 400
     * {error:'IP address is required'} and never call banIP().
     */
    public function testBanIpRejectsMissingIpAddress(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [];

        $response = (new AdminModerationController())->banIp();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('IP address is required', json_decode($response->getBody(), true)['error']);
    }

    /**
     * ban-nickname POST with no nickname must return the legacy 400
     * {error:'Nickname is required'} and never call banNickname().
     */
    public function testBanNicknameRejectsMissingNickname(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [];

        $response = (new AdminModerationController())->banNickname();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Nickname is required', json_decode($response->getBody(), true)['error']);
    }

    /**
     * kick-user POST with no username must return the legacy 400
     * {error:'Username required'} before touching the sessions table.
     */
    public function testKickUserRejectsMissingUsername(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [];

        $response = (new AdminModerationController())->kickUser();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Username required', json_decode($response->getBody(), true)['error']);
    }

    /**
     * delete-message with no message_id must return the legacy 400
     * {error:'Message ID is required'} before any soft-delete runs.
     */
    public function testDeleteMessageRejectsMissingMessageId(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [];

        $response = (new AdminModerationController())->deleteMessage();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Message ID is required', json_decode($response->getBody(), true)['error']);
    }

    /**
     * url-blacklist POST with a blank pattern must return the legacy 400
     * {error:'Pattern is required'} without inserting a row.
     */
    public function testUrlBlacklistRejectsMissingPattern(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['pattern' => '   '];

        $response = (new AdminModerationController())->urlBlacklist();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Pattern is required', json_decode($response->getBody(), true)['error']);
    }

    /**
     * url-blacklist DELETE with no ?id must return the legacy 400
     * {error:'ID is required'} without deleting a row.
     */
    public function testUrlBlacklistRejectsMissingIdOnDelete(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'DELETE';
        $_GET = [];

        $response = (new AdminModerationController())->urlBlacklist();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('ID is required', json_decode($response->getBody(), true)['error']);
    }

    /**
     * url-whitelist POST with a blank pattern must return the legacy 400
     * {error:'Pattern is required'} without inserting a row.
     */
    public function testUrlWhitelistRejectsMissingPattern(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['pattern' => '   '];

        $response = (new AdminModerationController())->urlWhitelist();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Pattern is required', json_decode($response->getBody(), true)['error']);
    }

    /**
     * url-whitelist DELETE with no ?id must return the legacy 400
     * {error:'ID is required'} without deleting a row.
     */
    public function testUrlWhitelistRejectsMissingIdOnDelete(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'DELETE';
        $_GET = [];

        $response = (new AdminModerationController())->urlWhitelist();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('ID is required', json_decode($response->getBody(), true)['error']);
    }

    /**
     * list-kicked-users is a safe, non-mutating Redis read: it must return 200
     * with a {kicked_sessions: [...]} array (the legacy payload had no success
     * key), regardless of how many kicked sessions currently exist.
     */
    public function testListKickedReturnsSessionsShape(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $response = (new AdminModerationController())->listKicked();

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('kicked_sessions', $body);
        $this->assertIsArray($body['kicked_sessions']);
    }
}
