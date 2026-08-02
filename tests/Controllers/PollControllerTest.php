<?php

namespace RadioChatBox\Tests\Controllers;

use PDO;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\FlatCache;
use Pramnos\Framework\Testing\TestDatabase;
use RadioChatBox\Controllers\PollController;
use RadioChatBox\Services\PollService;
use RadioChatBox\Services\SettingsService;

/**
 * Tests for live chat polls: the PollService (create closes the previous active
 * poll, vote upsert/change, results counts, expiry) and the controller (feature
 * gate, session verification, closed-poll 409, admin create/close).
 */
class PollControllerTest extends TestCase
{
    private PDO $pdo;
    private string $user;
    private string $session;
    /** @var list<int> */
    private array $pollIds = [];

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::connection();
        $suffix = substr(bin2hex(random_bytes(5)), 0, 8);
        $this->user = 'poll_' . $suffix;
        $this->session = 'psess_' . $suffix;

        $this->pdo->prepare(
            'INSERT INTO presence_sessions (username, session_id, ip_address, last_heartbeat, joined_at)
             VALUES (?, ?, ?, NOW(), NOW())'
        )->execute([$this->user, $this->session, '127.0.0.1']);
    }

    protected function tearDown(): void
    {
        // Clean the polls this test created and their votes.
        foreach ($this->pollIds as $id) {
            $this->pdo->prepare('DELETE FROM poll_votes WHERE poll_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM polls WHERE id = ?')->execute([$id]);
        }
        $this->pdo->prepare('DELETE FROM presence_sessions WHERE username = ?')->execute([$this->user]);
        $this->pdo->prepare("DELETE FROM settings WHERE setting = 'polls_enabled'")->execute();
        FlatCache::default()->clear();
        $_POST = [];
        $_GET = [];
    }

    private function enable(): void
    {
        (new SettingsService())->set('polls_enabled', 'true');
    }

    private function makePoll(string $q = 'Best genre?', array $opts = ['Rock', 'Metal', 'Jazz']): int
    {
        $id = (new PollService())->create($q, $opts, 'mod');
        $this->pollIds[] = $id;
        return $id;
    }

    // ---- service ----------------------------------------------------

    /** Creating a poll closes any previously-active one (one active at a time). */
    public function testCreateClosesThePreviousActivePoll(): void
    {
        $first = $this->makePoll('First?');
        $second = $this->makePoll('Second?');

        $service = new PollService();
        $this->assertFalse($service->results($first)['is_active']);
        $this->assertTrue($service->results($second)['is_active']);
        $this->assertSame($second, $service->activeResults()['id']);
    }

    /** A vote is counted; changing it moves the count, never doubles it. */
    public function testVoteAndChange(): void
    {
        $id = $this->makePoll();
        $service = new PollService();

        $r1 = $service->vote($id, $this->session, $this->user, 0);
        $this->assertSame(1, $r1['total']);
        $this->assertSame(1, $r1['counts'][0]);
        $this->assertSame(0, $r1['my_vote']);

        $r2 = $service->vote($id, $this->session, $this->user, 2); // change vote
        $this->assertSame(1, $r2['total'], 'still one vote, just moved');
        $this->assertSame(0, $r2['counts'][0]);
        $this->assertSame(1, $r2['counts'][2]);
        $this->assertSame(2, $r2['my_vote']);
    }

    /** Too few / too many options are rejected. */
    public function testCreateValidatesOptionCount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PollService())->create('bad', ['only one']);
    }

    /** Voting on a closed poll throws. */
    public function testVoteOnClosedPollThrows(): void
    {
        $id = $this->makePoll();
        (new PollService())->close($id);

        $this->expectException(\RuntimeException::class);
        (new PollService())->vote($id, $this->session, $this->user, 0);
    }

    // ---- controller -------------------------------------------------

    /** Off by default -> 404 on the public endpoints. */
    public function testDisabledReturns404(): void
    {
        $this->assertSame(404, (new PollController())->active()->getStatusCode());
    }

    /** The active endpoint returns the current poll with results. */
    public function testActiveReturnsTheCurrentPoll(): void
    {
        $this->enable();
        $id = $this->makePoll('What now?');

        $_GET = ['session_id' => $this->session];
        $response = (new PollController())->active();
        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertSame($id, $body['poll']['id']);
        $this->assertSame('What now?', $body['poll']['question']);
    }

    /** Voting through the controller requires a verified session. */
    public function testVoteRejectsInvalidSession(): void
    {
        $this->enable();
        $id = $this->makePoll();
        $_POST = ['poll_id' => $id, 'option_index' => 0, 'username' => $this->user, 'session_id' => 'not-mine'];

        $this->assertSame(403, (new PollController())->vote()->getStatusCode());
    }

    /** The happy path: cast a vote and get fresh results. */
    public function testVoteHappyPath(): void
    {
        $this->enable();
        $id = $this->makePoll();
        $_POST = ['poll_id' => $id, 'option_index' => 1, 'username' => $this->user, 'session_id' => $this->session];

        $response = (new PollController())->vote();
        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame(1, $body['poll']['counts'][1]);
        $this->assertSame(1, $body['poll']['my_vote']);
    }

    /** Voting a closed poll is a 409 through the controller. */
    public function testVoteClosedPollIs409(): void
    {
        $this->enable();
        $id = $this->makePoll();
        (new PollService())->close($id);
        $_POST = ['poll_id' => $id, 'option_index' => 0, 'username' => $this->user, 'session_id' => $this->session];

        $this->assertSame(409, (new PollController())->vote()->getStatusCode());
    }

    /** Admin create validates and returns the new poll. */
    public function testAdminCreate(): void
    {
        $_POST = ['question' => 'Coffee or tea?', 'options' => ['Coffee', 'Tea']];
        $response = (new PollController())->create();
        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->pollIds[] = (int) $body['poll']['id'];
        $this->assertSame('Coffee or tea?', $body['poll']['question']);

        // Bad option count -> 400.
        $_POST = ['question' => 'x', 'options' => ['one']];
        $this->assertSame(400, (new PollController())->create()->getStatusCode());
    }
}
