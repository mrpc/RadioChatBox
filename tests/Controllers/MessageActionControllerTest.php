<?php

namespace RadioChatBox\Tests\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Http\Response;
use RadioChatBox\Controllers\MessageActionController;
use RadioChatBox\ReactionService;

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
}
