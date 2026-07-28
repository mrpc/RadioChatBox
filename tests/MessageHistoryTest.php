<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use RadioChatBox\Database;
use RadioChatBox\MessageHistory;

/**
 * Covers RadioChatBox\MessageHistory, the single owner of the recent-message
 * Redis state (the `chat:messages` list, the `chat:messages:hash` reply-quote
 * hash and the `chat:deleted_messages` tombstones) consolidated behind one
 * boundary in Phase 8 Step 4.
 *
 * Runs against the shared dev Redis using a random per-test key prefix for
 * isolation, and cleans up after itself. Pins the list ordering (newest-first),
 * the bounded trim, the reply-hash round-trip and edit, and the delete
 * tombstone filter — the exact behaviours ChatService relies on.
 */
class MessageHistoryTest extends TestCase
{
    private string $prefix;
    private MessageHistory $history;

    protected function setUp(): void
    {
        $this->prefix  = 'mhtest_' . bin2hex(random_bytes(6)) . ':';
        $this->history = new MessageHistory(Database::getRedis(), $this->prefix, 5);
    }

    protected function tearDown(): void
    {
        try {
            $redis = Database::getRedis();
            $redis->del($this->prefix . 'chat:messages');
            $redis->del($this->prefix . 'chat:messages:hash');
            $redis->del($this->prefix . 'chat:deleted_messages');
        } catch (\Throwable) {
            // best effort
        }
    }

    /**
     * append() prepends messages so the newest is first, and recent() returns
     * them decoded in that newest-first order.
     */
    public function testAppendAndRecentAreNewestFirst(): void
    {
        $this->history->append(['id' => 'm1', 'message' => 'first']);
        $this->history->append(['id' => 'm2', 'message' => 'second']);

        $recent = $this->history->recent(50);
        $this->assertCount(2, $recent);
        $this->assertSame('m2', $recent[0]['id'], 'newest message first');
        $this->assertSame('m1', $recent[1]['id']);
    }

    /**
     * append() trims the list to the configured history limit, dropping the
     * oldest messages beyond it.
     */
    public function testAppendTrimsToHistoryLimit(): void
    {
        for ($i = 1; $i <= 8; $i++) {
            $this->history->append(['id' => 'm' . $i, 'message' => (string) $i]);
        }

        $recent = $this->history->recent(50);
        $this->assertCount(5, $recent, 'trimmed to the limit of 5');
        $this->assertSame('m8', $recent[0]['id']);
        $this->assertSame('m4', $recent[4]['id'], 'oldest kept is m4; m1-m3 trimmed');
    }

    /**
     * recent() returns an empty array when nothing is cached (the signal for
     * ChatService to rebuild from the database).
     */
    public function testRecentEmptyWhenNothingCached(): void
    {
        $this->assertSame([], $this->history->recent(50));
    }

    /**
     * replace() rebuilds the whole list from a newest-first set so the newest
     * ends up at position 0, and clear() empties it.
     */
    public function testReplaceRebuildsAndClearEmpties(): void
    {
        $this->history->append(['id' => 'stale', 'message' => 'old cache']);

        $this->history->replace([
            ['id' => 'newest', 'message' => 'c'],
            ['id' => 'middle', 'message' => 'b'],
            ['id' => 'oldest', 'message' => 'a'],
        ]);

        $recent = $this->history->recent(50);
        $this->assertSame(['newest', 'middle', 'oldest'], array_column($recent, 'id'));

        $this->history->clear();
        $this->assertSame([], $this->history->recent(50));
    }

    /**
     * cacheReply()/getReply() round-trip the minimal quote fields, and getReply()
     * returns null for an unknown message.
     */
    public function testCacheReplyRoundTrip(): void
    {
        $this->assertNull($this->history->getReply('nope'));

        $this->history->cacheReply('m1', [
            'username'     => 'alice',
            'display_name' => 'Alice',
            'message'      => 'hello there',
        ]);

        $this->assertSame(
            ['username' => 'alice', 'display_name' => 'Alice', 'message' => 'hello there'],
            $this->history->getReply('m1')
        );
    }

    /**
     * updateReplyMessage() updates the cached text of a known message and is a
     * no-op for an unknown one.
     */
    public function testUpdateReplyMessage(): void
    {
        $this->history->cacheReply('m1', ['username' => 'a', 'display_name' => 'A', 'message' => 'before']);

        $this->history->updateReplyMessage('m1', 'after');
        $this->assertSame('after', $this->history->getReply('m1')['message']);

        // No-op for unknown message.
        $this->history->updateReplyMessage('ghost', 'x');
        $this->assertNull($this->history->getReply('ghost'));
    }

    /**
     * markDeleted()/isDeleted() tombstone a message so the history load can filter
     * it out without a database round-trip.
     */
    public function testDeletedTombstones(): void
    {
        $this->assertFalse($this->history->isDeleted('m1'));

        $this->history->markDeleted('m1');
        $this->assertTrue($this->history->isDeleted('m1'));
        $this->assertFalse($this->history->isDeleted('m2'));
    }
}
