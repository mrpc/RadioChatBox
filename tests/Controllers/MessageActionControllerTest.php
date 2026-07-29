<?php

namespace RadioChatBox\Tests\Controllers;

use Pramnos\Framework\Testing\TestDatabase;

use PHPUnit\Framework\TestCase;
use Pramnos\Http\Response;
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
    protected function tearDown(): void
    {
        $_POST = [];
        $_GET = [];
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
            'INSERT INTO sessions (username, session_id, ip_address, last_heartbeat, joined_at)
             VALUES (:u, :s, :ip, NOW(), NOW())'
        )->execute(['u' => $username, 's' => $sessionId, 'ip' => '127.0.0.1']);
        $pdo->prepare(
            'INSERT INTO messages (message_id, username, message, ip_address, created_at)
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

            $stmt = $pdo->prepare('SELECT message, edited_at FROM messages WHERE message_id = ?');
            $stmt->execute([$messageId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            $this->assertSame('edited text', $row['message'], 'the row must be updated');
            $this->assertNotNull($row['edited_at'], 'edited_at must be stamped');
        } finally {
            $pdo->prepare('DELETE FROM messages WHERE message_id = ?')->execute([$messageId]);
            $pdo->prepare('DELETE FROM sessions WHERE username = ?')->execute([$username]);
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
}
