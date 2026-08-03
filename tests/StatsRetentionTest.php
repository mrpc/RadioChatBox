<?php

namespace RadioChatBox\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Pramnos\Framework\Testing\TestDatabase;
use RadioChatBox\Services\StatsService;

/**
 * Active-user retention metrics: DAU/WAU/MAU count DISTINCT usernames that posted
 * within the last 1/7/30 days, and a recent poster is reflected across all three.
 */
class StatsRetentionTest extends TestCase
{
    private PDO $pdo;
    /** @var list<string> */
    private array $ids = [];
    private string $user;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::connection();
        $this->user = 'ret_' . substr(bin2hex(random_bytes(5)), 0, 8);
    }

    protected function tearDown(): void
    {
        foreach ($this->ids as $mid) {
            $this->pdo->prepare('DELETE FROM chat_messages WHERE message_id = ?')->execute([$mid]);
        }
        $this->ids = [];
    }

    private function post(string $ago): void
    {
        $mid = 'retmsg_' . bin2hex(random_bytes(6));
        $this->pdo->prepare(
            "INSERT INTO chat_messages (message_id, username, message, ip_address, created_at)
             VALUES (?, ?, ?, ?, NOW() - INTERVAL '{$ago}')"
        )->execute([$mid, $this->user, 'hi', '127.0.0.1']);
        $this->ids[] = $mid;
    }

    /** A message posted just now counts toward DAU, WAU and MAU. */
    public function testRecentPosterCountsEverywhere(): void
    {
        $before = (new StatsService())->activeUserCounts();
        $this->post('1 hour');
        $after = (new StatsService())->activeUserCounts();

        $this->assertSame($before['dau'] + 1, $after['dau']);
        $this->assertSame($before['wau'] + 1, $after['wau']);
        $this->assertSame($before['mau'] + 1, $after['mau']);
    }

    /** A poster from 10 days ago is in MAU/WAU windows but not DAU. */
    public function testOlderPosterOnlyInWiderWindows(): void
    {
        $before = (new StatsService())->activeUserCounts();
        $this->post('10 days');
        $after = (new StatsService())->activeUserCounts();

        $this->assertSame($before['dau'], $after['dau'], 'not a daily-active');
        $this->assertSame($before['mau'] + 1, $after['mau'], 'counts as monthly-active');
    }

    /** The shape includes a stickiness percentage. */
    public function testShape(): void
    {
        $r = (new StatsService())->activeUserCounts();
        $this->assertArrayHasKey('stickiness', $r);
        $this->assertIsInt($r['stickiness']);
    }
}
