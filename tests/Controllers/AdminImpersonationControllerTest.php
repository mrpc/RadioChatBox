<?php

namespace RadioChatBox\Tests\Controllers;

use Pramnos\Framework\Testing\TestDatabase;

use PHPUnit\Framework\TestCase;
use Pramnos\Http\Request;
use Pramnos\Http\Response;
use RadioChatBox\Services\BlockService;
use RadioChatBox\Controllers\AdminImpersonationController;
use Pramnos\Cache\FlatCache;
use Pramnos\Database\Database;
use RadioChatBox\Middleware\AdminAuthMiddleware;

/**
 * Golden-contract tests for the migrated AdminImpersonation resource controller
 * (replaces public/api/admin/impersonate-{bot,block,send,conversations}.php).
 *
 * Two auth gates guard every action: AdminAuthMiddleware (401 for anyone not
 * authenticated) and an in-action root/owner check (403 for a logged-in admin
 * without those roles). The unauthenticated paths are exercised directly (they
 * never touch Redis, since AdminAuth::getCurrentUser() short-circuits on a
 * missing Bearer header). The safe, non-mutating validation/read paths are
 * covered by faking a root session in Redis; those tests self-skip when Redis
 * is unreachable so the file still runs in a DB-only environment. No mutating
 * path (send insert, block/unblock, bot take-over) is ever exercised.
 */
class AdminImpersonationControllerTest extends TestCase
{
    /** Bearer identifier used for the faked root session. */
    private const ROOT_ID = 'imptest_root';

    /** @var string|null Redis session key to clean up in tearDown. */
    private ?string $sessionKey = null;

    protected function tearDown(): void
    {
        if ($this->sessionKey !== null) {
            try {
                FlatCache::default()->delete($this->sessionKey);
            } catch (\Throwable $e) {
                // best effort cleanup
            }
            $this->sessionKey = null;
        }
        $_POST = [];
        $_GET  = [];
        unset(
            $_SERVER['REQUEST_METHOD'],
            $_SERVER['HTTP_AUTHORIZATION'],
            $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        );
    }

    /**
     * Establish a fake root admin session so AdminAuth::getCurrentUser() returns
     * a root user. Skips the calling test if Redis is not reachable.
     */
    private function authAsRoot(): void
    {
        try {
            $key = 'admin_session:' . self::ROOT_ID;
            FlatCache::default()->set($key, [
                'username' => self::ROOT_ID,
                'role'     => 'root',
            ], 120);
            $this->sessionKey = $key;
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::ROOT_ID . ':x';
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis unavailable: ' . $e->getMessage());
        }
    }

    private function unauthenticate(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    }

    private function json(Response $r): array
    {
        return json_decode($r->getBody(), true) ?: [];
    }

