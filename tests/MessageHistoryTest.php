<?php

namespace RadioChatBox\Tests;

use Pramnos\Cache\Adapter\ArrayAdapter;
use Pramnos\Cache\FlatCache;
use PHPUnit\Framework\TestCase;
use RadioChatBox\MessageHistory;

/**
 * Covers RadioChatBox\MessageHistory after Phase C moved it onto the framework
 * Cache capability (structured list/hash operations) instead of raw Redis.
 *
 * An in-memory ArrayAdapter-backed FlatCache is injected, so the domain
 * behaviour — newest-first list ordering, the bounded trim, the reply-quote
 * round-trip and edit, and the delete tombstone filter — is verified without a
 * live Redis server and independently of the backend.
 */
class MessageHistoryTest extends TestCase
{
    private MessageHistory $history;

    protected function setUp(): void
    {
        $cache = new FlatCache(new ArrayAdapter('mh_'), 'mh_');
        $this->history = new MessageHistory(5, $cache);
    }

    /**
     * append() prepends messages (newest first) and recent() returns them decoded
     * in that order.
     */
    public function testAppendAndRecentAreNewestFirst(): void
    {
        $this->history->append(['id' => 'm1', 'message' => 'first']);
        $this->history->append(['id' => 'm2', 'message' => 'second']);

        $recent = $this->history->recent(50);
        $this->assertCount(2, $recent);
        $this->assertSame('m2', $recent[0]['id'], 'newest first');
        $this->assertSame('m1', $recent[1]['id']);
    }

    /** append() trims to the configured history limit, dropping the oldest. */
    public function testAppendTrimsToHistoryLimit(): void
    {
        for ($i = 1; $i <= 8; $i++) {
            $this->history->append(['id' => 'm' . $i, 'message' => (string) $i]);
        }
        $recent = $this->history->recent(50);
        $this->assertCount(5, $recent, 'trimmed to the limit of 5');
        $this->assertSame('m8', $recent[0]['id']);
        $this->assertSame('m4', $recent[4]['id'], 'oldest kept is m4');
    }

    /** recent() is empty when nothing is cached (the DB-rebuild signal). */
    public function testRecentEmptyWhenNothingCached(): void
    {
        $this->assertSame([], $this->history->recent(50));
    }

    /** replace() rebuilds the list newest-first, and clear() empties it. */
    public function testReplaceRebuildsAndClearEmpties(): void
    {
        $this->history->append(['id' => 'stale', 'message' => 'old']);
        $this->history->replace([
            ['id' => 'newest', 'message' => 'c'],
            ['id' => 'middle', 'message' => 'b'],
            ['id' => 'oldest', 'message' => 'a'],
        ]);
        $this->assertSame(['newest', 'middle', 'oldest'], array_column($this->history->recent(50), 'id'));

        $this->history->clear();
        $this->assertSame([], $this->history->recent(50));
    }

    /** cacheReply()/getReply() round-trip the quote fields; null for unknown. */
    public function testCacheReplyRoundTrip(): void
    {
        $this->assertNull($this->history->getReply('nope'));
        $this->history->cacheReply('m1', ['username' => 'alice', 'display_name' => 'Alice', 'message' => 'hi']);
        $this->assertSame(
            ['username' => 'alice', 'display_name' => 'Alice', 'message' => 'hi'],
            $this->history->getReply('m1')
        );
    }

    /** updateReplyMessage() updates a known message's text; no-op for unknown. */
    public function testUpdateReplyMessage(): void
    {
        $this->history->cacheReply('m1', ['username' => 'a', 'display_name' => 'A', 'message' => 'before']);
        $this->history->updateReplyMessage('m1', 'after');
        $this->assertSame('after', $this->history->getReply('m1')['message']);

        $this->history->updateReplyMessage('ghost', 'x');
        $this->assertNull($this->history->getReply('ghost'));
    }

    /** markDeleted()/isDeleted() tombstone a message for history filtering. */
    public function testDeletedTombstones(): void
    {
        $this->assertFalse($this->history->isDeleted('m1'));
        $this->history->markDeleted('m1');
        $this->assertTrue($this->history->isDeleted('m1'));
        $this->assertFalse($this->history->isDeleted('m2'));
    }
}
