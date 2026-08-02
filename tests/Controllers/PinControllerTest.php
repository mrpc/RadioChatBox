<?php

namespace RadioChatBox\Tests\Controllers;

use PDO;
use PHPUnit\Framework\TestCase;
use Pramnos\Framework\Testing\TestDatabase;
use RadioChatBox\Controllers\PinController;
use RadioChatBox\Services\PinService;

/**
 * Tests for moderator-pinned messages: the PinService (pin/unpin, idempotent
 * re-pin, active filtering by expiry, purge) and the controller (public list +
 * admin pin/unpin with validation).
 */
class PinControllerTest extends TestCase
{
    private PDO $pdo;
    private string $mid;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::connection();
        $this->mid = 'msg_' . substr(bin2hex(random_bytes(6)), 0, 12);
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare("DELETE FROM pinned_messages WHERE message_id LIKE ?")->execute(['msg_%']);
        $_POST = [];
    }

    // ---- service ----------------------------------------------------

    /** A pinned message appears in active(); re-pinning the same id updates, not duplicates. */
    public function testPinIsIdempotentPerMessage(): void
    {
        $service = new PinService();
        $service->pin($this->mid, 'First text', 'alice', 'mod');
        $service->pin($this->mid, 'Updated text', 'alice', 'mod'); // re-pin same message

        $active = array_values(array_filter($service->active(50), fn ($p) => $p['message_id'] === $this->mid));
        $this->assertCount(1, $active, 'a message is pinned at most once');
        $this->assertSame('Updated text', $active[0]['content']);
    }

    /** An expired pin is excluded from active() and removed by purgeExpired(). */
    public function testExpiredPinsAreExcludedAndPurged(): void
    {
        // Insert a pin that already expired.
        $this->pdo->prepare(
            "INSERT INTO pinned_messages (message_id, content, pinned_by, created_at, expires_at)
             VALUES (?, 'old', 'mod', NOW() - INTERVAL '1 hour', NOW() - INTERVAL '1 minute')"
        )->execute([$this->mid]);

        $service = new PinService();
        $active = array_filter($service->active(50), fn ($p) => $p['message_id'] === $this->mid);
        $this->assertCount(0, $active, 'expired pins are not active');

        $this->assertGreaterThanOrEqual(1, $service->purgeExpired());
    }

    /** A future expiry keeps the pin active. */
    public function testFutureExpiryStaysActive(): void
    {
        $service = new PinService();
        $service->pin($this->mid, 'timed', 'bob', 'mod', 60); // expires in 60 min

        $active = array_filter($service->active(50), fn ($p) => $p['message_id'] === $this->mid);
        $this->assertCount(1, $active);
    }

    /** unpin() removes the pin. */
    public function testUnpinRemovesThePin(): void
    {
        $service = new PinService();
        $id = $service->pin($this->mid, 'to remove', null, 'mod');
        $this->assertGreaterThan(0, $id);

        $service->unpin($id);
        $active = array_filter($service->active(50), fn ($p) => $p['message_id'] === $this->mid);
        $this->assertCount(0, $active);
    }

    // ---- controller -------------------------------------------------

    /** The public list endpoint returns the active pins. */
    public function testPublicListReturnsActivePins(): void
    {
        (new PinService())->pin($this->mid, 'Pinned announcement', 'carol', 'mod');

        $response = (new PinController())->list();
        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success']);
        $ids = array_column($body['pins'], 'message_id');
        $this->assertContains($this->mid, $ids);
    }

    /** Admin pin then unpin through the controller. */
    public function testAdminPinAndUnpin(): void
    {
        $controller = new PinController();

        $_POST = ['message_id' => $this->mid, 'content' => 'Read the rules', 'username' => 'dave'];
        $pinned = $controller->pin();
        $this->assertSame(200, $pinned->getStatusCode());
        $id = json_decode($pinned->getBody(), true)['id'];
        $this->assertGreaterThan(0, $id);

        $_POST = ['id' => $id];
        $unpinned = $controller->unpin();
        $this->assertSame(200, $unpinned->getStatusCode());

        $active = array_filter((new PinService())->active(50), fn ($p) => $p['message_id'] === $this->mid);
        $this->assertCount(0, $active);
    }

    /** Pinning with no content is a 400. */
    public function testAdminPinRequiresContent(): void
    {
        $_POST = ['message_id' => $this->mid, 'content' => '   '];
        $response = (new PinController())->pin();
        $this->assertSame(400, $response->getStatusCode());
    }

    /** Unpin with neither id nor message_id is a 400. */
    public function testAdminUnpinRequiresAnIdentifier(): void
    {
        $_POST = [];
        $response = (new PinController())->unpin();
        $this->assertSame(400, $response->getStatusCode());
    }
}
