<?php

namespace RadioChatBox\Tests\Controllers;

use PDO;
use PHPUnit\Framework\TestCase;
use Pramnos\Framework\Testing\TestDatabase;
use RadioChatBox\Controllers\SearchController;
use RadioChatBox\Services\ChatService;

/**
 * User-facing chat search: the service finds public messages case-insensitively
 * (excluding deleted ones) and the endpoint returns them, treating a too-short
 * query as an empty result set rather than an error.
 */
class SearchControllerTest extends TestCase
{
    private PDO $pdo;
    /** @var list<string> */
    private array $ids = [];
    private string $needle;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::connection();
        $this->needle = 'zqx' . substr(bin2hex(random_bytes(4)), 0, 6);
        $this->seed('hello ' . $this->needle . ' world', false);
        $this->seed('UPPER ' . strtoupper($this->needle) . ' case', false);
        $this->seed('deleted ' . $this->needle, true);
        $this->seed('nothing to see here', false);
    }

    protected function tearDown(): void
    {
        foreach ($this->ids as $mid) {
            $this->pdo->prepare('DELETE FROM chat_messages WHERE message_id = ?')->execute([$mid]);
        }
        $this->ids = [];
        $_GET = [];
    }

    private function seed(string $text, bool $deleted): void
    {
        $mid = 'search_' . bin2hex(random_bytes(6));
        $this->pdo->prepare(
            'INSERT INTO chat_messages (message_id, username, message, ip_address, is_deleted, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        )->execute([$mid, 'searcher', $text, '127.0.0.1', $deleted ? 'true' : 'false']);
        $this->ids[] = $mid;
    }

    /** The service finds matches case-insensitively and skips deleted rows. */
    public function testServiceFindsMatchesIgnoringCaseAndDeleted(): void
    {
        $results = (new ChatService())->searchMessages($this->needle, 50);
        $texts = array_map(fn ($r) => $r['message'], $results);

        $this->assertContains('hello ' . $this->needle . ' world', $texts);
        $this->assertContains('UPPER ' . strtoupper($this->needle) . ' case', $texts, 'case-insensitive');
        foreach ($texts as $t) {
            $this->assertStringNotContainsString('deleted ' . $this->needle, $t, 'deleted excluded');
        }
    }

    /** A query under 2 chars returns nothing. */
    public function testServiceIgnoresTooShortQuery(): void
    {
        $this->assertSame([], (new ChatService())->searchMessages('a', 50));
    }

    /** The endpoint returns matching results. */
    public function testEndpointReturnsResults(): void
    {
        $_GET = ['q' => $this->needle];
        $response = (new SearchController())->search();
        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertGreaterThanOrEqual(2, count($body['results']));
    }

    /** The endpoint treats a too-short query as an empty (successful) result. */
    public function testEndpointShortQueryIsEmpty(): void
    {
        $_GET = ['q' => 'a'];
        $response = (new SearchController())->search();
        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertSame([], $body['results']);
    }
}
