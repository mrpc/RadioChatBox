<?php

namespace RadioChatBox\Tests\Controllers;

use Pramnos\Framework\Testing\TestDatabase;

use PHPUnit\Framework\TestCase;
use Pramnos\Http\Response;
use Pramnos\Cache\FlatCache;
use RadioChatBox\Services\ChatService;
use RadioChatBox\Controllers\MessageActionController;
use Pramnos\Database\Database;
use RadioChatBox\Services\ReactionService;

/**
 * Golden-contract tests for the migrated MessageAction endpoints (replaced
 * public/api/{react,edit-message,block,private-message}.php).
 *
 * The mutating POST paths (react toggle, edit, block, DM send) require a valid
 * owned session and write real data, so only their deterministic validation
 * guards (400/empty-body/missing-field) are asserted here — never the
 * destructive success path. The non-mutating GET reads (allowed emojis, blocked
 * list) assert the full success shape since they are side-effect free.
 */
class MessageActionControllerTest extends TestCase
{
    private ?string $sessionKey = null;

    protected function tearDown(): void
    {
        if ($this->sessionKey !== null) {
            try {
                FlatCache::default()->delete($this->sessionKey);
            } catch (\Throwable) {
            }
            $this->sessionKey = null;
        }
        $_POST = [];
        $_GET = [];
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    }

    /** Fake an administrator session for the admin-only private-message read. */
    private function authAsAdmin(string $id, string $role = 'administrator'): void
    {
        try {
            $key = 'admin_session:' . $id;
            FlatCache::default()->set($key, ['username' => $id, 'role' => $role], 120);
            $this->sessionKey = $key;
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $id . ':x';
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis unavailable: ' . $e->getMessage());
        }
    }

    /**
     * react POST with an empty body reproduces the legacy "Invalid JSON" 400.
     */
    public function testReactEmptyBodyReturns400(): void
    {
        $_POST = [];
        $response = (new MessageActionController())->react();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Invalid JSON', json_decode($response->getBody(), true)['error']);
    }

    /**
     * react POST missing the required identifiers returns the exact 400 guard
     * message the legacy endpoint used.
     */
    public function testReactMissingFieldsReturns400(): void
    {
        $_POST = ['message_id' => 'm1']; // username + session_id absent
        $response = (new MessageActionController())->react();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(
            'message_id, username and session_id are required',
            json_decode($response->getBody(), true)['error']
        );
    }

    /**
     * react GET returns {success:true, allowed:[...]} — the reaction picker set,
     * a side-effect free read matching ReactionService::getAllowedEmojis().
     */
    public function testReactAllowedReturnsEmojiSet(): void
    {
        $response = (new MessageActionController())->reactAllowed();

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertIsArray($body['allowed']);
        $this->assertSame(ReactionService::getAllowedEmojis(), $body['allowed']);
    }

    /** react/who without a message_id is a 400. */
    public function testReactWhoMissingIdReturns400(): void
    {
        $_GET = [];
        $response = (new MessageActionController())->reactWho();
        $this->assertSame(400, $response->getStatusCode());
    }

    /** react/who returns the grouped-by-emoji reaction roster for a message. */
    public function testReactWhoReturnsRoster(): void
    {
        $pdo = TestDatabase::connection();
        $messageId = 'msg_who_' . bin2hex(random_bytes(6));
        $pdo->prepare(
            'INSERT INTO chat_messages (message_id, username, message, ip_address, created_at)
             VALUES (?, ?, ?, ?, NOW())'
        )->execute([$messageId, '__who_author__', 'hi', '127.0.0.1']);
        (new ReactionService())->toggleReaction($messageId, '__who_a__', 'sess', '👍');

        try {
            $_GET = ['message_id' => $messageId];
            $response = (new MessageActionController())->reactWho();
            $this->assertSame(200, $response->getStatusCode());
            $body = json_decode($response->getBody(), true);
            $this->assertTrue($body['success']);
            $this->assertSame('👍', $body['reactions'][0]['emoji']);
            $this->assertContains('__who_a__', $body['reactions'][0]['users']);
        } finally {
            $pdo->prepare('DELETE FROM message_reactions WHERE message_id = ?')->execute([$messageId]);
            $pdo->prepare('DELETE FROM chat_messages WHERE message_id = ?')->execute([$messageId]);
        }
    }

