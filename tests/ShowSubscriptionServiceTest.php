<?php

namespace RadioChatBox\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\FlatCache;
use Pramnos\Framework\Testing\TestDatabase;
use RadioChatBox\Services\NotificationService;
use RadioChatBox\Services\ShowService;
use RadioChatBox\Services\ShowSubscriptionService;

/**
 * Show reminders: subscribe/unsubscribe round-trip, and sendDueReminders notifies
 * subscribers of a show airing within the lead window (once per occurrence),
 * skipping shows that are further out.
 */
class ShowSubscriptionServiceTest extends TestCase
{
    private PDO $pdo;
    private string $user;
    /** @var list<int> */
    private array $showIds = [];
    private \DateTimeZone $tz;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::connection();
        $this->user = 'sub_' . substr(bin2hex(random_bytes(5)), 0, 8);
        $this->tz = new \DateTimeZone(getenv('TZ') ?: 'Europe/Athens');
        FlatCache::default()->clear();
    }

    protected function tearDown(): void
    {
        foreach ($this->showIds as $id) {
            $this->pdo->prepare('DELETE FROM show_subscriptions WHERE show_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM shows WHERE id = ?')->execute([$id]);
        }
        $this->pdo->prepare('DELETE FROM user_notifications WHERE username = ?')->execute([$this->user]);
        $this->showIds = [];
        FlatCache::default()->clear();
    }

    /** A one-off show at a given local datetime. */
    private function oneOffShow(string $date, string $time): int
    {
        $id = (new ShowService())->create([
            'title' => 'SubTest Show', 'is_recurring' => false,
            'show_date' => $date, 'start_time' => $time, 'host' => 'DJ Sub',
        ]);
        $this->showIds[] = $id;
        return $id;
    }

    /** subscribe then unsubscribe toggles the stored state. */
    public function testSubscribeUnsubscribe(): void
    {
        $id = $this->oneOffShow('2026-09-01', '20:00');
        $svc = new ShowSubscriptionService();

        $this->assertTrue($svc->setSubscribed($this->user, $id, true));
        $this->assertContains($id, $svc->subscribedShowIds($this->user));

        $this->assertFalse($svc->setSubscribed($this->user, $id, false));
        $this->assertNotContains($id, $svc->subscribedShowIds($this->user));
    }

    /** A subscriber is reminded when the show is within the lead window. */
    public function testReminderFiresWithinLeadWindow(): void
    {
        // Show airs at a fixed instant; "now" is 10 minutes before it.
        $start = new \DateTimeImmutable('2026-09-02 21:00:00', $this->tz);
        $id = $this->oneOffShow('2026-09-02', '21:00');
        $svc = new ShowSubscriptionService();
        $svc->setSubscribed($this->user, $id, true);

        $now = $start->modify('-10 minutes');
        $sent = $svc->sendDueReminders($now);
        $this->assertGreaterThanOrEqual(1, $sent);

        $notifs = (new NotificationService())->listFor($this->user);
        $this->assertNotEmpty($notifs);
        $this->assertSame('show', $notifs[0]['type']);

        // A second run for the same occurrence does not double-notify.
        $again = $svc->sendDueReminders($now);
        $this->assertSame(0, $again);
    }

    /** A show still far out (beyond the lead window) is not reminded. */
    public function testNoReminderWhenFarOut(): void
    {
        $start = new \DateTimeImmutable('2026-09-03 21:00:00', $this->tz);
        $id = $this->oneOffShow('2026-09-03', '21:00');
        $svc = new ShowSubscriptionService();
        $svc->setSubscribed($this->user, $id, true);

        $now = $start->modify('-2 hours');
        $this->assertSame(0, $svc->sendDueReminders($now));
    }
}
