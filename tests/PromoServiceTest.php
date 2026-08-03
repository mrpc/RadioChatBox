<?php

namespace RadioChatBox\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\FlatCache;
use Pramnos\Framework\Testing\TestDatabase;
use RadioChatBox\Services\PromoService;

/**
 * Promo campaigns: due-scheduling (interval + active-hours window), public
 * delivery posts as a fake user, and DM delivery respects the per-run cap and the
 * per-user cooldown.
 */
class PromoServiceTest extends TestCase
{
    private PDO $pdo;
    private PromoService $service;
    private string $bot;
    private int $botId;
    /** @var list<int> */
    private array $promoIds = [];
    /** @var list<string> */
    private array $peers = [];
    private \DateTimeZone $tz;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::connection();
        $this->service = new PromoService();
        $this->tz = new \DateTimeZone(getenv('TZ') ?: 'Europe/Athens');
        FlatCache::default()->clear();

        // An active fake user to send promos as.
        $this->bot = 'promobot_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $this->pdo->prepare('INSERT INTO fake_users (nickname, is_active, bot_enabled) VALUES (?, TRUE, TRUE)')->execute([$this->bot]);
        $this->botId = (int) $this->pdo->query('SELECT id FROM fake_users WHERE nickname = ' . $this->pdo->quote($this->bot))->fetchColumn();
    }

    protected function tearDown(): void
    {
        foreach ($this->promoIds as $id) {
            $this->pdo->prepare('DELETE FROM promo_campaigns WHERE id = ?')->execute([$id]);
        }
        foreach ($this->peers as $p) {
            $this->pdo->prepare('DELETE FROM presence_sessions WHERE username = ?')->execute([$p]);
            $this->pdo->prepare('DELETE FROM private_messages WHERE to_username = ?')->execute([$p]);
        }
        $this->pdo->prepare('DELETE FROM chat_messages WHERE username = ?')->execute([$this->bot]);
        $this->pdo->prepare('DELETE FROM private_messages WHERE from_username = ?')->execute([$this->bot]);
        $this->pdo->prepare('DELETE FROM fake_users WHERE id = ?')->execute([$this->botId]);
        FlatCache::default()->clear();
    }

    private function makeCampaign(array $over = []): array
    {
        $id = $this->service->create(array_merge([
            'name' => 'Promo ' . substr(bin2hex(random_bytes(3)), 0, 6),
            'message' => 'Try our premium plan!',
            'target' => 'public',
            'fake_user_id' => $this->botId,
            'interval_minutes' => 60,
        ], $over));
        $this->promoIds[] = $id;
        return $this->pdo->query('SELECT * FROM promo_campaigns WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
    }

    private function onlinePeer(): string
    {
        $u = 'peer_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $this->pdo->prepare(
            'INSERT INTO presence_sessions (username, session_id, ip_address, last_heartbeat, joined_at)
             VALUES (?, ?, ?, NOW(), NOW())'
        )->execute([$u, 'sess_' . $u, '127.0.0.1']);
        $this->peers[] = $u;
        return $u;
    }

    /** A never-run campaign is due; one that just ran is not until its interval. */
    public function testDueScheduling(): void
    {
        $c = $this->makeCampaign(['interval_minutes' => 30]);
        $now = new \DateTimeImmutable('now');
        $due = array_column($this->service->dueCampaigns($now), 'id');
        $this->assertContains((int) $c['id'], array_map('intval', $due));

        // Mark it just-run → no longer due.
        $this->pdo->prepare('UPDATE promo_campaigns SET last_run_at = NOW() WHERE id = ?')->execute([(int) $c['id']]);
        $due2 = array_map('intval', array_column($this->service->dueCampaigns($now), 'id'));
        $this->assertNotContains((int) $c['id'], $due2);
    }

    /** Outside the active-hours window, a campaign is not due. */
    public function testWindowGating(): void
    {
        // A window that does not include "now".
        $now = new \DateTimeImmutable('2026-08-03 12:00:00', $this->tz);
        $c = $this->makeCampaign(['window_start' => '01:00', 'window_end' => '02:00']);
        $due = array_map('intval', array_column($this->service->dueCampaigns($now), 'id'));
        $this->assertNotContains((int) $c['id'], $due);
    }

    /** Public run posts the message into the chat as the fake user. */
    public function testRunPublicPosts(): void
    {
        $c = $this->makeCampaign(['target' => 'public', 'message' => 'PROMO_PUBLIC_MARKER']);
        $this->assertSame(1, $this->service->runPublic($c));
        $count = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM chat_messages WHERE username = " . $this->pdo->quote($this->bot)
            . " AND message = 'PROMO_PUBLIC_MARKER'"
        )->fetchColumn();
        $this->assertSame(1, $count);
    }

    /** DM run sends to online peers up to the cap and respects the cooldown. */
    public function testRunDmCapAndCooldown(): void
    {
        $p1 = $this->onlinePeer();
        $this->onlinePeer();
        $this->onlinePeer();
        $c = $this->makeCampaign(['target' => 'dm', 'max_recipients' => 2, 'cooldown_hours' => 24, 'message' => 'PROMO_DM_MARKER']);
        $now = new \DateTimeImmutable('now');

        $sent = $this->service->runDm($c, $now);
        $this->assertSame(2, $sent, 'capped at max_recipients');

        // A second run right away sends only to peers not yet contacted (cooldown).
        $sentAgain = $this->service->runDm($c, $now);
        $this->assertSame(1, $sentAgain, 'the 3rd peer, the first two are on cooldown');

        // A third run: everyone is now on cooldown.
        $this->assertSame(0, $this->service->runDm($c, $now));
    }

    /** create() validates name + message. */
    public function testCreateValidates(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->create(['name' => '', 'message' => 'x']);
    }
}
