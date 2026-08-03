<?php

namespace RadioChatBox\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Pramnos\Framework\Testing\TestDatabase;
use RadioChatBox\Services\UserProfileService;

/**
 * The public profile card: message count from the public history, online status
 * from a fresh presence heartbeat, and a role badge for staff. Works for both a
 * nickname-only guest and a registered/staff user.
 */
class UserProfileServiceTest extends TestCase
{
    private PDO $pdo;
    private string $user;
    /** @var list<string> */
    private array $messageIds = [];

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::connection();
        $this->user = 'prof_' . substr(bin2hex(random_bytes(5)), 0, 8);
        for ($i = 0; $i < 3; $i++) {
            $mid = 'pmsg_' . bin2hex(random_bytes(6));
            $this->pdo->prepare(
                'INSERT INTO chat_messages (message_id, username, message, ip_address, created_at)
                 VALUES (?, ?, ?, ?, NOW())'
            )->execute([$mid, $this->user, 'hello ' . $i, '127.0.0.1']);
            $this->messageIds[] = $mid;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->messageIds as $mid) {
            $this->pdo->prepare('DELETE FROM chat_messages WHERE message_id = ?')->execute([$mid]);
        }
        $this->pdo->prepare('DELETE FROM presence_sessions WHERE username = ?')->execute([$this->user]);
        $this->pdo->prepare('DELETE FROM users WHERE username = ?')->execute([$this->user]);
        $this->messageIds = [];
    }

    /** A guest gets a card with a message count, no badge, offline by default. */
    public function testGuestProfile(): void
    {
        $p = (new UserProfileService())->profile($this->user);
        $this->assertNotNull($p);
        $this->assertSame($this->user, $p['username']);
        $this->assertSame(3, $p['message_count']);
        $this->assertFalse($p['is_online'], 'no presence row → offline');
        $this->assertNull($p['badge']);
        $this->assertNotNull($p['first_seen']);
    }

    /** A fresh heartbeat marks the user online. */
    public function testOnlineFromFreshHeartbeat(): void
    {
        $this->pdo->prepare(
            'INSERT INTO presence_sessions (username, session_id, ip_address, last_heartbeat, joined_at)
             VALUES (?, ?, ?, NOW(), NOW())'
        )->execute([$this->user, 'psess_' . bin2hex(random_bytes(4)), '127.0.0.1']);

        $p = (new UserProfileService())->profile($this->user);
        $this->assertTrue($p['is_online']);
    }

    /** A moderator user gets the Moderator badge. */
    public function testStaffBadge(): void
    {
        $this->pdo->prepare('INSERT INTO users (username, password, usertype, display_name) VALUES (?, ?, 50, ?)')
            ->execute([$this->user, 'x', 'Cool Mod']);

        $p = (new UserProfileService())->profile($this->user);
        $this->assertSame('Moderator', $p['badge']);
        $this->assertSame('Cool Mod', $p['display_name']);
    }

    /** An empty username yields null. */
    public function testEmptyUsername(): void
    {
        $this->assertNull((new UserProfileService())->profile('  '));
    }

    /** A saved bio + custom status surface on the profile card. */
    public function testBioAndStatusSurface(): void
    {
        $this->pdo->prepare(
            'INSERT INTO user_profiles (username, session_id, bio, status_message, created_at)
             VALUES (?, ?, ?, ?, NOW())'
        )->execute([$this->user, 'psess_' . bin2hex(random_bytes(4)), 'Radio lover from Athens', 'Vibing 🎧']);

        try {
            $p = (new UserProfileService())->profile($this->user);
            $this->assertSame('Radio lover from Athens', $p['bio']);
            $this->assertSame('Vibing 🎧', $p['status']);
        } finally {
            $this->pdo->prepare('DELETE FROM user_profiles WHERE username = ?')->execute([$this->user]);
        }
    }
}
