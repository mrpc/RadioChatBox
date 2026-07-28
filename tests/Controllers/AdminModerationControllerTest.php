<?php

namespace RadioChatBox\Tests\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Http\Request;
use Pramnos\Http\Response;
use RadioChatBox\Controllers\AdminModerationController;
use RadioChatBox\Database;
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

    // ---------------------------------------------------------------------
    // DB-path coverage for the Phase-7 conversion (getPDO -> getDb). These run
    // against the dedicated url_blacklist table / non-existent message ids and
    // clean up after themselves — they do not touch real chat data.
    // ---------------------------------------------------------------------

    /**
     * url-blacklist exercises the converted query()/insert()/delete() path AND
     * the rewritten duplicate handling: the legacy code caught a PDOException
     * with code 23505, but the framework DB layer throws a generic Exception, so
     * the controller now classifies by message text. Insert a unique pattern
     * (200), insert it again (400 "Pattern already exists"), confirm GET lists it,
     * then delete it (200). All rows are cleaned up.
     */
    public function testUrlBlacklistInsertDuplicateListAndDelete(): void
    {
        $pattern = 'phase7-' . bin2hex(random_bytes(5)) . '.example';
        $pdo     = Database::getPDO();

        try {
            // First insert succeeds.
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = ['pattern' => $pattern, 'description' => 'phase7 test'];
            $created = (new AdminModerationController())->urlBlacklist();
            $this->assertSame(200, $created->getStatusCode());
            $this->assertTrue(json_decode($created->getBody(), true)['success']);

            // Second insert of the same pattern hits the unique constraint and must
            // map to the legacy 400 message via the new message-based classifier.
            $_POST = ['pattern' => $pattern, 'description' => 'dupe'];
            $dupe  = (new AdminModerationController())->urlBlacklist();
            $this->assertSame(400, $dupe->getStatusCode());
            $this->assertSame('Pattern already exists', json_decode($dupe->getBody(), true)['error']);

            // GET lists it (query() path).
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_POST = [];
            $list  = (new AdminModerationController())->urlBlacklist();
            $this->assertSame(200, $list->getStatusCode());
            $patterns = array_column(json_decode($list->getBody(), true)['patterns'], 'pattern');
            $this->assertContains($pattern, $patterns);

            // DELETE by id (delete() path).
            $stmt = $pdo->prepare('SELECT id FROM url_blacklist WHERE pattern = ?');
            $stmt->execute([$pattern]);
            $id = (int) $stmt->fetchColumn();
            $this->assertGreaterThan(0, $id);

            $_SERVER['REQUEST_METHOD'] = 'DELETE';
            $_GET = ['id' => $id];
            $deleted = (new AdminModerationController())->urlBlacklist();
            $this->assertSame(200, $deleted->getStatusCode());
            $this->assertTrue(json_decode($deleted->getBody(), true)['success']);

            $stmt = $pdo->prepare('SELECT COUNT(*) FROM url_blacklist WHERE pattern = ?');
            $stmt->execute([$pattern]);
            $this->assertSame(0, (int) $stmt->fetchColumn(), 'the pattern must be gone after delete');
        } finally {
            $pdo->prepare('DELETE FROM url_blacklist WHERE pattern = ?')->execute([$pattern]);
        }
    }

    /**
     * delete-message on a message id that does not exist must return 404
     * "Message not found". This pins the converted soft-delete update: when
     * getAffectedRows() is 0 the controller reports not-found (no row is mutated).
     */
    public function testDeleteMessageUnknownIdReturns404(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['message_id' => 'no-such-message-' . bin2hex(random_bytes(4))];

        $response = (new AdminModerationController())->deleteMessage();

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Message not found', json_decode($response->getBody(), true)['error']);
    }
}
