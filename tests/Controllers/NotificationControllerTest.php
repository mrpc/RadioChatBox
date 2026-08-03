<?php

namespace RadioChatBox\Tests\Controllers;

use PDO;
use PHPUnit\Framework\TestCase;
use Pramnos\Framework\Testing\TestDatabase;
use RadioChatBox\Controllers\NotificationController;
use RadioChatBox\Services\NotificationService;

/**
 * The notification inbox endpoints: session-verified list (+ unread count) and
 * mark-read.
 */
class NotificationControllerTest extends TestCase
{
    private PDO $pdo;
    private string $user;
    private string $session;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::connection();
        $suffix = substr(bin2hex(random_bytes(5)), 0, 8);
        $this->user = 'nc_' . $suffix;
        $this->session = 'ncsess_' . $suffix;
        $this->pdo->prepare(
            'INSERT INTO presence_sessions (username, session_id, ip_address, last_heartbeat, joined_at)
             VALUES (?, ?, ?, NOW(), NOW())'
        )->execute([$this->user, $this->session, '127.0.0.1']);
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare('DELETE FROM user_notifications WHERE username = ?')->execute([$this->user]);
        $this->pdo->prepare('DELETE FROM presence_sessions WHERE username = ?')->execute([$this->user]);
        $_POST = [];
        $_GET = [];
    }

    /** list returns the user's notifications and unread count for a valid session. */
    public function testListReturnsNotifications(): void
    {
        (new NotificationService())->add($this->user, 'reaction', 'someone reacted 👍', null, 'msg_x');

        $_GET = ['username' => $this->user, 'session_id' => $this->session];
        $response = (new NotificationController())->list();
        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertCount(1, $body['notifications']);
        $this->assertSame(1, $body['unread_count']);
    }

    /** list rejects a bad session. */
    public function testListRejectsBadSession(): void
    {
        $_GET = ['username' => $this->user, 'session_id' => 'nope'];
        $this->assertSame(403, (new NotificationController())->list()->getStatusCode());
    }

    /** mark-read (all) clears the unread count. */
    public function testMarkAllRead(): void
    {
        $svc = new NotificationService();
        $svc->add($this->user, 'system', 'a');
        $svc->add($this->user, 'system', 'b');

        $_POST = ['username' => $this->user, 'session_id' => $this->session];
        $response = (new NotificationController())->markRead();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $svc->unreadCount($this->user));
    }

    /** endpoints validate required fields. */
    public function testValidation(): void
    {
        $_GET = [];
        $this->assertSame(400, (new NotificationController())->list()->getStatusCode());
        $_POST = [];
        $this->assertSame(400, (new NotificationController())->markRead()->getStatusCode());
    }
}
