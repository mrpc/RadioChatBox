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
        $pdo->prepare('DELETE FROM chat_messages WHERE message LIKE ? OR username LIKE ?')->execute([$like, $like]);
        $pdo->prepare('DELETE FROM private_messages WHERE from_username LIKE ? OR to_username LIKE ?')->execute([$like, $like]);
        $pdo->prepare('DELETE FROM presence_sessions WHERE username LIKE ?')->execute([$like]);
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
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM presence_sessions WHERE username = ? AND session_id = ?');
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

    /**
     * When the Redis reply hash misses, getReplyMessageData falls back to the
     * database: post a parent, wipe the message hash, then post a reply — the
     * quote is still resolved (from the DB) and re-cached.
     */
    public function testReplyDataFallsBackToDatabaseOnCacheMiss(): void
    {
        $this->service->registerUser($this->user, $this->session, $this->ip);
        $parent = $this->service->postMessage($this->user, 'parent-db ' . $this->suffix, $this->ip, $this->session);

        // Wipe the Redis reply hash so the O(1) lookup misses and the DB path runs.
        (new \RadioChatBox\MessageHistory())->clear();

        $reply = $this->service->postMessage(
            $this->user,
            'child-db ' . $this->suffix,
            $this->ip,
            $this->session,
            $parent['id']
        );

        $this->assertArrayHasKey('reply_data', $reply);
        $this->assertSame($this->user, $reply['reply_data']['username']);
        $this->assertStringContainsString('parent-db ' . $this->suffix, $reply['reply_data']['message']);
    }

    /**
     * isNicknameAvailable across all namespaces: an unused nickname is free; a
     * registered username is free only to a session authenticated as that user
     * (denied otherwise / with no session); a fake-user nickname is denied to
     * guests; and a guest nickname held by another live session is denied but free
     * to the session that already holds it.
     */
    public function testIsNicknameAvailableBranches(): void
    {
        $pdo = TestDatabase::connection();

        // 1) Unused nickname → available.
        $this->assertTrue($this->service->isNicknameAvailable('free' . $this->suffix, 'anysess'));

        // 2) Registered username.
        $regUser = 'reg' . $this->suffix;
        $created = (new \RadioChatBox\Services\UserService())->createUser($regUser, 'testpass123', 'simple_user');
        $userId  = (int) $created['user']['userid'];
        $fakeNick = 'fk' . $this->suffix;
        try {
            $this->assertFalse($this->service->isNicknameAvailable($regUser, ''), 'registered name needs a session');
            $this->assertFalse($this->service->isNicknameAvailable($regUser, 'wrongsess'), 'wrong session is denied');

            // A session authenticated as that registered user → available.
            $authSess = 'auth' . $this->suffix;
            $pdo->prepare(
                'INSERT INTO presence_sessions (username, session_id, ip_address, user_id, last_heartbeat, joined_at)
                 VALUES (?, ?, ?, ?, NOW(), NOW())'
            )->execute([$regUser, $authSess, $this->ip, $userId]);
            $this->assertTrue($this->service->isNicknameAvailable($regUser, $authSess), 'own authenticated session is allowed');

            // 3) Fake user nickname → denied to guests.
            $pdo->prepare('INSERT INTO fake_users (nickname, is_active) VALUES (?, TRUE)')->execute([$fakeNick]);
            $this->assertFalse($this->service->isNicknameAvailable($fakeNick, 'sess'));

            // 4) Guest nickname held by another live session → denied; same session → free.
            $this->service->registerUser($this->user, $this->session, $this->ip);
            $this->assertFalse($this->service->isNicknameAvailable($this->user, 'someone_else'));
            $this->assertTrue($this->service->isNicknameAvailable($this->user, $this->session));
        } finally {
            $pdo->prepare('DELETE FROM presence_sessions WHERE username = ?')->execute([$regUser]);
            $pdo->prepare('DELETE FROM users WHERE userid = ?')->execute([$userId]);
            $pdo->prepare('DELETE FROM fake_users WHERE nickname = ?')->execute([$fakeNick]);
        }
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

    /**
     * A backgrounded phone is still someone in the chat.
     *
     * Mobile freezes the page, so the 60s heartbeat stops and the old five-minute
     * rule deleted the session — the reader vanished from presence while still
     * very much there, and peak concurrency under-reported a mobile audience.
     * The client now announces the absence (sendBeacon → state=away) and the two
     * questions are answered separately: OUT of the user list, so no ghosts, but
     * still counted as present.
     */
    public function testAwaySessionLeavesTheUserListButStillCountsAsPresent(): void
    {
        $pdo = TestDatabase::connection();

        // Two people: one beating normally, one that just backgrounded.
        $this->service->refreshPresence($this->user, $this->session, $this->ip);
        $away = 'away' . $this->suffix;
        $this->service->refreshPresence($away, 'sess_away' . $this->suffix, $this->ip, null, '', ChatService::PRESENCE_AWAY);

        $stmt = $pdo->prepare('SELECT state FROM presence_sessions WHERE username = ?');
        $stmt->execute([$away]);
        $this->assertSame('away', $stmt->fetchColumn(), 'the beacon is recorded as a state, not a deletion');

        $listed = array_column($this->service->getActiveUsers(), 'username');
        $this->assertContains($this->user, $listed);
        $this->assertNotContains($away, $listed, 'an away session must never show up as online');

        // ...but it is still present for the purpose of counting concurrency.
        $counted = (int) $pdo->query(
            "SELECT COUNT(DISTINCT username) FROM presence_sessions
             WHERE (COALESCE(state, 'active') <> 'away' AND last_heartbeat > NOW() - INTERVAL '5 minutes')
                OR (state = 'away' AND last_heartbeat > NOW() - INTERVAL '30 minutes')"
        )->fetchColumn();
        $this->assertGreaterThanOrEqual(2, $counted, 'both sessions count toward concurrency');
    }

    /**
     * Coming back clears the flag: any presence refresh other than the beacon is
     * proof the user is here, so they reappear in the list without waiting for a
     * state to be reset explicitly.
     */
    public function testReturningFromAwayPutsTheUserBackInTheList(): void
    {
        $this->service->refreshPresence($this->user, $this->session, $this->ip, null, '', ChatService::PRESENCE_AWAY);
        $this->assertNotContains($this->user, array_column($this->service->getActiveUsers(), 'username'));

        $this->service->refreshPresence($this->user, $this->session, $this->ip);

        $this->assertContains($this->user, array_column($this->service->getActiveUsers(), 'username'));
    }

    /**
     * The two expiries: silence still means gone after five minutes, an announced
     * absence gets half an hour. Both are enforced by cleanup_inactive_sessions()
     * in one pass, so a stale away row cannot linger forever either.
     */
    public function testCleanupKeepsAnAwaySessionButDropsASilentOne(): void
    {
        $pdo = TestDatabase::connection();

        $silent = 'silent' . $this->suffix;
        $away   = 'away' . $this->suffix;
        $stale  = 'stale' . $this->suffix;

        $insert = $pdo->prepare(
            'INSERT INTO presence_sessions (username, session_id, ip_address, last_heartbeat, joined_at, state)
             VALUES (?, ?, ?, NOW() - (? || \' minutes\')::interval, NOW(), ?)'
        );
        $insert->execute([$silent, 's1' . $this->suffix, $this->ip, 10, 'active']);
        $insert->execute([$away, 's2' . $this->suffix, $this->ip, 10, 'away']);
        $insert->execute([$stale, 's3' . $this->suffix, $this->ip, 45, 'away']);

        $pdo->query('SELECT cleanup_inactive_sessions()');

        $survivors = $pdo->prepare(
            'SELECT username FROM presence_sessions WHERE username IN (?, ?, ?)'
        );
        $survivors->execute([$silent, $away, $stale]);
        $left = $survivors->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertNotContains($silent, $left, 'silent for 10 minutes → gone');
        $this->assertContains($away, $left, 'away for 10 minutes → still here');
        $this->assertNotContains($stale, $left, 'away for 45 minutes → expired too');
    }
}
