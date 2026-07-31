<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use Pramnos\Framework\Testing\TestDatabase;
use RadioChatBox\Services\ChatService;

/**
 * Covers ChatService's moderation and admin-facing surface — IP/nickname bans,
 * the communication-block reasons, the admin message/user listings and the session
 * lifecycle — against the shared dev database. Everything created is tagged with a
 * per-run suffix and removed in tearDown.
 */
class ChatServiceModerationTest extends TestCase
{
    private ChatService $service;
    private string $suffix;
    private string $ip;
    private string $nick;
    private string $user;
    private string $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ChatService();
        $this->suffix  = substr(bin2hex(random_bytes(5)), 0, 10);
        $this->ip      = '203.0.113.' . random_int(2, 250);
        $this->nick    = 'ban' . $this->suffix;
        $this->user    = 'u' . $this->suffix;
        $this->session = 'sess' . $this->suffix;
    }

    protected function tearDown(): void
    {
        // Undo bans through the service (also exercises the unban paths).
        $this->service->unbanIP($this->ip);
        $this->service->unbanNickname($this->nick);

        $pdo  = TestDatabase::connection();
        $like = '%' . $this->suffix . '%';
        $pdo->prepare('DELETE FROM chat_messages WHERE message LIKE ? OR username LIKE ?')->execute([$like, $like]);
        $pdo->prepare('DELETE FROM presence_sessions WHERE username LIKE ?')->execute([$like]);
        $pdo->prepare('DELETE FROM user_activity WHERE username LIKE ?')->execute([$like]);
        parent::tearDown();
    }

    /**
     * banIP records the address (listed by getBannedIPs) and makes it a
     * communication block reason; unbanIP clears it.
     */
    public function testBanAndUnbanIp(): void
    {
        $this->assertTrue($this->service->banIP($this->ip, 'test ' . $this->suffix));
        $this->assertContains($this->ip, array_column($this->service->getBannedIPs(), 'ip_address'));

        $reason = $this->service->communicationBlockReason($this->user, $this->ip);
        $this->assertNotNull($reason, 'a banned IP must be a block reason');

        $this->assertTrue($this->service->unbanIP($this->ip));
        $this->assertNotContains($this->ip, array_column($this->service->getBannedIPs(), 'ip_address'));
    }

    /**
     * banNickname records the nickname (listed by getBannedNicknames) and makes it
     * unavailable; unbanNickname frees it again.
     */
    public function testBanAndUnbanNickname(): void
    {
        $cleanIp = '198.51.100.' . random_int(2, 250);

        $this->assertTrue($this->service->banNickname($this->nick, 'test ' . $this->suffix));
        $this->assertContains(
            strtolower($this->nick),
            array_map('strtolower', array_column($this->service->getBannedNicknames(), 'nickname'))
        );
        // The ban is enforced via the block-reason (nickname-not-allowed), not
        // isNicknameAvailable (which only guards fake users + active sessions).
        $this->assertNotNull(
            $this->service->communicationBlockReason($this->nick, $cleanIp),
            'a banned nickname must be a block reason'
        );

        $this->assertTrue($this->service->unbanNickname($this->nick));
        $this->assertNull($this->service->communicationBlockReason($this->nick, $cleanIp));
    }

    /**
     * The admin message listing and counter include a just-posted message.
     */
    public function testAdminMessageListingAndCount(): void
    {
        $this->service->registerUser($this->user, $this->session, $this->ip);
        $this->service->postMessage($this->user, 'hello ' . $this->suffix, $this->ip, $this->session);

        $messages = $this->service->getAllMessages(100, 0, false, 'all');
        $this->assertContains('hello ' . $this->suffix, array_column($messages, 'message'));
        $this->assertGreaterThanOrEqual(1, $this->service->getTotalMessagesCount(false, 'all'));
    }

    /**
     * The session lifecycle: register → active listings / session info / heartbeat
     * → logout, each reporting sensibly.
     */
    public function testActiveUserAndSessionLifecycle(): void
    {
        $this->assertTrue($this->service->registerUser($this->user, $this->session, $this->ip));

        $this->assertContains($this->user, array_column($this->service->getActiveUsers(), 'username'));
        $this->assertIsInt($this->service->getActiveUserCount());
        $this->assertIsInt($this->service->getTotalActiveUsersCount());
        $this->assertContains($this->user, array_column($this->service->getAllUsers(), 'username'));

        $this->assertIsArray($this->service->getSessionInfo($this->user, $this->session));
        $this->assertTrue($this->service->updateHeartbeat($this->user, $this->session));
        $this->assertTrue($this->service->logoutUser($this->session));
    }

    /**
     * Read helpers that back the admin/config surfaces: batched settings, the
     * offset history page, and the kicked-session check for an unknown session.
     */
    public function testSettingsHistoryAndKickChecks(): void
    {
        $this->assertIsArray($this->service->getSettings(['chat_mode']));
        $this->assertNotNull($this->service->getSetting('chat_mode', 'both'));
        $this->assertIsArray($this->service->getHistoryWithOffset(10, 0));
        $this->assertFalse($this->service->isSessionKicked('no-such-session-' . $this->suffix));
    }
}
