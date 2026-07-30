<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use Pramnos\Framework\Testing\TestDatabase;
use RadioChatBox\Services\FakeUserService;

/**
 * Covers the FakeUserService CRUD lifecycle (add → read → update → bot settings →
 * toggle → delete), which the export/import/balance tests do not exercise. The
 * fake user is tagged with a per-run suffix and removed in tearDown.
 */
class FakeUserServiceCrudTest extends TestCase
{
    private FakeUserService $service;
    private string $nick;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FakeUserService();
        $this->nick = 'fu' . substr(bin2hex(random_bytes(5)), 0, 8);
    }

    protected function tearDown(): void
    {
        TestDatabase::connection()
            ->prepare('DELETE FROM fake_users WHERE nickname LIKE ?')
            ->execute(['%' . substr($this->nick, 2) . '%']);
        parent::tearDown();
    }

    /** The full add → read → update → bot-settings → toggle → delete lifecycle. */
    public function testFakeUserLifecycle(): void
    {
        $add = $this->service->addFakeUser($this->nick, 25, 'female', 'NYC');
        $this->assertIsArray($add);

        $row = $this->service->getFakeUserByNickname($this->nick);
        $this->assertNotNull($row);
        $id = (int) $row['id'];

        $this->assertNotNull($this->service->getFakeUserById($id));
        $this->assertContains($this->nick, array_column($this->service->getAllFakeUsers(), 'nickname'));
        $this->assertIsArray($this->service->getActiveFakeUsers());

        $this->assertNotNull($this->service->updateFakeUser($id, ['age' => 30, 'location' => 'LA']));
        $this->assertNotNull($this->service->updateBotSettings($id, [
            'bot_enabled'     => true,
            'bot_persona'     => 'friendly',
            'bot_max_messages' => 4,
        ]));

        $this->assertIsArray($this->service->toggleFakeUser($id));
        $this->assertTrue($this->service->setFakeUserActive($id, true));
        $this->assertIsArray($this->service->exportFakeUsers());

        $this->assertTrue($this->service->deleteFakeUser($id));
        $this->assertNull($this->service->getFakeUserById($id));
    }
}