    /**
     * edit-message POST missing required fields returns the legacy 400 message
     * (no InvalidArgumentException — a direct validation return).
     */
    public function testEditMessageMissingFieldsReturns400(): void
    {
        $_POST = ['message_id' => 'm1']; // message/username/sessionId absent
        $response = (new MessageActionController())->editMessage();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(
            'message_id, message, username and sessionId are required',
            json_decode($response->getBody(), true)['error']
        );
    }

    /**
     * edit-message POST rejects a body over the 500-character limit with the
     * exact legacy 400 message, before any DB access.
     */
    public function testEditMessageTooLongReturns400(): void
    {
        $_POST = [
            'message_id' => 'm1',
            'message'    => str_repeat('a', 501),
            'username'   => 'u',
            'sessionId'  => 's',
        ];
        $response = (new MessageActionController())->editMessage();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(
            'Message too long (max 500 characters)',
            json_decode($response->getBody(), true)['error']
        );
    }

    /**
     * block POST with an empty body reproduces the legacy "Invalid JSON" 400.
     */
    public function testBlockEmptyBodyReturns400(): void
    {
        $_POST = [];
        $response = (new MessageActionController())->block();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Invalid JSON', json_decode($response->getBody(), true)['error']);
    }

    /**
     * block POST missing required fields returns the exact legacy 400 guard.
     */
    public function testBlockMissingFieldsReturns400(): void
    {
        $_POST = ['action' => 'block', 'username' => 'u']; // session_id + target absent
        $response = (new MessageActionController())->block();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(
            'username, session_id and target_username are required',
            json_decode($response->getBody(), true)['error']
        );
    }

    /**
     * block GET without a username returns the legacy 400.
     */
    public function testBlockListRequiresUsername(): void
    {
        $_GET = [];
        $response = (new MessageActionController())->blockList();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('username is required', json_decode($response->getBody(), true)['error']);
    }

    /**
     * block GET for an arbitrary username returns {success, blocked_users:[]} —
     * a side-effect free read; an unknown user simply has an empty block list.
     */
    public function testBlockListReturnsBlockedUsersShape(): void
    {
        $_GET = ['username' => 'probe_' . substr(md5(__METHOD__), 0, 8)];
        $response = (new MessageActionController())->blockList();

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertIsArray($body['blocked_users']);
        $this->assertArrayNotHasKey('with_user', $body);
    }

    /**
     * private-message POST with an empty body reproduces the "Invalid JSON" 400.
     */
    public function testPrivateMessageEmptyBodyReturns400(): void
    {
        $_POST = [];
        $response = (new MessageActionController())->privateMessage();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Invalid JSON', json_decode($response->getBody(), true)['error']);
    }

    /**
     * private-message POST missing the from/to/session fields returns the exact
     * legacy 400 message.
     */
    public function testPrivateMessageMissingFieldsReturns400(): void
    {
        $_POST = ['from_username' => 'a']; // to_username + from_session_id absent
        $response = (new MessageActionController())->privateMessage();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(
            'From username, to username, and session ID are required',
            json_decode($response->getBody(), true)['error']
        );
    }

    /**
     * private-message POST with all identifiers but neither message nor
     * attachment returns the legacy "Either message or attachment" 400.
     */
    public function testPrivateMessageRequiresMessageOrAttachment(): void
    {
        $_POST = [
            'from_username'   => 'a',
            'to_username'     => 'b',
            'from_session_id' => 's',
        ];
        $response = (new MessageActionController())->privateMessage();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(
            'Either message or attachment is required',
            json_decode($response->getBody(), true)['error']
        );
    }

    /**
     * private-message GET without username/session returns the legacy 400.
     */
    public function testPrivateMessageListRequiresUsernameAndSession(): void
    {
        $_GET = ['username' => 'someone']; // session_id absent
        $response = (new MessageActionController())->privateMessageList();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(
            'Username and session ID are required',
            json_decode($response->getBody(), true)['error']
        );
    }

