<?php

namespace RadioChatBox\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Pramnos\Framework\Testing\TestDatabase;
use RadioChatBox\Services\ReputationService;

/**
 * Reputation score: positive from messages + reactions received, negative from
 * warnings + reports against, clamped at zero, with a tier label.
 */
class ReputationServiceTest extends TestCase
{
    private PDO $pdo;
    private string $user;
    /** @var list<string> */
    private array $messageIds = [];
    /** @var list<int> */
    private array $reportIds = [];

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::connection();
        $this->user = 'rep_' . substr(bin2hex(random_bytes(5)), 0, 8);
    }

    protected function tearDown(): void
    {
        foreach ($this->messageIds as $mid) {
            $this->pdo->prepare('DELETE FROM message_reactions WHERE message_id = ?')->execute([$mid]);
            $this->pdo->prepare('DELETE FROM chat_messages WHERE message_id = ?')->execute([$mid]);
        }
        foreach ($this->reportIds as $id) {
            $this->pdo->prepare('DELETE FROM message_reports WHERE id = ?')->execute([$id]);
        }
        $this->pdo->prepare('DELETE FROM user_warnings WHERE username = ?')->execute([$this->user]);
        $this->messageIds = [];
        $this->reportIds = [];
    }

    private function message(): string
    {
        $mid = 'repmsg_' . bin2hex(random_bytes(6));
        $this->pdo->prepare(
            'INSERT INTO chat_messages (message_id, username, message, ip_address, created_at)
             VALUES (?, ?, ?, ?, NOW())'
        )->execute([$mid, $this->user, 'hi', '127.0.0.1']);
        $this->messageIds[] = $mid;
        return $mid;
    }

    /** Positive contributions accumulate: messages (×1) + reactions (×2). */
    public function testPositiveScore(): void
    {
        $m = $this->message();
        $this->message();
        // one reaction on the first message → +2
        $this->pdo->prepare('INSERT INTO message_reactions (message_id, username, emoji) VALUES (?, ?, ?)')
            ->execute([$m, 'fan', '👍']);

        $rep = (new ReputationService())->forUser($this->user);
        $this->assertSame(2, $rep['messages']);
        $this->assertSame(1, $rep['reactions']);
        $this->assertSame(4, $rep['score']); // 2*1 + 1*2
    }

    /** Warnings and reports subtract; the score never goes below zero. */
    public function testNegativeClampsToZero(): void
    {
        $this->message(); // +1
        $this->pdo->prepare('INSERT INTO user_warnings (username, moderator) VALUES (?, ?)')
            ->execute([$this->user, 'mod']); // -10
        $rep = (new ReputationService())->forUser($this->user);
        $this->assertSame(0, $rep['score'], 'clamped at zero');
        $this->assertSame(1, $rep['warnings']);
    }

    /** An empty username scores zero on the "New" tier. */
    public function testEmptyUser(): void
    {
        $rep = (new ReputationService())->forUser('   ');
        $this->assertSame(0, $rep['score']);
        $this->assertSame('New', $rep['tier']);
    }
}
