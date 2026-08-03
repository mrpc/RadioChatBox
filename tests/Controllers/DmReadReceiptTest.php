<?php

namespace RadioChatBox\Tests\Controllers;

use PDO;
use PHPUnit\Framework\TestCase;
use Pramnos\Framework\Testing\TestDatabase;
use RadioChatBox\Controllers\MessageActionController;

/**
 * DM read receipts: marking a conversation read stamps read_at on the peer's
 * unread messages to me (and only those), requires an owned session, and
 * validates its inputs.
 */
class DmReadReceiptTest extends TestCase
{
    private PDO $pdo;
    private string $me;
    private string $peer;
    private string $session;
    /** @var list<int> */
    private array $dmIds = [];

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::connection();
        $suffix = substr(bin2hex(random_bytes(5)), 0, 8);
        $this->me = 'rr_me_' . $suffix;
        $this->peer = 'rr_peer_' . $suffix;
        $this->session = 'rrsess_' . $suffix;

        $this->pdo->prepare(
            'INSERT INTO presence_sessions (username, session_id, ip_address, last_heartbeat, joined_at)
             VALUES (?, ?, ?, NOW(), NOW())'
        )->execute([$this->me, $this->session, '127.0.0.1']);
    }

    protected function tearDown(): void
    {
        foreach ($this->dmIds as $id) {
            $this->pdo->prepare('DELETE FROM private_messages WHERE id = ?')->execute([$id]);
        }
        $this->pdo->prepare('DELETE FROM presence_sessions WHERE username = ?')->execute([$this->me]);
        $this->dmIds = [];
        $_POST = [];
    }

    private function dm(string $from, string $to): int
    {
        $row = $this->pdo->query(
            "INSERT INTO private_messages (from_username, to_username, message, created_at)
             VALUES (" . $this->pdo->quote($from) . ", " . $this->pdo->quote($to) . ", 'hi', NOW()) RETURNING id"
        )->fetchColumn();
        $this->dmIds[] = (int) $row;
        return (int) $row;
    }

    /** Marking read stamps read_at on the peer→me messages, not my own. */
    public function testMarkReadStampsPeerMessages(): void
    {
        $fromPeer = $this->dm($this->peer, $this->me); // unread, should be marked
        $fromMe   = $this->dm($this->me, $this->peer); // my own, must NOT be marked

        $_POST = ['username' => $this->me, 'session_id' => $this->session, 'peer' => $this->peer];
        $response = (new MessageActionController())->markRead();
        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertSame(1, $body['marked']);

        $peerRead = $this->pdo->query('SELECT read_at FROM private_messages WHERE id = ' . $fromPeer)->fetchColumn();
        $mineRead = $this->pdo->query('SELECT read_at FROM private_messages WHERE id = ' . $fromMe)->fetchColumn();
        $this->assertNotNull($peerRead, 'peer message marked read');
        $this->assertNull($mineRead, 'my own message untouched');
    }

    /** A second mark-read finds nothing new to mark. */
    public function testMarkReadIsIdempotent(): void
    {
        $this->dm($this->peer, $this->me);
        $_POST = ['username' => $this->me, 'session_id' => $this->session, 'peer' => $this->peer];
        (new MessageActionController())->markRead();
        $response = (new MessageActionController())->markRead();
        $this->assertSame(0, json_decode($response->getBody(), true)['marked']);
    }

    /** An unowned session is rejected. */
    public function testMarkReadRejectsBadSession(): void
    {
        $_POST = ['username' => $this->me, 'session_id' => 'not-mine', 'peer' => $this->peer];
        $this->assertSame(403, (new MessageActionController())->markRead()->getStatusCode());
    }

    /** Missing fields are a 400. */
    public function testMarkReadValidatesInput(): void
    {
        $_POST = ['username' => $this->me, 'session_id' => $this->session];
        $this->assertSame(400, (new MessageActionController())->markRead()->getStatusCode());
    }
}