    /**
     * private-message GET admin=true reads ALL messages between two users,
     * bypassing session isolation. Without an authenticated admin it must be
     * refused with 401 Unauthorized BEFORE any query runs — the IDOR gate that
     * closes anyone reading any private conversation via ?admin=true.
     */
    public function testPrivateMessageListAdminModeRequiresAuth(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        $_GET = [
            'username'   => 'someone',
            'session_id' => 'sess',
            'with_user'  => 'victim',
            'admin'      => 'true',
        ];
        $response = (new MessageActionController())->privateMessageList();

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Unauthorized', json_decode($response->getBody(), true)['error']);
    }

    /**
     * A banned nickname cannot send a DM to anyone: private-message POST enforces
     * the same communication block as the public chat send path, returning 403
     * with the ban reason before the message is filtered, stored or published.
     */
    public function testPrivateMessageSendRejectsBannedNickname(): void
    {
        $chat = new ChatService();
        $nick = 'dmban_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $chat->banNickname($nick, 'test', 'admin');

        try {
            $_POST = [
                'from_username'   => $nick,
                'to_username'     => 'anybody',
                'from_session_id' => 'sess',
                'message'         => 'hello?',
            ];
            $response = (new MessageActionController())->privateMessage();

            $this->assertSame(403, $response->getStatusCode());
            $this->assertSame(
                'This nickname is not allowed.',
                json_decode($response->getBody(), true)['error']
            );
        } finally {
            $chat->unbanNickname($nick);
        }
    }

    // ---------------------------------------------------------------------
    // DB-path coverage for the Phase-7 conversion (getPDO -> getDb). edit-message
    // carries the riskiest converted SQL (dollar-quoted $$TIMEZONE$$/$$UTC$$ and
    // timezone math), and the conversation list runs the converted preparedQuery
    // reads; both are pinned here with seeded rows that are cleaned up.
    // ---------------------------------------------------------------------

