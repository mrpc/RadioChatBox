<?php

namespace RadioChatBox\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Pramnos\Framework\Testing\TestDatabase;
use RadioChatBox\Services\NotificationService;

/**
 * Per-user notification inbox: add + list (newest first), unread count, and
 * mark-read for one or all, scoped to the owning user.
 */
class NotificationServiceTest extends TestCase
{
    private PDO $pdo;
    private string $user;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::connection();
        $this->user = 'notif_' . substr(bin2hex(random_bytes(5)), 0, 8);
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare('DELETE FROM user_notifications WHERE username = ?')->execute([$this->user]);
    }

    /** add stores a notification and it appears in the list unread. */
    public function testAddAndList(): void
    {
        $svc = new NotificationService();
        $id = $svc->add($this->user, 'reaction', 'bob reacted 👍 to your message', null, 'msg_1');
        $this->assertGreaterThan(0, $id);

        $list = $svc->listFor($this->user);
        $this->assertCount(1, $list);
        $this->assertSame('reaction', $list[0]['type']);
        $this->assertFalse((bool) $list[0]['is_read']);
        $this->assertSame(1, $svc->unreadCount($this->user));
    }

    /** add is a no-op for an empty username or title. */
    public function testAddValidation(): void
    {
        $svc = new NotificationService();
        $this->assertSame(0, $svc->add('', 'system', 'hi'));
        $this->assertSame(0, $svc->add($this->user, 'system', '   '));
    }

    /** markRead(id) marks just that one; unread count drops. */
    public function testMarkOneRead(): void
    {
        $svc = new NotificationService();
        $a = $svc->add($this->user, 'system', 'first');
        $svc->add($this->user, 'system', 'second');
        $this->assertSame(2, $svc->unreadCount($this->user));

        $svc->markRead($this->user, $a);
        $this->assertSame(1, $svc->unreadCount($this->user));
    }

    /** markRead() with no id marks everything read. */
    public function testMarkAllRead(): void
    {
        $svc = new NotificationService();
        $svc->add($this->user, 'system', 'a');
        $svc->add($this->user, 'system', 'b');
        $svc->markRead($this->user);
        $this->assertSame(0, $svc->unreadCount($this->user));
    }

    /** unreadOnly filters to unread notifications. */
    public function testUnreadOnlyFilter(): void
    {
        $svc = new NotificationService();
        $read = $svc->add($this->user, 'system', 'read one');
        $svc->add($this->user, 'system', 'unread one');
        $svc->markRead($this->user, $read);

        $unread = $svc->listFor($this->user, 30, true);
        $this->assertCount(1, $unread);
        $this->assertSame('unread one', $unread[0]['title']);
    }

    /** A user can't mark another user's notification read. */
    public function testMarkReadScopedToUser(): void
    {
        $svc = new NotificationService();
        $mine = $svc->add($this->user, 'system', 'mine');
        // A different user tries to mark my notification read → no effect.
        $svc->markRead('someone_else', $mine);
        $this->assertSame(1, $svc->unreadCount($this->user));
    }
}