    /**
     * The route middleware must reject an unauthenticated request with a 401
     * ({"error":"Unauthorized"}) and never run the wrapped action.
     */
    public function testMiddlewareBlocksUnauthenticatedWith401(): void
    {
        $this->unauthenticate();

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
     * bot() enforces the extra root/owner gate: a request without a root/owner
     * session gets 403 before any bot state is touched.
     */
    public function testBotForbiddenWithoutRootRole(): void
    {
        $this->unauthenticate();
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $response = (new AdminImpersonationController())->bot();

        $this->assertSame(403, $response->getStatusCode());
        $this->assertArrayHasKey('error', json_decode($response->getBody(), true));
    }

    /**
     * block() returns 403 (with the exact legacy message "Forbidden") when the
     * caller is not a root/owner admin.
     */
    public function testBlockForbiddenWithoutRootRole(): void
    {
        $this->unauthenticate();
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $response = (new AdminImpersonationController())->block();

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Forbidden', json_decode($response->getBody(), true)['error']);
    }

    /**
     * send() (a mutating endpoint) refuses non-root callers with 403 before any
     * database write can occur.
     */
    public function testSendForbiddenWithoutRootRole(): void
    {
        $this->unauthenticate();
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $response = (new AdminImpersonationController())->send();

        $this->assertSame(403, $response->getStatusCode());
        $this->assertArrayHasKey('error', json_decode($response->getBody(), true));
    }

    /**
     * conversations() refuses non-root callers with 403.
     */
    public function testConversationsForbiddenWithoutRootRole(): void
    {
        $this->unauthenticate();
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $response = (new AdminImpersonationController())->conversations();

        $this->assertSame(403, $response->getStatusCode());
        $this->assertArrayHasKey('error', json_decode($response->getBody(), true));
    }

    /**
     * bot() GET with missing fake_user/peer returns 400 with the required-fields
     * message (safe: no bot state is read or written).
     */
    public function testBotGetMissingParamsReturns400(): void
    {
        $this->authAsRoot();
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = [];

        $response = (new AdminImpersonationController())->bot();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('fake_user and peer are required', json_decode($response->getBody(), true)['error']);
    }

    /**
     * bot() POST with an unrecognised action returns 400 and the "Unknown action"
     * message (safe: the match falls through to the default before any service
     * call, so no take/release/force/stop/block is performed). The message now
     * advertises the two force-stop actions added alongside the take-over flow.
     */
    public function testBotPostUnknownActionReturns400(): void
    {
        $this->authAsRoot();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['fake_user' => 'someone', 'peer' => 'peerx', 'action' => 'definitely_not_valid'];

        $response = (new AdminImpersonationController())->bot();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(
            'Unknown action (expected take, release, reset, force, stop, block or unblock)',
            json_decode($response->getBody(), true)['error']
        );
    }

    /**
     * bot() POST action=stop is a recognised action (routed past the match, unlike
     * an unknown action which 400s). With a fake_user that does not exist,
     * stopThread returns false before any write, so the controller answers 404
     * "Fake user not found" — proving the port wired 'stop' in without side effects.
     */
    public function testBotStopActionIsRoutedNotRejectedAsUnknown(): void
    {
        $this->authAsRoot();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'fake_user' => 'nonexistent_' . substr(bin2hex(random_bytes(4)), 0, 8),
            'peer'      => 'peerx',
            'action'    => 'stop',
        ];

        $response = (new AdminImpersonationController())->bot();

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Fake user not found', json_decode($response->getBody(), true)['error']);
    }

    /**
     * block() GET with missing params returns 400 (safe: read-only path never
     * reached).
     */
    public function testBlockGetMissingParamsReturns400(): void
    {
        $this->authAsRoot();
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = [];

        $response = (new AdminImpersonationController())->block();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('impersonate_as and to_username are required', json_decode($response->getBody(), true)['error']);
    }

    /**
     * block() POST with a bogus action (but valid usernames) returns 400 with the
     * action-validation message and performs neither a block nor an unblock.
     */
    public function testBlockPostInvalidActionReturns400(): void
    {
        $this->authAsRoot();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['impersonate_as' => 'fakeone', 'to_username' => 'realone', 'action' => 'nope'];

        $response = (new AdminImpersonationController())->block();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('action must be "block" or "unblock"', json_decode($response->getBody(), true)['error']);
    }

    /**
     * send() POST with no impersonate_as/to_username returns 400 before touching
     * the database (mutating path never reached).
     */
    public function testSendMissingFieldsReturns400(): void
    {
        $this->authAsRoot();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [];

        $response = (new AdminImpersonationController())->send();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Impersonate username and recipient are required', json_decode($response->getBody(), true)['error']);
    }

    /**
     * conversations() as root returns the {success, fake_users[], conversations{}}
     * read shape (read-only queries, safe to run against the dev DB).
     */
    public function testConversationsReturnsShapeForRoot(): void
    {
        $this->authAsRoot();
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $response = (new AdminImpersonationController())->conversations();

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertIsArray($body['fake_users']);
        $this->assertIsArray($body['conversations']);
    }

    /**
     * send() as root, impersonating a username that is not an active fake user,
     * must return 400 "Can only impersonate active fake users". This drives the
     * converted fake_users lookup (preparedQuery) and stops before any message is
     * stored, so it exercises the DB path without writing.
     */
    public function testSendRejectsNonFakeImpersonationTarget(): void
    {
        $this->authAsRoot();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'impersonate_as' => 'not_a_fake_' . substr(bin2hex(random_bytes(4)), 0, 8),
            'to_username'    => 'someone',
            'message'        => 'hi',
        ];

