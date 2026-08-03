<?php

namespace RadioChatBox\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\FlatCache;
use Pramnos\Framework\Testing\TestDatabase;
use RadioChatBox\Services\SettingsService;
use RadioChatBox\Services\TrustService;

/**
 * Trust decision: registered users are always trusted; a guest becomes trusted
 * once their public message count reaches the threshold; below it they aren't.
 */
class TrustServiceTest extends TestCase
{
    private PDO $pdo;
    private string $user;
    /** @var list<string> */
    private array $messageIds = [];

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::connection();
        $this->user = 'trust_' . substr(bin2hex(random_bytes(5)), 0, 8);
        (new SettingsService())->set('trust_message_threshold', '3');
        (new SettingsService())->invalidateCache();
        FlatCache::default()->clear();
    }

    protected function tearDown(): void
    {
        foreach ($this->messageIds as $mid) {
            $this->pdo->prepare('DELETE FROM chat_messages WHERE message_id = ?')->execute([$mid]);
        }
        $this->pdo->prepare('DELETE FROM users WHERE username = ?')->execute([$this->user]);
        $this->pdo->prepare("DELETE FROM settings WHERE setting = 'trust_message_threshold'")->execute();
        (new SettingsService())->invalidateCache();
        FlatCache::default()->clear();
        $this->messageIds = [];
    }

    private function postMessages(int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $mid = 'tmsg_' . bin2hex(random_bytes(6));
            $this->pdo->prepare(
                'INSERT INTO chat_messages (message_id, username, message, ip_address, created_at)
                 VALUES (?, ?, ?, ?, NOW())'
            )->execute([$mid, $this->user, 'hi ' . $i, '127.0.0.1']);
            $this->messageIds[] = $mid;
        }
    }

    /** A guest below the threshold is not trusted. */
    public function testNewGuestNotTrusted(): void
    {
        $this->postMessages(2); // threshold is 3
        $this->assertFalse((new TrustService())->isTrusted($this->user));
    }

    /** A guest at/above the threshold is trusted. */
    public function testEstablishedGuestTrusted(): void
    {
        $this->postMessages(3);
        $this->assertTrue((new TrustService())->isTrusted($this->user));
    }

    /** A registered account is trusted regardless of message count. */
    public function testRegisteredUserAlwaysTrusted(): void
    {
        $this->pdo->prepare('INSERT INTO users (username, password, usertype) VALUES (?, ?, 0)')
            ->execute([$this->user, 'x']);
        $this->assertTrue((new TrustService())->isTrusted($this->user));
    }

    /** An empty username is never trusted. */
    public function testEmptyNotTrusted(): void
    {
        $this->assertFalse((new TrustService())->isTrusted('   '));
    }
}
