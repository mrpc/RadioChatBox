<?php

namespace RadioChatBox\Tests\Controllers;

use PDO;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\FlatCache;
use Pramnos\Framework\Testing\TestDatabase;
use RadioChatBox\Controllers\SendController;
use RadioChatBox\Services\NotificationService;
use RadioChatBox\Services\SettingsService;

/**
 * Posting a public message that @mentions a known participant creates a
 * notification in that participant's inbox — but not for unknown names or the
 * sender themselves.
 */
class MentionNotificationTest extends TestCase
{
    private PDO $pdo;
    private string $sender;
    private string $senderSession;
    private string $mentioned;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::connection();
        $suffix = substr(bin2hex(random_bytes(5)), 0, 8);
        $this->sender = 'snd_' . $suffix;
        $this->senderSession = 'sndsess_' . $suffix;
        $this->mentioned = 'mtn_' . $suffix;

        foreach ([[$this->sender, $this->senderSession], [$this->mentioned, 'msess_' . $suffix]] as [$u, $s]) {
            $this->pdo->prepare(
                'INSERT INTO presence_sessions (username, session_id, ip_address, last_heartbeat, joined_at)
                 VALUES (?, ?, ?, NOW(), NOW())'
            )->execute([$u, $s, '127.0.0.1']);
        }
        (new SettingsService())->set('chat_mode', 'both');
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare('DELETE FROM user_notifications WHERE username IN (?, ?)')->execute([$this->mentioned, $this->sender]);
        $this->pdo->prepare('DELETE FROM presence_sessions WHERE username IN (?, ?)')->execute([$this->sender, $this->mentioned]);
        $this->pdo->prepare('DELETE FROM chat_messages WHERE username = ?')->execute([$this->sender]);
        $this->pdo->prepare("DELETE FROM settings WHERE setting = 'chat_mode'")->execute();
        FlatCache::default()->clear();
        $_POST = [];
    }

    /** A message mentioning a known user notifies them, but not the sender. */
    public function testMentionNotifiesKnownUser(): void
    {
        $_POST = [
            'username'  => $this->sender,
            'message'   => "hey @{$this->mentioned} and @{$this->sender} check this",
            'sessionId' => $this->senderSession,
        ];
        $response = (new SendController())->store();
        $this->assertSame(200, $response->getStatusCode());

        $svc = new NotificationService();
        $mine = $svc->listFor($this->mentioned);
        $this->assertCount(1, $mine, 'the mentioned user got one notification');
        $this->assertSame('mention', $mine[0]['type']);
        $this->assertStringContainsString($this->sender, $mine[0]['title']);

        // The sender is never notified for mentioning themselves.
        $this->assertSame(0, $svc->unreadCount($this->sender));
    }

    /** Mentioning an unknown name creates no notification. */
    public function testUnknownMentionIgnored(): void
    {
        $ghost = 'ghost_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $_POST = [
            'username'  => $this->sender,
            'message'   => "hello @{$ghost}",
            'sessionId' => $this->senderSession,
        ];
        (new SendController())->store();

        try {
            $this->assertSame(0, (new NotificationService())->unreadCount($ghost));
        } finally {
            $this->pdo->prepare('DELETE FROM user_notifications WHERE username = ?')->execute([$ghost]);
        }
    }
}
