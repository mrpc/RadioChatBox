<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use RadioChatBox\ChatService;
use RadioChatBox\BotService;
use RadioChatBox\Database;

/**
 * A banned (nickname or IP) or kicked user must not be able to communicate with
 * anyone — public chat, private messages, or bots — and must get an error.
 *
 * These regression tests pin the single source of truth
 * (ChatService::communicationBlockReason) enforced on every send path, the
 * public postMessage path, and the bot delivery guard (BotService::isPeerBanned),
 * so the moderation actions cannot silently stop being enforced.
 */
class CommunicationBanTest extends TestCase
{
    private ChatService $chat;
    private string $suffix;
    private string $nickname;
    private string $ip;
    private string $sessionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->chat = new ChatService();
        $this->suffix = substr(bin2hex(random_bytes(4)), 0, 8);
        $this->nickname = 'bantest_' . $this->suffix;
        $this->ip = '198.51.100.' . random_int(2, 254); // TEST-NET-2, never a real client
        $this->sessionId = 'sess_' . $this->suffix;
    }

    protected function tearDown(): void
    {
        // Undo anything a test set (idempotent).
        $this->chat->unbanNickname($this->nickname);
        $this->chat->unbanIP($this->ip);
        try {
            Database::getRedis()->del('banned_session:' . $this->sessionId);
        } catch (\Throwable) {
            // ignore
        }
        parent::tearDown();
    }

    /** A clean user is allowed to communicate — no block reason. */
    public function testCleanUserIsNotBlocked(): void
    {
        $this->assertNull(
            $this->chat->communicationBlockReason($this->nickname, $this->ip, $this->sessionId)
        );
    }

    /** A banned nickname is blocked with the nickname message. */
    public function testBannedNicknameIsBlocked(): void
    {
        $this->chat->banNickname($this->nickname, 'test', 'admin');

        $reason = $this->chat->communicationBlockReason($this->nickname, $this->ip, $this->sessionId);
        $this->assertSame('This nickname is not allowed.', $reason);
    }

    /** A banned IP is blocked with the IP message. */
    public function testBannedIpIsBlocked(): void
    {
        $this->chat->banIP($this->ip, 'test', 'admin');

        $reason = $this->chat->communicationBlockReason($this->nickname, $this->ip, $this->sessionId);
        $this->assertSame('Your IP address has been banned from the chat.', $reason);
    }

    /** A kicked session (Redis banned_session:<id>) is blocked with the kick message. */
    public function testKickedSessionIsBlocked(): void
    {
        Database::getRedis()->setex('banned_session:' . $this->sessionId, 3600, json_encode(['reason' => 'test']));

        $reason = $this->chat->communicationBlockReason($this->nickname, $this->ip, $this->sessionId);
        $this->assertSame('You have been kicked and cannot send messages right now.', $reason);
    }

    /** The public chat send path enforces the ban (postMessage throws). */
    public function testPostMessageRejectsBannedNickname(): void
    {
        $this->chat->banNickname($this->nickname, 'test', 'admin');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('This nickname is not allowed.');
        $this->chat->postMessage($this->nickname, 'hello', $this->ip, $this->sessionId);
    }

    /**
     * The bot delivery guard treats a banned peer as off-limits, so bots stop
     * messaging a user the moment an admin bans them (BotService::isPeerBanned).
     */
    public function testBotWillNotMessageBannedPeer(): void
    {
        $bot = new BotService();
        $method = new \ReflectionMethod($bot, 'isPeerBanned');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($bot, $this->nickname), 'not banned yet');

        $this->chat->banNickname($this->nickname, 'test', 'admin');
        $this->assertTrue($method->invoke($bot, $this->nickname), 'banned peer must be off-limits to bots');
    }
}