    /**
     * edit-message: an owned, recent message is updated in place. This drives the
     * converted ownership/timing SELECT (with its $$-quoted timezone literals) and
     * the edited_at = NOW() UPDATE, returning the {success, message, ...} shape
     * with the filtered new text. All seeded rows are cleaned up.
     */
    public function testEditMessageUpdatesAnOwnedRecentMessage(): void
    {
        $pdo       = TestDatabase::connection();
        $username  = 'editor_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $sessionId = 'sess_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $messageId = 'msg_' . bin2hex(random_bytes(6));

        $pdo->prepare(
            'INSERT INTO presence_sessions (username, session_id, ip_address, last_heartbeat, joined_at)
             VALUES (:u, :s, :ip, NOW(), NOW())'
        )->execute(['u' => $username, 's' => $sessionId, 'ip' => '127.0.0.1']);
        $pdo->prepare(
            'INSERT INTO chat_messages (message_id, username, message, ip_address, created_at)
             VALUES (:mid, :u, :msg, :ip, NOW())'
        )->execute(['mid' => $messageId, 'u' => $username, 'msg' => 'original text', 'ip' => '127.0.0.1']);

        try {
            $_POST = [
                'message_id' => $messageId,
                'message'    => 'edited text',
                'username'   => $username,
                'sessionId'  => $sessionId,
            ];
            $response = (new MessageActionController())->editMessage();

            $this->assertSame(200, $response->getStatusCode());
            $body = json_decode($response->getBody(), true);
            $this->assertTrue($body['success']);
            $this->assertSame('edited text', $body['message']);

            $stmt = $pdo->prepare('SELECT message, edited_at FROM chat_messages WHERE message_id = ?');
            $stmt->execute([$messageId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            $this->assertSame('edited text', $row['message'], 'the row must be updated');
            $this->assertNotNull($row['edited_at'], 'edited_at must be stamped');
        } finally {
            $pdo->prepare('DELETE FROM chat_messages WHERE message_id = ?')->execute([$messageId]);
            $pdo->prepare('DELETE FROM presence_sessions WHERE username = ?')->execute([$username]);
        }
    }

    /**
     * edit-message: a message the caller does not own (no matching session) must
     * return 403 "Message not found or you do not own it" — the converted
     * ownership SELECT returning no row. No seeding required.
     */
    public function testEditMessageForeignMessageReturns403(): void
    {
        $_POST = [
            'message_id' => 'msg_' . bin2hex(random_bytes(6)),
            'message'    => 'hijack',
            'username'   => 'ghost_' . substr(bin2hex(random_bytes(4)), 0, 8),
            'sessionId'  => 'sess_' . substr(bin2hex(random_bytes(4)), 0, 8),
        ];
        $response = (new MessageActionController())->editMessage();

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(
            'Message not found or you do not own it',
            json_decode($response->getBody(), true)['error']
        );
    }

    /**
     * private-message GET (real-user branch): the converted conversation query
     * fetches the recent window and reverses it to chronological order. Seed two
     * DMs in the same session and assert they come back oldest-first with the
     * newest last. Exercises the fake-user exists() check (false) plus the
     * session-scoped preparedQuery read.
     */
    public function testPrivateMessageListReturnsConversationChronologically(): void
    {
        $pdo       = TestDatabase::connection();
        $me        = 'me_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $peer      = 'peer_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $sessionId = 'sess_' . substr(bin2hex(random_bytes(4)), 0, 8);

        // Two messages in this session: an older one and a newer one.
        $pdo->prepare(
            "INSERT INTO private_messages (from_username, from_session_id, to_username, to_session_id, message, created_at)
             VALUES (:me, :s, :peer, :ps, 'older', NOW() - INTERVAL '2 minutes'),
                    (:peer, :ps, :me, :s, 'newer', NOW())"
        )->execute(['me' => $me, 's' => $sessionId, 'peer' => $peer, 'ps' => 'peer_sess']);

        try {
            $_GET = ['username' => $me, 'session_id' => $sessionId, 'with_user' => $peer];
            $response = (new MessageActionController())->privateMessageList();

            $this->assertSame(200, $response->getStatusCode());
            $body     = json_decode($response->getBody(), true);
            $this->assertTrue($body['success']);
            $bodies   = array_column($body['messages'], 'message');
            $this->assertSame(['older', 'newer'], $bodies, 'messages must be oldest-first');
        } finally {
            $pdo->prepare('DELETE FROM private_messages WHERE from_username = :me OR to_username = :me')
                ->execute(['me' => $me]);
        }
    }

    /** Seed a live session row so getSessionInfo() accepts the caller. */
    private function seedSession(\PDO $pdo, string $username, string $sessionId): void
    {
        $pdo->prepare(
            'INSERT INTO presence_sessions (username, session_id, ip_address, last_heartbeat, joined_at)
             VALUES (:u, :s, :ip, NOW(), NOW())'
        )->execute(['u' => $username, 's' => $sessionId, 'ip' => '127.0.0.1']);
    }

    /**
     * react POST happy path: an owned session toggles a reaction on a real
     * message, returning {success:true, ...} — covers the session-ownership check
     * plus ReactionService::toggleReaction. Seeded rows are cleaned up.
     */
    public function testReactTogglesReactionForOwnedSession(): void
    {
        $pdo       = TestDatabase::connection();
        $username  = 'react_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $sessionId = 'sess_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $messageId = 'msg_' . bin2hex(random_bytes(6));
        $emoji     = ReactionService::getAllowedEmojis()[0];

        $this->seedSession($pdo, $username, $sessionId);
        $pdo->prepare(
            'INSERT INTO chat_messages (message_id, username, message, ip_address, created_at)
             VALUES (:mid, :u, :msg, :ip, NOW())'
        )->execute(['mid' => $messageId, 'u' => $username, 'msg' => 'react to me', 'ip' => '127.0.0.1']);

        try {
            $_POST = [
                'message_id' => $messageId,
                'username'   => $username,
                'session_id' => $sessionId,
                'emoji'      => $emoji,
            ];
            $response = (new MessageActionController())->react();

            $this->assertSame(200, $response->getStatusCode());
            $this->assertTrue(json_decode($response->getBody(), true)['success']);
        } finally {
            $pdo->prepare('DELETE FROM message_reactions WHERE message_id = ?')->execute([$messageId]);
            $pdo->prepare('DELETE FROM chat_messages WHERE message_id = ?')->execute([$messageId]);
            $pdo->prepare('DELETE FROM presence_sessions WHERE username = ?')->execute([$username]);
        }
    }

    /**
     * block POST happy path: an owned session blocks then unblocks a target,
     * returning {success:true, blocked:true|false} — covers both action branches
     * and the session-ownership check.
     */
    public function testBlockAndUnblockForOwnedSession(): void
    {
        $pdo       = TestDatabase::connection();
        $username  = 'blk_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $sessionId = 'sess_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $target    = 'tgt_' . substr(bin2hex(random_bytes(4)), 0, 8);

        $this->seedSession($pdo, $username, $sessionId);

        try {
            $_POST = [
                'action'          => 'block',
                'username'        => $username,
                'session_id'      => $sessionId,
                'target_username' => $target,
            ];
            $blocked = (new MessageActionController())->block();
            $this->assertSame(200, $blocked->getStatusCode());
            $this->assertTrue(json_decode($blocked->getBody(), true)['blocked']);

            $_POST['action'] = 'unblock';
            $unblocked = (new MessageActionController())->block();
            $this->assertSame(200, $unblocked->getStatusCode());
            $this->assertFalse(json_decode($unblocked->getBody(), true)['blocked']);
        } finally {
            $pdo->prepare('DELETE FROM dm_blocks WHERE blocker_username = ?')->execute([$username]);
            $pdo->prepare('DELETE FROM presence_sessions WHERE username = ?')->execute([$username]);
        }
    }

    /**
     * private-message POST happy path: a real user DMs another live user; the
     * message is stored and the {success:true, data:{...}} envelope returned.
     * Drives the recipient-live-session branch, the display-name snapshot reads
     * and the INSERT ... RETURNING. All seeded rows are cleaned up.
     */
    public function testPrivateMessageSendStoresAndReturnsEnvelope(): void
    {
        $pdo      = TestDatabase::connection();
        $from     = 'pmf_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $to       = 'pmt_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $fromSess = 'sess_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $toSess   = 'sess_' . substr(bin2hex(random_bytes(4)), 0, 8);

        $this->seedSession($pdo, $from, $fromSess);
        $this->seedSession($pdo, $to, $toSess);

        try {
            $_POST = [
                'from_username'   => $from,
                'to_username'     => $to,
                'from_session_id' => $fromSess,
                'message'         => 'hi there ' . $from,
            ];
            $response = (new MessageActionController())->privateMessage();

            $this->assertSame(200, $response->getStatusCode());
            $body = json_decode($response->getBody(), true);
            $this->assertTrue($body['success']);
            $this->assertSame('Private message sent', $body['message']);
            $this->assertSame($to, $body['data']['to_username']);

            $stmt = $pdo->prepare('SELECT COUNT(*) FROM private_messages WHERE from_username = ? AND to_username = ?');
            $stmt->execute([$from, $to]);
            $this->assertSame(1, (int) $stmt->fetchColumn(), 'the DM must be stored');
        } finally {
            $pdo->prepare('DELETE FROM private_messages WHERE from_username = ?')->execute([$from]);
            $pdo->prepare('DELETE FROM presence_sessions WHERE username IN (?, ?)')->execute([$from, $to]);
        }
    }

    /**
     * edit-message past the 10-minute window returns 403 "Edit window has
     * expired" — drives the age_seconds > 600 guard with a message seeded 20
     * minutes in the past.
     */
    public function testEditMessageWindowExpiredReturns403(): void
    {
        $pdo       = TestDatabase::connection();
        $username  = 'old_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $sessionId = 'sess_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $messageId = 'msg_' . bin2hex(random_bytes(6));

        $this->seedSession($pdo, $username, $sessionId);
        // Well past the 10-minute window with generous margin for any server
        // timezone offset applied by the age calculation.
        $pdo->prepare(
            "INSERT INTO chat_messages (message_id, username, message, ip_address, created_at)
             VALUES (?, ?, 'old text', '127.0.0.1', NOW() - INTERVAL '6 hours')"
        )->execute([$messageId, $username]);

        try {
            $_POST = ['message_id' => $messageId, 'message' => 'too late', 'username' => $username, 'sessionId' => $sessionId];
            $response = (new MessageActionController())->editMessage();

            $this->assertSame(403, $response->getStatusCode());
            $this->assertSame(
                'Edit window has expired (10 minutes)',
                json_decode($response->getBody(), true)['error']
            );
        } finally {
            $pdo->prepare('DELETE FROM chat_messages WHERE message_id = ?')->execute([$messageId]);
            $pdo->prepare('DELETE FROM presence_sessions WHERE username = ?')->execute([$username]);
        }
    }

    /**
     * private-message GET (fake-user branch): a conversation with an active fake
     * user returns the full (session-agnostic) history, and an attachment row is
     * folded into an `attachment` object. Drives the fake_users exists() = true
     * path and the attachment-formatting block.
     */
    public function testPrivateMessageListFakeUserBranchWithAttachment(): void
    {
        $pdo    = TestDatabase::connection();
        $suffix = substr(bin2hex(random_bytes(4)), 0, 8);
        $me     = 'me_' . $suffix;
        $fake   = 'fk_' . $suffix;
        $attId  = 'att_' . $suffix;

        $pdo->prepare('INSERT INTO fake_users (nickname, is_active, bot_enabled) VALUES (?, TRUE, FALSE)')->execute([$fake]);
        $pdo->prepare(
            "INSERT INTO attachments (attachment_id, filename, original_filename, file_path, file_size, mime_type, uploaded_by, ip_address, is_deleted)
             VALUES (?, 'f.jpg', 'f.jpg', '/uploads/photos/f.jpg', 10, 'image/jpeg', ?, '127.0.0.1', FALSE)"
        )->execute([$attId, $me]);
        $pdo->prepare(
            "INSERT INTO private_messages (from_username, from_session_id, to_username, to_session_id, message, attachment_id, created_at)
             VALUES (?, 'sX', ?, ?, 'with photo', ?, NOW())"
        )->execute([$me, $fake, 'fake_' . md5($fake), $attId]);

        try {
            $_GET = ['username' => $me, 'session_id' => 'anything', 'with_user' => $fake];
            $response = (new MessageActionController())->privateMessageList();

            $this->assertSame(200, $response->getStatusCode());
            $body = json_decode($response->getBody(), true);
            $this->assertTrue($body['success']);
            $this->assertNotEmpty($body['messages']);
            $this->assertSame($attId, $body['messages'][0]['attachment']['attachment_id']);
        } finally {
            $pdo->prepare('DELETE FROM private_messages WHERE from_username = ?')->execute([$me]);
            $pdo->prepare('DELETE FROM attachments WHERE attachment_id = ?')->execute([$attId]);
            $pdo->prepare('DELETE FROM fake_users WHERE nickname = ?')->execute([$fake]);
        }
    }

    /**
     * private-message GET admin mode returns ALL messages between the pair,
     * bypassing session scoping — gated behind an authenticated administrator.
     */
    public function testPrivateMessageListAdminModeReturnsPair(): void
    {
        $pdo    = TestDatabase::connection();
        $suffix = substr(bin2hex(random_bytes(4)), 0, 8);
        $a      = 'a_' . $suffix;
        $b      = 'b_' . $suffix;

        // Admin mode calls AdminAuth::verify() — a full Bearer username:password
        // DB authentication — so a real administrator account is required.
        $admin = 'pmadmin_' . $suffix;
        $pass  = 'Str0ng-Admin-Pass!';
        $ip    = '203.0.113.' . random_int(2, 250);
        (new \RadioChatBox\Services\UserService())->createUser($admin, $pass, 'administrator');
        $_SERVER['REMOTE_ADDR']        = $ip;
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $admin . ':' . $pass;

        $pdo->prepare(
            "INSERT INTO private_messages (from_username, from_session_id, to_username, to_session_id, message, created_at)
             VALUES (?, 's1', ?, 's2', 'a to b', NOW() - INTERVAL '1 minute'),
                    (?, 's2', ?, 's1', 'b to a', NOW())"
        )->execute([$a, $b, $b, $a]);

        try {
            $_GET = ['username' => $a, 'session_id' => 'ignored', 'with_user' => $b, 'admin' => 'true'];
            $response = (new MessageActionController())->privateMessageList();

            $this->assertSame(200, $response->getStatusCode());
            $bodies = array_column(json_decode($response->getBody(), true)['messages'], 'message');
            $this->assertContains('a to b', $bodies);
            $this->assertContains('b to a', $bodies);
        } finally {
            $pdo->prepare('DELETE FROM private_messages WHERE from_username IN (?, ?)')->execute([$a, $b]);
            Database::getInstance()->queryBuilder()->from('users')->where('username', '=', $admin)->delete();
            try {
                FlatCache::default()->delete('admin_session:' . $admin);
                FlatCache::default()->delete('admin_auth_attempts:' . $ip);
            } catch (\Throwable) {
            }
        }
    }

    /**
     * private-message GET with no with_user returns the session's recent messages
     * (the else branch, kept in DESC order).
     */
    public function testPrivateMessageListRecentBranch(): void
    {
        $pdo    = TestDatabase::connection();
        $suffix = substr(bin2hex(random_bytes(4)), 0, 8);
        $me     = 'rc_' . $suffix;
        $sess   = 'sess_' . $suffix;

        $pdo->prepare(
            "INSERT INTO private_messages (from_username, from_session_id, to_username, to_session_id, message, created_at)
             VALUES (?, ?, 'peer', 'psess', 'recent one', NOW())"
        )->execute([$me, $sess]);

        try {
            $_GET = ['username' => $me, 'session_id' => $sess];
            $response = (new MessageActionController())->privateMessageList();

            $this->assertSame(200, $response->getStatusCode());
            $this->assertContains('recent one', array_column(json_decode($response->getBody(), true)['messages'], 'message'));
        } finally {
            $pdo->prepare('DELETE FROM private_messages WHERE from_username = ?')->execute([$me]);
        }
    }

    /**
     * private-message POST to an active fake user (bot disabled) stores the DM and
     * drives the is-fake-user branch: an admin DM notification is created and a
     * (no-op) bot reply is scheduled. Returns the success envelope.
     */
    public function testPrivateMessageToFakeUserSucceeds(): void
    {
        $pdo    = TestDatabase::connection();
        $suffix = substr(bin2hex(random_bytes(4)), 0, 8);
        $from   = 'sender_' . $suffix;
        $fromS  = 'sess_' . $suffix;
        $fake   = 'fake_' . $suffix;

        $this->seedSession($pdo, $from, $fromS);
        $pdo->prepare('INSERT INTO fake_users (nickname, is_active, bot_enabled) VALUES (?, TRUE, FALSE)')->execute([$fake]);

        try {
            $_POST = ['from_username' => $from, 'to_username' => $fake, 'from_session_id' => $fromS, 'message' => 'hey bot ' . $suffix];
            $response = (new MessageActionController())->privateMessage();

            $this->assertSame(200, $response->getStatusCode());
            $this->assertTrue(json_decode($response->getBody(), true)['success']);

            $stmt = $pdo->prepare('SELECT COUNT(*) FROM private_messages WHERE from_username = ? AND to_username = ?');
            $stmt->execute([$from, $fake]);
            $this->assertSame(1, (int) $stmt->fetchColumn());
        } finally {
            $pdo->prepare('DELETE FROM private_messages WHERE from_username = ? OR to_username = ?')->execute([$from, $fake]);
            $pdo->prepare('DELETE FROM presence_sessions WHERE username = ?')->execute([$from]);
            $pdo->prepare('DELETE FROM bot_threads WHERE fake_user_id IN (SELECT id FROM fake_users WHERE nickname = ?)')->execute([$fake]);
            $pdo->prepare('DELETE FROM fake_users WHERE nickname = ?')->execute([$fake]);
        }
    }
}
