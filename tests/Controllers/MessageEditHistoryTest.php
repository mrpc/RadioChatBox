<?php

namespace RadioChatBox\Tests\Controllers;

use PDO;
use PHPUnit\Framework\TestCase;
use Pramnos\Framework\Testing\TestDatabase;
use RadioChatBox\Controllers\AdminSystemController;
use RadioChatBox\Controllers\MessageActionController;

/**
 * Editing a public message snapshots its previous text into message_edits, and
 * the admin message-edits endpoint returns that history newest-first alongside
 * the current text.
 */
class MessageEditHistoryTest extends TestCase
{
    private PDO $pdo;
    private string $user;
    private string $session;
    private string $messageId;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::connection();
        $suffix = substr(bin2hex(random_bytes(5)), 0, 8);
        $this->user = 'edit_' . $suffix;
        $this->session = 'esess_' . $suffix;
        $this->messageId = 'emsg_' . $suffix;

        $this->pdo->prepare(
            'INSERT INTO presence_sessions (username, session_id, ip_address, last_heartbeat, joined_at)
             VALUES (?, ?, ?, NOW(), NOW())'
        )->execute([$this->user, $this->session, '127.0.0.1']);
        $this->pdo->prepare(
            'INSERT INTO chat_messages (message_id, username, message, ip_address, created_at)
             VALUES (?, ?, ?, ?, NOW())'
        )->execute([$this->messageId, $this->user, 'the original text', '127.0.0.1']);
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare('DELETE FROM message_edits WHERE message_id = ?')->execute([$this->messageId]);
        $this->pdo->prepare('DELETE FROM chat_messages WHERE message_id = ?')->execute([$this->messageId]);
        $this->pdo->prepare('DELETE FROM presence_sessions WHERE username = ?')->execute([$this->user]);
        $_POST = [];
        $_GET = [];
    }

    /** Editing records the previous text and the admin endpoint returns it. */
    public function testEditRecordsHistoryAndAdminCanRead(): void
    {
        $_POST = [
            'message_id' => $this->messageId,
            'message'    => 'the edited text',
            'username'   => $this->user,
            'sessionId'  => $this->session,
        ];
        $response = (new MessageActionController())->editMessage();
        $this->assertSame(200, $response->getStatusCode());

        // A history row now holds the pre-edit text.
        $old = $this->pdo->query(
            'SELECT old_message FROM message_edits WHERE message_id = '
            . $this->pdo->quote($this->messageId) . ' ORDER BY created_at DESC LIMIT 1'
        )->fetchColumn();
        $this->assertSame('the original text', $old);

        // The admin endpoint surfaces the current text plus the edit history.
        $_GET = ['message_id' => $this->messageId];
        $adminResp = (new AdminSystemController())->messageEdits();
        $this->assertSame(200, $adminResp->getStatusCode());
        $body = json_decode($adminResp->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertSame('the edited text', $body['current']['message']);
        $this->assertCount(1, $body['edits']);
        $this->assertSame('the original text', $body['edits'][0]['old_message']);
        $this->assertSame($this->user, $body['edits'][0]['edited_by']);
    }

    /** The admin endpoint needs a message_id. */
    public function testAdminEndpointRequiresMessageId(): void
    {
        $_GET = [];
        $this->assertSame(400, (new AdminSystemController())->messageEdits()->getStatusCode());
    }
}
