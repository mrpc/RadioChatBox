<?php

namespace RadioChatBox\Tests\Controllers;

use PDO;
use PHPUnit\Framework\TestCase;
use Pramnos\Framework\Testing\TestDatabase;
use RadioChatBox\Controllers\PromoController;

/**
 * Admin promo-campaign CRUD endpoints. AdminAuthMiddleware only runs through the
 * router, so calling the actions directly exercises the handlers.
 */
class PromoControllerTest extends TestCase
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
            $this->pdo->prepare('DELETE FROM promo_campaigns WHERE id = ?')->execute([$id]);
        }
        $this->pdo->prepare("DELETE FROM promo_campaigns WHERE name LIKE 'PCtl %'")->execute();
        $this->ids = [];
        $_POST = [];
    }

    /** create → list → update → delete round-trip. */
    public function testCrud(): void
    {
        $_POST = ['name' => 'PCtl Promo', 'message' => 'Buy premium!', 'target' => 'public', 'interval_minutes' => '30'];
        $createResp = (new PromoController())->create();
        $this->assertSame(200, $createResp->getStatusCode());
        $id = (int) json_decode($createResp->getBody(), true)['id'];
        $this->ids[] = $id;
        $this->assertGreaterThan(0, $id);

        $list = json_decode((new PromoController())->list()->getBody(), true);
        $this->assertTrue($list['success']);
        $this->assertContains($id, array_map(fn ($p) => (int) $p['id'], $list['promos']));

        $_POST = ['id' => $id, 'name' => 'PCtl Promo Renamed', 'message' => 'Buy premium!', 'target' => 'dm', 'interval_minutes' => '120'];
        $this->assertSame(200, (new PromoController())->update()->getStatusCode());
        $row = $this->pdo->query('SELECT name, target FROM promo_campaigns WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('PCtl Promo Renamed', $row['name']);
        $this->assertSame('dm', $row['target']);

        $_POST = ['id' => $id];
        $this->assertSame(200, (new PromoController())->delete()->getStatusCode());
        $this->assertFalse((bool) $this->pdo->query('SELECT 1 FROM promo_campaigns WHERE id = ' . $id)->fetchColumn());
    }

    /** create validates required fields. */
    public function testCreateValidation(): void
    {
        $_POST = ['name' => '', 'message' => 'x'];
        $this->assertSame(400, (new PromoController())->create()->getStatusCode());
    }

    /** update/delete need an id. */
    public function testNeedId(): void
    {
        $_POST = [];
        $this->assertSame(400, (new PromoController())->update()->getStatusCode());
        $this->assertSame(400, (new PromoController())->delete()->getStatusCode());
    }
}