        $response = (new AdminImpersonationController())->send();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(
            'Can only impersonate active fake users',
            json_decode($response->getBody(), true)['error']
        );
    }

    /**
     * bot() POST action=block is the compound force-stop action: the fake user
     * blocks the peer (mutual DM block) AND the bot is stopped in that thread. With
     * a real fake user it succeeds, leaves a block in place, and marks the thread
     * ended — the port must wire both halves, not just recognise the action. All
     * seeded rows (fake user, block, thread) are cleaned up.
     */
    public function testBotBlockActionBlocksThePeerAndStopsTheThread(): void
    {
        $this->authAsRoot();
        $pdo    = TestDatabase::connection();
        $suffix = substr(bin2hex(random_bytes(4)), 0, 8);
        $fake   = 'blkfake_' . $suffix;
        $peer   = 'blkpeer_' . $suffix;

        $pdo->prepare('INSERT INTO fake_users (nickname, is_active, bot_enabled) VALUES (?, TRUE, TRUE)')
            ->execute([$fake]);

        try {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = ['fake_user' => $fake, 'peer' => $peer, 'action' => 'block'];

            $response = (new AdminImpersonationController())->bot();

            $this->assertSame(200, $response->getStatusCode());
            $body = json_decode($response->getBody(), true);
            $this->assertTrue($body['success']);
            $this->assertSame('block', $body['action']);

            // The peer is now blocked (mutual)…
            $this->assertTrue(
                (new BlockService())->isBlockedBetween($fake, $peer),
                'block action must leave a DM block in place'
            );
            // …and the bot is silenced in this conversation.
            $stmt = $pdo->prepare(
                'SELECT farewell_sent_at FROM bot_threads t
                 JOIN fake_users f ON f.id = t.fake_user_id
                 WHERE f.nickname = ? AND t.peer_username = ?'
            );
            $stmt->execute([$fake, $peer]);
            $this->assertNotNull($stmt->fetchColumn(), 'block action must also stop the thread');
        } finally {
            $pdo->prepare('DELETE FROM dm_blocks WHERE blocker_username = ? OR blocked_username = ?')
                ->execute([$fake, $fake]);
            $pdo->prepare('DELETE FROM bot_threads WHERE fake_user_id IN (SELECT id FROM fake_users WHERE nickname = ?)')
                ->execute([$fake]);
            $pdo->prepare('DELETE FROM fake_users WHERE nickname = ?')->execute([$fake]);
        }
    }

    /**
     * send() happy path: root impersonates an active fake user and DMs a live
     * recipient. The message is stored (INSERT ... RETURNING), the {success:true,
     * data:{...}} envelope is returned and the bot is taken over in that thread.
     * All seeded rows (fake user, recipient session, DM) are cleaned up.
     */
    public function testSendAsFakeUserStoresPrivateMessage(): void
    {
        $this->authAsRoot();
        $pdo    = TestDatabase::connection();
        $suffix = substr(bin2hex(random_bytes(4)), 0, 8);
        $fake   = 'sndfake_' . $suffix;
        $peer   = 'sndpeer_' . $suffix;
        $peerSess = 'sess_' . $suffix;

        $pdo->prepare('INSERT INTO fake_users (nickname, is_active, bot_enabled) VALUES (?, TRUE, FALSE)')
            ->execute([$fake]);
        $pdo->prepare(
            'INSERT INTO presence_sessions (username, session_id, ip_address, last_heartbeat, joined_at)
             VALUES (?, ?, ?, NOW(), NOW())'
        )->execute([$peer, $peerSess, '127.0.0.1']);

        try {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = ['impersonate_as' => $fake, 'to_username' => $peer, 'message' => 'ping ' . $suffix];

            $response = (new AdminImpersonationController())->send();

            $this->assertSame(200, $response->getStatusCode());
            $body = json_decode($response->getBody(), true);
            $this->assertTrue($body['success']);
            $this->assertSame($fake, $body['data']['from_username']);

            $stmt = $pdo->prepare('SELECT COUNT(*) FROM private_messages WHERE from_username = ? AND to_username = ?');
            $stmt->execute([$fake, $peer]);
            $this->assertSame(1, (int) $stmt->fetchColumn(), 'the impersonated DM must be stored');
        } finally {
            $pdo->prepare('DELETE FROM private_messages WHERE from_username = ?')->execute([$fake]);
            $pdo->prepare('DELETE FROM presence_sessions WHERE username = ?')->execute([$peer]);
            $pdo->prepare('DELETE FROM bot_threads WHERE fake_user_id IN (SELECT id FROM fake_users WHERE nickname = ?)')
                ->execute([$fake]);
            $pdo->prepare('DELETE FROM fake_users WHERE nickname = ?')->execute([$fake]);
        }
    }

    /**
     * bot() GET returns the thread state for an active fake user (404 for an
     * unknown one), and each POST management action (take/release/reset/force/stop)
     * returns 200. Covers the GET state read and the action match arms.
     */
    public function testBotGetStateAndPostActions(): void
    {
        $this->authAsRoot();
        $pdo    = TestDatabase::connection();
        $suffix = substr(bin2hex(random_bytes(4)), 0, 8);
        $fake   = 'botfake_' . $suffix;
        $peer   = 'botpeer_' . $suffix;
        $pdo->prepare('INSERT INTO fake_users (nickname, is_active, bot_enabled) VALUES (?, TRUE, TRUE)')->execute([$fake]);

        try {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_GET = ['fake_user' => $fake, 'peer' => $peer];
            $state = (new AdminImpersonationController())->bot();
            $this->assertSame(200, $state->getStatusCode());
            $this->assertArrayHasKey('state', $this->json($state));

            $_GET = ['fake_user' => 'ghost_' . $suffix, 'peer' => $peer];
            $this->assertSame(404, (new AdminImpersonationController())->bot()->getStatusCode());

            $_SERVER['REQUEST_METHOD'] = 'POST';
            foreach (['take', 'force', 'release', 'reset', 'stop'] as $action) {
                $_GET  = [];
                $_POST = ['fake_user' => $fake, 'peer' => $peer, 'action' => $action];
                $this->assertSame(200, (new AdminImpersonationController())->bot()->getStatusCode(), "action {$action} must be 200");
            }
        } finally {
            $pdo->prepare('DELETE FROM bot_threads WHERE fake_user_id IN (SELECT id FROM fake_users WHERE nickname = ?)')->execute([$fake]);
            $pdo->prepare('DELETE FROM dm_blocks WHERE blocker_username = ? OR blocked_username = ?')->execute([$fake, $fake]);
            $pdo->prepare('DELETE FROM fake_users WHERE nickname = ?')->execute([$fake]);
        }
    }

    /**
     * block() GET reports the block state, and POST block/unblock toggle a fake
     * user's mutual DM block against a peer.
     */
    public function testImpersonateBlockGetAndToggle(): void
    {
        $this->authAsRoot();
        $pdo    = TestDatabase::connection();
        $suffix = substr(bin2hex(random_bytes(4)), 0, 8);
        $fake   = 'blkf_' . $suffix;
        $peer   = 'blkp_' . $suffix;
        $pdo->prepare('INSERT INTO fake_users (nickname, is_active) VALUES (?, TRUE)')->execute([$fake]);

        try {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_GET = ['impersonate_as' => $fake, 'to_username' => $peer];
            $get = (new AdminImpersonationController())->block();
            $this->assertSame(200, $get->getStatusCode());
            $this->assertFalse($this->json($get)['i_blocked']);

            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_GET  = [];
            $_POST = ['action' => 'block', 'impersonate_as' => $fake, 'to_username' => $peer];
            $blocked = (new AdminImpersonationController())->block();
            $this->assertSame(200, $blocked->getStatusCode());
            $this->assertTrue($this->json($blocked)['blocked']);

            $_POST['action'] = 'unblock';
            $unblocked = (new AdminImpersonationController())->block();
            $this->assertSame(200, $unblocked->getStatusCode());
            $this->assertFalse($this->json($unblocked)['blocked']);
        } finally {
            $pdo->prepare('DELETE FROM dm_blocks WHERE blocker_username = ? OR blocked_username = ?')->execute([$fake, $fake]);
            $pdo->prepare('DELETE FROM fake_users WHERE nickname = ?')->execute([$fake]);
        }
    }

    /**
     * conversations() with a fake user that has inbound DMs populates its
     * per-fake-user rollup: the sender list (with online status from a live
     * session), the recent-messages slice and total count. Drives the inner
     * loop that stays empty when no fake user has conversations.
     */
    public function testConversationsPopulatesFakeUserRollup(): void
    {
        $this->authAsRoot();
        $pdo    = TestDatabase::connection();
        $suffix = substr(bin2hex(random_bytes(4)), 0, 8);
        $fake   = 'convfake_' . $suffix;
        $sender = 'convsend_' . $suffix;

        $pdo->prepare('INSERT INTO fake_users (nickname, is_active, bot_enabled) VALUES (?, TRUE, FALSE)')->execute([$fake]);
        $pdo->prepare(
            'INSERT INTO presence_sessions (username, session_id, ip_address, last_heartbeat, joined_at)
             VALUES (?, ?, ?, NOW(), NOW())'
        )->execute([$sender, 'sess_' . $suffix, '127.0.0.1']);
        $pdo->prepare(
            "INSERT INTO private_messages (from_username, from_session_id, to_username, to_session_id, message, created_at)
             VALUES (?, ?, ?, ?, 'hello fake', NOW())"
        )->execute([$sender, 'sess_' . $suffix, $fake, 'fake_' . md5($fake)]);

        try {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $response = (new AdminImpersonationController())->conversations();

            $this->assertSame(200, $response->getStatusCode());
            $body = json_decode($response->getBody(), true);
            $this->assertTrue($body['success']);
            $this->assertArrayHasKey($fake, $body['conversations']);
            $conv = $body['conversations'][$fake];
            $this->assertSame(1, $conv['total_messages']);
            $this->assertSame($sender, $conv['senders'][0]['username']);
            $this->assertTrue($conv['senders'][0]['is_online'], 'the sender has a live session');
        } finally {
            $pdo->prepare('DELETE FROM private_messages WHERE to_username = ?')->execute([$fake]);
            $pdo->prepare('DELETE FROM presence_sessions WHERE username = ?')->execute([$sender]);
            $pdo->prepare('DELETE FROM fake_users WHERE nickname = ?')->execute([$fake]);
        }
    }

    /** bot-ask refuses non-root callers with 403. */
    public function testBotAskForbiddenWithoutRootRole(): void
    {
        $this->unauthenticate();
        $_POST = ['fake_user' => 'bot', 'peer' => 'peer', 'question' => 'q?'];

        $this->assertSame(403, (new AdminImpersonationController())->botAsk()->getStatusCode());
    }

    /** bot-ask requires fake_user, peer and question. */
    public function testBotAskValidatesInput(): void
    {
        $this->authAsRoot();
        $_POST = ['fake_user' => 'bot', 'peer' => 'peer']; // no question

        $this->assertSame(400, (new AdminImpersonationController())->botAsk()->getStatusCode());
    }

    /** bot-ask on an unknown fake user is a 400 (InvalidArgument -> 400). */
    public function testBotAskUnknownFakeUserIs400(): void
    {
        $this->authAsRoot();
        $_POST = ['fake_user' => 'no_such_bot_' . bin2hex(random_bytes(3)), 'peer' => 'peer', 'question' => 'what?'];

        $this->assertSame(400, (new AdminImpersonationController())->botAsk()->getStatusCode());
    }

    /** bot-steer refuses non-root callers with 403. */
    public function testBotSteerForbiddenWithoutRootRole(): void
    {
        $this->unauthenticate();
        $_POST = ['fake_user' => 'bot', 'peer' => 'peer', 'directive' => 'x'];

        $this->assertSame(403, (new AdminImpersonationController())->botSteer()->getStatusCode());
    }

    /** bot-steer requires fake_user and peer. */
    public function testBotSteerValidatesInput(): void
    {
        $this->authAsRoot();
        $_POST = ['fake_user' => 'bot']; // no peer

        $this->assertSame(400, (new AdminImpersonationController())->botSteer()->getStatusCode());
    }

    /** bot-steer on an unknown fake user is a 400. */
    public function testBotSteerUnknownFakeUserIs400(): void
    {
        $this->authAsRoot();
        $_POST = ['fake_user' => 'no_such_bot_' . bin2hex(random_bytes(3)), 'peer' => 'peer', 'directive' => 'x'];

        $this->assertSame(400, (new AdminImpersonationController())->botSteer()->getStatusCode());
    }

    /** bot-rollback refuses non-root callers with 403. */
    public function testBotRollbackForbiddenWithoutRootRole(): void
    {
        $this->unauthenticate();
        $_POST = ['fake_user' => 'bot', 'peer' => 'peer', 'from_message_id' => '5'];

        $this->assertSame(403, (new AdminImpersonationController())->botRollback()->getStatusCode());
    }

    /** bot-rollback requires fake_user, peer and a positive from_message_id. */
    public function testBotRollbackValidatesInput(): void
    {
        $this->authAsRoot();
        $_POST = ['fake_user' => 'bot', 'peer' => 'peer', 'from_message_id' => '0'];

        $this->assertSame(400, (new AdminImpersonationController())->botRollback()->getStatusCode());
    }

    /** bot-rollback on an unknown fake user is a 400. */
    public function testBotRollbackUnknownFakeUserIs400(): void
    {
        $this->authAsRoot();
        $_POST = ['fake_user' => 'no_such_bot_' . bin2hex(random_bytes(3)), 'peer' => 'peer', 'from_message_id' => '5'];

        $this->assertSame(400, (new AdminImpersonationController())->botRollback()->getStatusCode());
    }

    /** bot-resend refuses non-root callers with 403. */
    public function testBotResendForbiddenWithoutRootRole(): void
    {
        $this->unauthenticate();
        $_POST = ['fake_user' => 'bot', 'peer' => 'peer', 'message' => 'x'];

        $this->assertSame(403, (new AdminImpersonationController())->botResend()->getStatusCode());
    }

    /** bot-resend requires fake_user, peer and a non-empty message. */
    public function testBotResendValidatesInput(): void
    {
        $this->authAsRoot();
        $_POST = ['fake_user' => 'bot', 'peer' => 'peer', 'message' => '   '];

        $this->assertSame(400, (new AdminImpersonationController())->botResend()->getStatusCode());
    }

    /** The impersonate typing cue refuses non-root callers with 403. */
    public function testTypingForbiddenWithoutRootRole(): void
    {
        $this->unauthenticate();
        $_POST = ['impersonate_as' => 'bot', 'to_username' => 'peer'];

        $this->assertSame(403, (new AdminImpersonationController())->typing()->getStatusCode());
    }

    /** Missing recipient -> 400 (after the root check passes). */
    public function testTypingValidatesInput(): void
    {
        $this->authAsRoot();
        $_POST = ['impersonate_as' => 'bot']; // no to_username

        $this->assertSame(400, (new AdminImpersonationController())->typing()->getStatusCode());
    }

    /** When typing indicators are on, a valid root request broadcasts (200, no skip). */
    public function testTypingBroadcastsWhenEnabled(): void
    {
        $this->authAsRoot();
        (new \RadioChatBox\Services\SettingsService())->set('dm_typing_indicators_enabled', 'true');

        try {
            $_POST = ['impersonate_as' => 'bot', 'to_username' => 'peer', 'is_typing' => 'true'];
            $response = (new AdminImpersonationController())->typing();

            $this->assertSame(200, $response->getStatusCode());
            $body = $this->json($response);
            $this->assertTrue($body['success']);
            $this->assertArrayNotHasKey('skipped', $body);
        } finally {
            \Pramnos\Framework\Testing\TestDatabase::connection()
                ->prepare("DELETE FROM settings WHERE setting = 'dm_typing_indicators_enabled'")->execute();
            FlatCache::default()->clear();
        }
    }
}
