<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use RadioChatBox\ReactionService;
use RadioChatBox\Database;

class ReactionServiceTest extends TestCase
{
    private ReactionService $service;
    private \PDO $pdo;
    private string $messageId;
    private string $userA = '__test_reactor_a__';
    private string $userB = '__test_reactor_b__';

    protected function setUp(): void
    {
        $this->service = new ReactionService();
        $this->pdo = Database::getPDO();

        // Create a dedicated public message to react to (FK target).
        $this->messageId = 'msg_test_' . bin2hex(random_bytes(6));
        $stmt = $this->pdo->prepare(
            'INSERT INTO messages (message_id, username, message, ip_address, created_at)
             VALUES (?, ?, ?, ?, NOW())'
        );
        $stmt->execute([$this->messageId, '__test_author__', 'test message', '127.0.0.1']);
    }

    protected function tearDown(): void
    {
        // Reactions are removed via FK ON DELETE CASCADE, but clean explicitly too.
        $this->pdo->prepare('DELETE FROM message_reactions WHERE message_id = ?')->execute([$this->messageId]);
        $this->pdo->prepare('DELETE FROM messages WHERE message_id = ?')->execute([$this->messageId]);
    }

    private function countRowsForUser(string $username): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM message_reactions WHERE message_id = ? AND LOWER(username) = LOWER(?)'
        );
        $stmt->execute([$this->messageId, $username]);
        return (int)$stmt->fetchColumn();
    }

    public function testToggleAddsReaction(): void
    {
        $result = $this->service->toggleReaction($this->messageId, $this->userA, 'sess', '👍');
        $this->assertSame('added', $result['action']);

        $reactions = $this->service->getReactionsForMessage($this->messageId, $this->userA);
        $this->assertCount(1, $reactions);
        $this->assertSame('👍', $reactions[0]['emoji']);
        $this->assertSame(1, $reactions[0]['count']);
        $this->assertTrue($reactions[0]['mine']);
    }

    public function testSameEmojiTogglesOff(): void
    {
        $this->service->toggleReaction($this->messageId, $this->userA, 'sess', '👍');
        $result = $this->service->toggleReaction($this->messageId, $this->userA, 'sess', '👍');

        $this->assertSame('removed', $result['action']);
        $this->assertCount(0, $this->service->getReactionsForMessage($this->messageId, $this->userA));
        $this->assertSame(0, $this->countRowsForUser($this->userA));
    }

    public function testDifferentEmojiReplacesPrevious(): void
    {
        $this->service->toggleReaction($this->messageId, $this->userA, 'sess', '👍');
        $result = $this->service->toggleReaction($this->messageId, $this->userA, 'sess', '❤️');

        $this->assertSame('changed', $result['action']);

        // Exactly one reaction per user, and it's the new emoji.
        $this->assertSame(1, $this->countRowsForUser($this->userA));
        $reactions = $this->service->getReactionsForMessage($this->messageId, $this->userA);
        $this->assertCount(1, $reactions);
        $this->assertSame('❤️', $reactions[0]['emoji']);
    }

    public function testAggregationCountsAcrossUsers(): void
    {
        $this->service->toggleReaction($this->messageId, $this->userA, 'sess', '🔥');
        $this->service->toggleReaction($this->messageId, $this->userB, 'sess', '🔥');

        $reactions = $this->service->getReactionsForMessage($this->messageId, $this->userA);
        $this->assertCount(1, $reactions);
        $this->assertSame('🔥', $reactions[0]['emoji']);
        $this->assertSame(2, $reactions[0]['count']);
        $this->assertTrue($reactions[0]['mine']); // from userA's perspective
    }

    public function testRejectsDisallowedEmoji(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->toggleReaction($this->messageId, $this->userA, 'sess', '🍕');
    }

    public function testThrowsWhenMessageMissing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->toggleReaction('msg_does_not_exist_' . bin2hex(random_bytes(4)), $this->userA, 'sess', '👍');
    }

    public function testAttachToMessagesAddsReactionsArray(): void
    {
        $this->service->toggleReaction($this->messageId, $this->userA, 'sess', '😂');

        $messages = [['id' => $this->messageId, 'message' => 'x']];
        $out = $this->service->attachToMessages($messages, $this->userA);

        $this->assertArrayHasKey('reactions', $out[0]);
        $this->assertCount(1, $out[0]['reactions']);
        $this->assertSame('😂', $out[0]['reactions'][0]['emoji']);
        $this->assertTrue($out[0]['reactions'][0]['mine']);
    }

    public function testGetAllowedEmojisIsNonEmpty(): void
    {
        $allowed = ReactionService::getAllowedEmojis();
        $this->assertNotEmpty($allowed);
        $this->assertContains('👍', $allowed);
    }
}
