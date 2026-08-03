<?php

namespace RadioChatBox\Tests\Controllers;

use PDO;
use PHPUnit\Framework\TestCase;
use Pramnos\Framework\Testing\TestDatabase;
use RadioChatBox\Controllers\ShowController;

/**
 * Show schedule endpoints: admin create/list/update/delete and the public
 * upcoming feed. AdminAuthMiddleware only runs through the router, so calling the
 * actions directly exercises the handlers without auth.
 */
class ShowControllerTest extends TestCase
{
    private PDO $pdo;
    /** @var list<int> */
    private array $ids = [];

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::connection();
    }

    protected function tearDown(): void
    {
        foreach ($this->ids as $id) {
            $this->pdo->prepare('DELETE FROM shows WHERE id = ?')->execute([$id]);
        }
        $this->pdo->prepare("DELETE FROM shows WHERE title LIKE 'SCtl %'")->execute();
        $this->ids = [];
        $_POST = [];
        $_GET = [];
    }

    /** create validates the title. */
    public function testCreateValidates(): void
    {
        $_POST = ['title' => ''];
        $this->assertSame(400, (new ShowController())->create()->getStatusCode());
    }

    /** create → list → update → delete round-trip through the controller. */
    public function testCrudThroughController(): void
    {
        $_POST = ['title' => 'SCtl Weekly', 'is_recurring' => 'true', 'day_of_week' => '2', 'start_time' => '19:30', 'host' => 'DJ Z'];
        $createResp = (new ShowController())->create();
        $this->assertSame(200, $createResp->getStatusCode());
        $id = (int) json_decode($createResp->getBody(), true)['id'];
        $this->ids[] = $id;
        $this->assertGreaterThan(0, $id);

        // Admin list contains it.
        $listResp = (new ShowController())->adminList();
        $body = json_decode($listResp->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertContains($id, array_map(fn ($s) => (int) $s['id'], $body['shows']));

        // Update.
        $_POST = ['id' => $id, 'title' => 'SCtl Weekly Renamed', 'is_recurring' => 'true', 'day_of_week' => '2', 'start_time' => '20:00'];
        $this->assertSame(200, (new ShowController())->update()->getStatusCode());
        $title = $this->pdo->query('SELECT title FROM shows WHERE id = ' . $id)->fetchColumn();
        $this->assertSame('SCtl Weekly Renamed', $title);

        // Delete.
        $_POST = ['id' => $id];
        $this->assertSame(200, (new ShowController())->delete()->getStatusCode());
        $this->assertFalse((bool) $this->pdo->query('SELECT 1 FROM shows WHERE id = ' . $id)->fetchColumn());
    }

    /** update/delete need an id. */
    public function testUpdateAndDeleteNeedId(): void
    {
        $_POST = [];
        $this->assertSame(400, (new ShowController())->update()->getStatusCode());
        $this->assertSame(400, (new ShowController())->delete()->getStatusCode());
    }

    /** The iCal feed returns a valid VCALENDAR containing an upcoming show. */
    public function testIcalReturnsCalendar(): void
    {
        $_POST = ['title' => 'SCtl Ical Show', 'is_recurring' => 'true', 'day_of_week' => '4', 'start_time' => '18:00', 'host' => 'DJ Ical'];
        $id = (int) json_decode((new ShowController())->create()->getBody(), true)['id'];
        $this->ids[] = $id;

        $response = (new ShowController())->ical();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('text/calendar', (string) $response->getHeaderLine('Content-Type'));
        $body = (string) $response->getBody();
        $this->assertStringContainsString('BEGIN:VCALENDAR', $body);
        $this->assertStringContainsString('BEGIN:VEVENT', $body);
        $this->assertStringContainsString('SUMMARY:SCtl Ical Show', $body);
        $this->assertStringContainsString('END:VCALENDAR', $body);
    }

    /** subscribe requires a valid session; subscriptions lists the user's ids. */
    public function testSubscribeFlow(): void
    {
        // A session for the subscriber.
        $user = 'subctl_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $session = 'subsess_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $this->pdo->prepare(
            'INSERT INTO presence_sessions (username, session_id, ip_address, last_heartbeat, joined_at)
             VALUES (?, ?, ?, NOW(), NOW())'
        )->execute([$user, $session, '127.0.0.1']);

        $_POST = ['title' => 'SCtl Sub Show', 'is_recurring' => 'true', 'day_of_week' => '3', 'start_time' => '20:00'];
        $showId = (int) json_decode((new ShowController())->create()->getBody(), true)['id'];
        $this->ids[] = $showId;

        try {
            // Bad session → 403.
            $_POST = ['username' => $user, 'session_id' => 'nope', 'show_id' => $showId];
            $this->assertSame(403, (new ShowController())->subscribe()->getStatusCode());

            // Subscribe.
            $_POST = ['username' => $user, 'session_id' => $session, 'show_id' => $showId, 'subscribe' => 'true'];
            $resp = (new ShowController())->subscribe();
            $this->assertSame(200, $resp->getStatusCode());
            $this->assertTrue(json_decode($resp->getBody(), true)['subscribed']);

            // Listed.
            $_GET = ['username' => $user, 'session_id' => $session];
            $list = json_decode((new ShowController())->subscriptions()->getBody(), true);
            $this->assertContains($showId, $list['show_ids']);
        } finally {
            $this->pdo->prepare('DELETE FROM show_subscriptions WHERE show_id = ?')->execute([$showId]);
            $this->pdo->prepare('DELETE FROM presence_sessions WHERE username = ?')->execute([$user]);
        }
    }

    /** The public upcoming feed returns a success shape. */
    public function testUpcomingReturnsShape(): void
    {
        $_POST = ['title' => 'SCtl Upcoming', 'is_recurring' => 'true', 'day_of_week' => '5', 'start_time' => '21:00'];
        $id = (int) json_decode((new ShowController())->create()->getBody(), true)['id'];
        $this->ids[] = $id;

        $_GET = ['limit' => '10'];
        $resp = (new ShowController())->upcoming();
        $this->assertSame(200, $resp->getStatusCode());
        $body = json_decode($resp->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertIsArray($body['shows']);
    }
}
