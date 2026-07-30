<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use Pramnos\Framework\Testing\TestDatabase;
use Pramnos\Cache\FlatCache;
use RadioChatBox\Services\ChatService;

/**
 * Exercises the ChatService surface the moderation/cache tests do not reach:
 * the reply-quoting and pinned-track branches of postMessage, the combined
 * real+fake user listing, fake-user balancing, session removal and the two
 * history readers. Runs against the shared dev DB; everything created is
 * suffix-tagged and removed in tearDown.
 */
class ChatServiceExtraTest extends TestCase
{
    private ChatService $service;
    private string $suffix;
    private string $user;
    private string $session;
    private string $ip;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ChatService();
        $this->suffix  = substr(bin2hex(random_bytes(5)), 0, 10);
        $this->user    = 'u' . $this->suffix;
        $this->session = 'sess' . $this->suffix;
        $this->ip      = '203.0.113.' . random_int(2, 250);
    }

    protected function tearDown(): void
    {
        // Drop the short-lived combined user-list cache so it never leaks a stale
        // list into another test.
        try {
            FlatCache::default()->delete('chat:all_users');
        } catch (\Throwable) {
            // best effort
        }

        $pdo  = TestDatabase::connection();
        $like = '%' . $this->suffix . '%';
        $pdo->prepare('DELETE FROM messages WHERE message LIKE ? OR username LIKE ?')->execute([$like, $like]);
        $pdo->prepare('DELETE FROM private_messages WHERE from_username LIKE ? OR to_username LIKE ?')->execute([$like, $like]);
        $pdo->prepare('DELETE FROM sessions WHERE username LIKE ?')->execute([$like]);
        $pdo->prepare('DELETE FROM user_activity WHERE username LIKE ?')->execute([$like]);
        $pdo->prepare('DELETE FROM user_profiles WHERE username LIKE ?')->execute([$like]);
        parent::tearDown();
    }

    /**
     * registerUser with profile data (age/location/sex) also writes a
     * user_profiles row (the profile branch skipped by the bare registration).
     */
    public function testRegisterUserWithProfileStoresProfileRow(): void
    {
        $this->assertTrue(
            $this->service->registerUser($this->user, $this->session, $this->ip, '27', 'Athens', 'female')
        );

        $pdo  = TestDatabase::connection();
        $stmt = $pdo->prepare('SELECT age, location, sex FROM user_profiles WHERE username = ? AND session_id = ?');
        $stmt->execute([$this->user, $this->session]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('Athens', $row['location']);
        $this->assertSame('female', $row['sex']);
    }

    /**
     * The admin message listing/counter honour the private and combined ("all"
     * with private included) type filters, driving the private-only and UNION
     * branches of getAllMessages / getTotalMessagesCount.
     */
    public function testAdminListingPrivateAndCombinedBranches(): void
    {
        $this->service->registerUser($this->user, $this->session, $this->ip);
        // A public message and a private one, both suffix-tagged.
        $this->service->postMessage($this->user, 'pub ' . $this->suffix, $this->ip, $this->session);
        $pdo = TestDatabase::connection();
        $pdo->prepare(
            "INSERT INTO private_messages (from_username, from_session_id, to_username, to_session_id, message, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())"
        )->execute([$this->user, $this->session, 'peer' . $this->suffix, 'psess', 'priv ' . $this->suffix]);

        // Private-only branch.
        $priv = array_column($this->service->getAllMessages(200, 0, true, 'private'), 'message');
        $this->assertContains('priv ' . $this->suffix, $priv);
        $this->assertGreaterThanOrEqual(1, $this->service->getTotalMessagesCount(true, 'private'));

        // Combined (public UNION private) branch.
        $both = array_column($this->service->getAllMessages(500, 0, true, 'all'), 'message');
        $this->assertContains('pub ' . $this->suffix, $both);
        $this->assertContains('priv ' . $this->suffix, $both);
        $this->assertGreaterThanOrEqual(2, $this->service->getTotalMessagesCount(true, 'all'));
    }

    /**
     * A reply message carries a quote of the parent (username + truncated text),
     * driving getReplyMessageData; a pinned-track snapshot is stored on the row.
     */
    public function testReplyQuotingAndPinnedTrack(): void
    {
        $this->service->registerUser($this->user, $this->session, $this->ip);

        $parent = $this->service->postMessage($this->user, 'parent ' . $this->suffix, $this->ip, $this->session);
        $this->assertArrayHasKey('id', $parent);

        $reply = $this->service->postMessage(
            $this->user,
            'child ' . $this->suffix,
            $this->ip,
            $this->session,
            $parent['id'],
            'Artist ' . $this->suffix . ' - Song'
        );

        $this->assertArrayHasKey('reply_data', $reply);
        $this->assertSame($this->user, $reply['reply_data']['username']);
        $this->assertStringContainsString('parent ' . $this->suffix, $reply['reply_data']['message']);
        $this->assertSame('Artist ' . $this->suffix . ' - Song', $reply['pinned_track']);
    }

    /**
     * getAllUsers merges the active real users with the active fake users (the
     * latter flagged is_fake:true), and the second call is served from the
     * 30-second cache identically.
     */
    public function testGetAllUsersMergesRealAndFakeAndCaches(): void
    {
        $this->service->registerUser($this->user, $this->session, $this->ip);

        // An active fake user so the fake-user formatting branch runs.
        $fakeNick = 'fk' . $this->suffix;
        $fakeSvc  = new \RadioChatBox\Services\FakeUserService();
        $fake     = $fakeSvc->addFakeUser($fakeNick, 24, 'female', 'Athens');
        $fakeSvc->setFakeUserActive((int) $fake['id'], true);

        try {
            // Bust the 30s combined-list cache from any earlier call this run.
            FlatCache::default()->delete('chat:all_users');

            $all = $this->service->getAllUsers();
            $this->assertContains($this->user, array_column($all, 'username'));

            $fakeRow = null;
            foreach ($all as $u) {
                if (($u['username'] ?? null) === $fakeNick) {
                    $fakeRow = $u;
                    break;
                }
            }
            $this->assertNotNull($fakeRow, 'the active fake user must be merged in');
            $this->assertTrue($fakeRow['is_fake'], 'fake users are flagged is_fake');
            $this->assertSame('Athens', $fakeRow['location']);

            // Second call returns the cached value byte-for-byte.
            $this->assertSame($all, $this->service->getAllUsers());
        } finally {
            TestDatabase::connection()->prepare('DELETE FROM fake_users WHERE nickname = ?')->execute([$fakeNick]);
            FlatCache::default()->delete('chat:all_users');
        }
    }

    /**
     * balanceFakeUsers reads the live real-user count and delegates to the fake
     * user balancer without error (it publishes a user update as a side effect).
     */
    public function testBalanceFakeUsersRunsCleanly(): void
    {
        $this->service->registerUser($this->user, $this->session, $this->ip);

        $this->service->balanceFakeUsers();

        // Nothing to assert beyond "did not throw"; the count is environment-driven.
        $this->assertIsInt($this->service->getActiveUserCount());
    }

    /**
     * removeUser deletes the caller's session row and returns true; the user then
     * drops out of the active-users listing.
     */
    public function testRemoveUserDeletesSession(): void
    {
        $this->service->registerUser($this->user, $this->session, $this->ip);
        $this->assertContains($this->user, array_column($this->service->getActiveUsers(), 'username'));

        $this->assertTrue($this->service->removeUser($this->user, $this->session));

        $pdo  = TestDatabase::connection();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM sessions WHERE username = ? AND session_id = ?');
        $stmt->execute([$this->user, $this->session]);
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'the session row must be gone');
    }

    /**
     * Both history readers return the just-posted message: getHistory (recent
     * window) and getHistoryWithOffset (paged).
     */
    public function testHistoryReadersReturnPostedMessage(): void
    {
        $this->service->registerUser($this->user, $this->session, $this->ip);
        $parent = $this->service->postMessage($this->user, 'history ' . $this->suffix, $this->ip, $this->session);
        // A reply, so the history rows include one carrying reply_data (the reply
        // branch of both readers).
        $this->service->postMessage(
            $this->user,
            'reply ' . $this->suffix,
            $this->ip,
            $this->session,
            $parent['id']
        );

        $history = $this->service->getHistory(100);
        $this->assertContains('history ' . $this->suffix, array_column($history, 'message'));
        $this->assertReplyDataPresent($history, 'reply ' . $this->suffix);

        $paged = $this->service->getHistoryWithOffset(100, 0);
        $this->assertContains('history ' . $this->suffix, array_column($paged, 'message'));
        $this->assertReplyDataPresent($paged, 'reply ' . $this->suffix);
    }

    /** Assert the message with $text in $rows carries a quoted reply_data. */
    private function assertReplyDataPresent(array $rows, string $text): void
    {
        foreach ($rows as $row) {
            if (($row['message'] ?? null) === $text) {
                $this->assertArrayHasKey('reply_data', $row, 'a reply must carry reply_data');
                $this->assertSame($this->user, $row['reply_data']['username']);
                return;
            }
        }
        $this->fail("reply message '{$text}' not found in history");
    }
}
