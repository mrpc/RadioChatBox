<?php

namespace RadioChatBox\Tests;

use Pramnos\Framework\Testing\TestDatabase;
use PHPUnit\Framework\TestCase;
use RadioChatBox\Services\ReactionService;

/**
 * Reactions on direct messages: ReactionService::toggleDmReaction writes to the
 * dedicated private_message_reactions table (FK to private_messages, so it cannot
 * reuse public message_reactions), keyed off a real private_messages row id and
 * toggling on/off like the public path.
 */
class DmReactionTest extends TestCase
{
    private int $dmId = 0;

    protected function tearDown(): void
    {
        $pdo = TestDatabase::connection();
        if ($this->dmId > 0) {
            try {
                $pdo->prepare('DELETE FROM private_message_reactions WHERE message_id = ?')->execute([$this->dmId]);
                $pdo->prepare('DELETE FROM private_messages WHERE id = ?')->execute([$this->dmId]);
            } catch (\Throwable) {
            }
        }
        $this->dmId = 0;
        parent::tearDown();
    }

    private function makeDm(string $from, string $to): int
    {
        $pdo = TestDatabase::connection();
        $stmt = $pdo->prepare(
            "INSERT INTO private_messages (from_username, to_username, message, created_at)
             VALUES (?, ?, 'hi', NOW()) RETURNING id"
        );
        $stmt->execute([$from, $to]);
        return (int) $stmt->fetchColumn();
    }

    public function testToggleDmReactionAddsThenRemoves(): void
    {
        $this->dmId = $this->makeDm('alice_' . uniqid(), 'bob_' . uniqid());
        $svc = new ReactionService();

        $add = $svc->toggleDmReaction($this->dmId, 'alice', 'sess-a', '👍');
        $this->assertSame('added', $add['action']);
        $this->assertSame($this->dmId, $add['message_id']);
        $this->assertSame(1, $add['reactions'][0]['count'] ?? 0);

        $remove = $svc->toggleDmReaction($this->dmId, 'alice', 'sess-a', '👍');
        $this->assertSame('removed', $remove['action']);
        $this->assertSame([], $remove['reactions']);
    }

    public function testAttachToMessagesSurfacesDmReactionForHistory(): void
    {
        $this->dmId = $this->makeDm('carol_' . uniqid(), 'dave_' . uniqid());
        $svc = new ReactionService();
        $svc->toggleDmReaction($this->dmId, 'carol', 'sess-c', '🔥');

        // The DM history path attaches reactions from private_message_reactions,
        // keyed by the numeric private_messages id (string in the row).
        $attached = $svc->attachToMessages(
            [['id' => (string) $this->dmId]],
            'carol',
            'private_message_reactions'
        );
        $reactions = $attached[0]['reactions'] ?? [];
        $this->assertNotEmpty($reactions, 'the DM reaction is attached for history');
        $this->assertSame('🔥', $reactions[0]['emoji']);
        $this->assertSame(1, $reactions[0]['count']);
        $this->assertTrue($reactions[0]['mine'], 'the reactor sees it as their own');
    }

    public function testToggleDmReactionRejectsUnknownMessage(): void
    {
        $this->expectException(\RuntimeException::class);
        (new ReactionService())->toggleDmReaction(999999999, 'alice', 'sess-a', '👍');
    }

    public function testToggleDmReactionRejectsDisallowedEmoji(): void
    {
        $this->dmId = $this->makeDm('a_' . uniqid(), 'b_' . uniqid());
        $this->expectException(\InvalidArgumentException::class);
        (new ReactionService())->toggleDmReaction($this->dmId, 'alice', 'sess-a', '💩');
    }
}
