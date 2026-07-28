<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use RadioChatBox\Database;

/**
 * Regression for the impersonation/DM conversation view hiding recent messages.
 *
 * The conversation queries in public/api/private-message.php used
 * `ORDER BY created_at ASC LIMIT 500`, which returns the OLDEST 500 messages —
 * so in a long thread (a heavily-used fake user) newly sent messages, including
 * the admin's own while impersonating, were truncated away and never shown.
 *
 * The fix fetches the most RECENT 500 (`ORDER BY created_at DESC LIMIT 500`) and
 * reverses them to chronological order for display. This test seeds a >500
 * message thread and pins that behaviour at the query level (the endpoint is
 * legacy raw PHP with no callable seam, so the query + reversal are mirrored
 * here exactly).
 */
class PrivateMessageHistoryTest extends TestCase
{
    private \PDO $pdo;
    private string $fake;
    private string $peer;
    private const TOTAL = 520; // > the 500 window

    protected function setUp(): void
    {
        $this->pdo = Database::getPDO();
        $suffix = substr(bin2hex(random_bytes(5)), 0, 10);
        $this->fake = 'histfake_' . $suffix;
        $this->peer = 'histpeer_' . $suffix;

        // Seed a long thread: the peer and the fake user alternate. The message
        // body encodes its ordinal so we can assert exactly which survive.
        $values = [];
        $params = [];
        for ($i = 1; $i <= self::TOTAL; $i++) {
            // Odd = peer -> fake, even = fake -> peer. created_at strictly increases.
            if ($i % 2 === 1) {
                $from = $this->peer; $to = $this->fake;
            } else {
                $from = $this->fake; $to = $this->peer;
            }
            $values[] = "(?, ?, ?, ?, ?, NOW() + (? || ' seconds')::interval)";
            array_push($params, $from, 'sess_' . $from, $to, 'sess_' . $to, 'msg #' . $i, $i);
        }
        $this->pdo->prepare(
            'INSERT INTO private_messages (from_username, from_session_id, to_username, to_session_id, message, created_at)
             VALUES ' . implode(',', $values)
        )->execute($params);
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare('DELETE FROM private_messages WHERE from_username = ? OR to_username = ?')
            ->execute([$this->fake, $this->fake]);
    }

    /**
     * The admin-mode conversation query (both directions, ignoring session) must
     * return the most recent 500 messages in chronological order — so the newest
     * message is present and the oldest is dropped, not the other way round.
     */
    public function testConversationReturnsNewest500Chronologically(): void
    {
        // Mirrors public/api/private-message.php admin-mode branch (+ the reversal).
        $stmt = $this->pdo->prepare(
            'SELECT message, created_at FROM private_messages
             WHERE ((from_username = ? AND to_username = ?) OR (from_username = ? AND to_username = ?))
             ORDER BY created_at DESC
             LIMIT 500'
        );
        $stmt->execute([$this->fake, $this->peer, $this->peer, $this->fake]);
        $messages = array_reverse($stmt->fetchAll(\PDO::FETCH_ASSOC));

        $this->assertCount(500, $messages, 'the window is capped at 500');

        $bodies = array_column($messages, 'message');
        // The newest message (the one the admin just sent) MUST be present…
        $this->assertContains('msg #' . self::TOTAL, $bodies, 'the newest message must be shown');
        // …and the oldest ones (beyond the window) must be the ones dropped.
        $this->assertNotContains('msg #1', $bodies, 'the oldest message is outside the recent window');
        $this->assertContains('msg #' . (self::TOTAL - 499), $bodies, 'exactly the newest 500 are kept');

        // Chronological (oldest-first) order for display.
        $this->assertSame('msg #' . (self::TOTAL - 499), $bodies[0]);
        $this->assertSame('msg #' . self::TOTAL, $bodies[array_key_last($bodies)]);
    }
}
