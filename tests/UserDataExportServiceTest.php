<?php

namespace RadioChatBox\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Pramnos\Framework\Testing\TestDatabase;
use RadioChatBox\Controllers\AdminSystemController;
use RadioChatBox\Services\UserDataExportService;

/**
 * The GDPR-style user data export gathers a user's public messages, private
 * messages (as sender or recipient), reports (filed and against) and song
 * requests, and the admin endpoint serves it as a JSON download.
 */
class UserDataExportServiceTest extends TestCase
{
    private PDO $pdo;
    private string $user;
    /** @var list<string> */
    private array $publicIds = [];

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::connection();
        $this->user = 'gdpr_' . substr(bin2hex(random_bytes(5)), 0, 8);

        $mid = 'gmsg_' . bin2hex(random_bytes(6));
        $this->pdo->prepare(
            'INSERT INTO chat_messages (message_id, username, message, ip_address, created_at)
             VALUES (?, ?, ?, ?, NOW())'
        )->execute([$mid, $this->user, 'my public message', '127.0.0.1']);
        $this->publicIds[] = $mid;

        $this->pdo->prepare(
            'INSERT INTO private_messages (from_username, to_username, message, created_at)
             VALUES (?, ?, ?, NOW())'
        )->execute([$this->user, 'someone', 'my dm']);

        $this->pdo->prepare(
            'INSERT INTO song_requests (requester_username, song_title, dedication, status, created_at)
             VALUES (?, ?, ?, ?, NOW())'
        )->execute([$this->user, 'A Song', 'for a friend', 'pending']);
    }

    protected function tearDown(): void
    {
        foreach ($this->publicIds as $mid) {
            $this->pdo->prepare('DELETE FROM chat_messages WHERE message_id = ?')->execute([$mid]);
        }
        $this->pdo->prepare('DELETE FROM private_messages WHERE from_username = ?')->execute([$this->user]);
        $this->pdo->prepare('DELETE FROM song_requests WHERE requester_username = ?')->execute([$this->user]);
        $this->publicIds = [];
        $_GET = [];
    }

    /** The service gathers each category of the user's data. */
    public function testExportGathersData(): void
    {
        $data = (new UserDataExportService())->export($this->user);

        $this->assertSame($this->user, $data['username']);
        $this->assertCount(1, $data['public_messages']);
        $this->assertSame('my public message', $data['public_messages'][0]['message']);
        $this->assertCount(1, $data['private_messages']);
        $this->assertCount(1, $data['song_requests']);
        $this->assertArrayHasKey('profile', $data);
        $this->assertArrayHasKey('warnings', $data);
    }

    /** The admin endpoint serves the export as a JSON download. */
    public function testEndpointServesJsonDownload(): void
    {
        $_GET = ['username' => $this->user];
        $response = (new AdminSystemController())->userDataExport();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('application/json', (string) $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('attachment; filename="user-data-', (string) $response->getHeaderLine('Content-Disposition'));
        $decoded = json_decode($response->getBody(), true);
        $this->assertSame($this->user, $decoded['username']);
        $this->assertNotNull($decoded['generated_at']);
    }

    /** A missing username is a 400. */
    public function testEndpointRequiresUsername(): void
    {
        $_GET = [];
        $this->assertSame(400, (new AdminSystemController())->userDataExport()->getStatusCode());
    }
}
