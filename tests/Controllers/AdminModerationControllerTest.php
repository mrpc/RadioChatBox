<?php

namespace RadioChatBox\Tests\Controllers;

use Pramnos\Framework\Testing\TestDatabase;

use PHPUnit\Framework\TestCase;
use Pramnos\Http\Request;
use Pramnos\Http\Response;
use RadioChatBox\Controllers\AdminModerationController;
use Pramnos\Database\Database;
use RadioChatBox\Middleware\AdminAuthMiddleware;
use RadioChatBox\Services\ChatService;

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
    /** @var array{ips:string[],nicks:string[],suffixes:string[]} data to clean up */
    private array $cleanup = ['ips' => [], 'nicks' => [], 'suffixes' => []];

    protected function tearDown(): void
    {
        $_POST = [];
        $_GET  = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $chat = new ChatService();
        foreach ($this->cleanup['ips'] as $ip) {
            $chat->unbanIP($ip);
        }
        foreach ($this->cleanup['nicks'] as $n) {
            $chat->unbanNickname($n);
        }
        if ($this->cleanup['suffixes'] !== []) {
            $pdo = TestDatabase::connection();
            foreach ($this->cleanup['suffixes'] as $s) {
                $like = '%' . $s . '%';
                $pdo->prepare('DELETE FROM messages WHERE message LIKE ? OR username LIKE ?')->execute([$like, $like]);
                $pdo->prepare('DELETE FROM sessions WHERE username LIKE ?')->execute([$like]);
                $pdo->prepare('DELETE FROM user_activity WHERE username LIKE ?')->execute([$like]);
            }
        }
        $this->cleanup = ['ips' => [], 'nicks' => [], 'suffixes' => []];
    }

    private function json(Response $r): array
    {
        return json_decode($r->getBody(), true) ?: [];
    }

    /**
     * ban-ip full cycle: POST bans an address (200 success), GET lists it, DELETE
     * unbans it (200 success) — exercises all three converted method branches.
     */
    public function testBanIpPostListDelete(): void
    {
        $ip = '203.0.113.' . random_int(2, 250);
        $this->cleanup['ips'][] = $ip;

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['ip_address' => $ip, 'reason' => 'moderation test'];
        $post  = (new AdminModerationController())->banIp();
        $this->assertSame(200, $post->getStatusCode());
        $this->assertTrue($this->json($post)['success']);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_POST = [];
        $list  = (new AdminModerationController())->banIp();
        $this->assertSame(200, $list->getStatusCode());
        $this->assertContains($ip, array_column($this->json($list)['banned_ips'], 'ip_address'));

        $_SERVER['REQUEST_METHOD'] = 'DELETE';
        $_POST = ['ip_address' => $ip];
        $del   = (new AdminModerationController())->banIp();
        $this->assertSame(200, $del->getStatusCode());
        $this->assertTrue($this->json($del)['success']);
    }

    /**
     * ban-nickname full cycle: POST bans a nickname (200), GET lists it, DELETE
     * unbans it (200).
     */
    public function testBanNicknamePostListDelete(): void
    {
        $nick = 'modnick' . substr(bin2hex(random_bytes(4)), 0, 8);
        $this->cleanup['nicks'][] = $nick;

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['nickname' => $nick, 'reason' => 'moderation test'];
        $post  = (new AdminModerationController())->banNickname();
        $this->assertSame(200, $post->getStatusCode());
        $this->assertTrue($this->json($post)['success']);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_POST = [];
        $list  = (new AdminModerationController())->banNickname();
        $this->assertSame(200, $list->getStatusCode());
        $this->assertContains(
            strtolower($nick),
            array_map('strtolower', array_column($this->json($list)['banned_nicknames'], 'nickname'))
        );

        $_SERVER['REQUEST_METHOD'] = 'DELETE';
        $_POST = ['nickname' => $nick];
        $del   = (new AdminModerationController())->banNickname();
        $this->assertSame(200, $del->getStatusCode());
        $this->assertTrue($this->json($del)['success']);
    }

    /**
     * kick-user happy path: register a real session, then kick it — the session
     * row is deleted and the response reports success (covers the KickRegistry +
     * broadcast + delete branch).
     */
    public function testKickUserRemovesSession(): void
    {
        $suffix = substr(bin2hex(random_bytes(4)), 0, 8);
        $this->cleanup['suffixes'][] = $suffix;
        $user = 'kick' . $suffix;
        $chat = new ChatService();
        $chat->registerUser($user, 'sess' . $suffix, '203.0.113.9');

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['username' => $user];
        $resp  = (new AdminModerationController())->kickUser();

        $this->assertSame(200, $resp->getStatusCode());
        $this->assertTrue($this->json($resp)['success']);

        $pdo  = TestDatabase::connection();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM sessions WHERE username = ?');
        $stmt->execute([$user]);
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'the kicked session row must be gone');
    }

    /**
     * delete-message happy path: post a real message, soft-delete it by id (200
     * success), then confirm the row is flagged is_deleted (covers the update /
     * tombstone / broadcast branch).
     */
    public function testDeleteMessageSoftDeletesRow(): void
    {
        $suffix = substr(bin2hex(random_bytes(4)), 0, 8);
        $this->cleanup['suffixes'][] = $suffix;
        $user = 'del' . $suffix;
        $chat = new ChatService();
        $chat->registerUser($user, 'sess' . $suffix, '203.0.113.10');
        $posted = $chat->postMessage($user, 'to delete ' . $suffix, '203.0.113.10', 'sess' . $suffix);
        $messageId = $posted['id'];

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['message_id' => $messageId];
        $resp  = (new AdminModerationController())->deleteMessage();

        $this->assertSame(200, $resp->getStatusCode());
        $this->assertTrue($this->json($resp)['success']);

        $pdo  = TestDatabase::connection();
        $stmt = $pdo->prepare('SELECT is_deleted FROM messages WHERE message_id = ?');
        $stmt->execute([$messageId]);
        $this->assertTrue((bool) $stmt->fetchColumn(), 'the message must be soft-deleted');
    }

    /**
     * clear-chat soft-deletes every live message and reports the count. Safe to
     * exercise now that the suite runs on an isolated database: seed two messages,
     * clear, and confirm they are flagged is_deleted.
     */
    public function testClearChatSoftDeletesMessages(): void
    {
        $suffix = substr(bin2hex(random_bytes(4)), 0, 8);
        $this->cleanup['suffixes'][] = $suffix;
        $user = 'clr' . $suffix;
        $chat = new ChatService();
        $chat->registerUser($user, 'sess' . $suffix, '203.0.113.11');
        $m1 = $chat->postMessage($user, 'one ' . $suffix, '203.0.113.11', 'sess' . $suffix);
        $m2 = $chat->postMessage($user, 'two ' . $suffix, '203.0.113.11', 'sess' . $suffix);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $response = (new AdminModerationController())->clearChat();

        $this->assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        $this->assertTrue($body['success']);
        $this->assertGreaterThanOrEqual(2, $body['deleted_count']);

        $pdo  = TestDatabase::connection();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE message_id IN (?, ?) AND is_deleted = TRUE');
        $stmt->execute([$m1['id'], $m2['id']]);
        $this->assertSame(2, (int) $stmt->fetchColumn(), 'both seeded messages must be soft-deleted');
    }

    /**
     * url-whitelist mirrors the blacklist path (query/insert/delete + duplicate
     * classification): insert a unique pattern (200), re-insert (400 duplicate),
     * GET lists it, DELETE by id (200). All rows cleaned up.
     */
    public function testUrlWhitelistInsertDuplicateListAndDelete(): void
    {
        $pattern = 'wl-' . bin2hex(random_bytes(5)) . '.example';
        $pdo     = TestDatabase::connection();

        try {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = ['pattern' => $pattern, 'description' => 'whitelist test'];
            $created = (new AdminModerationController())->urlWhitelist();
            $this->assertSame(200, $created->getStatusCode());
            $this->assertTrue($this->json($created)['success']);

            $_POST = ['pattern' => $pattern, 'description' => 'dupe'];
            $dupe  = (new AdminModerationController())->urlWhitelist();
            $this->assertSame(400, $dupe->getStatusCode());
            $this->assertSame('Pattern already exists', $this->json($dupe)['error']);

            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_POST = [];
            $list  = (new AdminModerationController())->urlWhitelist();
            $this->assertSame(200, $list->getStatusCode());
            $this->assertContains($pattern, array_column($this->json($list)['patterns'], 'pattern'));

            $stmt = $pdo->prepare('SELECT id FROM url_whitelist WHERE pattern = ?');
            $stmt->execute([$pattern]);
            $id = (int) $stmt->fetchColumn();

            $_SERVER['REQUEST_METHOD'] = 'DELETE';
            $_GET = ['id' => $id];
            $deleted = (new AdminModerationController())->urlWhitelist();
            $this->assertSame(200, $deleted->getStatusCode());
            $this->assertTrue($this->json($deleted)['success']);
        } finally {
            $pdo->prepare('DELETE FROM url_whitelist WHERE pattern = ?')->execute([$pattern]);
        }
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
        $pdo     = TestDatabase::connection();

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
