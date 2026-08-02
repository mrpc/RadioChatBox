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
