<?php

namespace RadioChatBox\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Pramnos\Framework\Testing\TestDatabase;
use RadioChatBox\Services\ShowService;

/**
 * Radio show schedule: create/update/delete, the admin listing, and the
 * `upcoming()` projection of recurring (weekly) and one-off shows from a fixed
 * "now" (so the recurrence maths is deterministic).
 */
class ShowServiceTest extends TestCase
{
    private PDO $pdo;
    private ShowService $service;
    /** @var list<int> */
    private array $ids = [];

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::connection();
        $this->service = new ShowService();
    }

    protected function tearDown(): void
    {
        foreach ($this->ids as $id) {
            $this->pdo->prepare('DELETE FROM shows WHERE id = ?')->execute([$id]);
        }
        // Clean any strays created by the test (by title prefix).
        $this->pdo->prepare("DELETE FROM shows WHERE title LIKE 'ShowTest %'")->execute();
        $this->ids = [];
    }

    private function make(array $data): int
    {
        $id = $this->service->create($data);
        $this->ids[] = $id;
        return $id;
    }

    /** create validates the title and (for one-off) the date. */
    public function testCreateValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->create(['title' => '']);
    }

    /** A one-off show without a date is rejected. */
    public function testOneOffRequiresDate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->create(['title' => 'ShowTest x', 'is_recurring' => false]);
    }

    /** create + update + delete round-trip. */
    public function testCrudRoundTrip(): void
    {
        $id = $this->make([
            'title' => 'ShowTest Morning', 'host' => 'DJ A',
            'is_recurring' => true, 'day_of_week' => 1, 'start_time' => '09:00',
        ]);
        $this->assertGreaterThan(0, $id);

        $this->assertTrue($this->service->update($id, [
            'title' => 'ShowTest Morning Renamed', 'is_recurring' => true,
            'day_of_week' => 1, 'start_time' => '10:00',
        ]));
        $row = $this->pdo->query('SELECT title, start_time FROM shows WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('ShowTest Morning Renamed', $row['title']);
        $this->assertStringStartsWith('10:00', $row['start_time']);

        $this->assertTrue($this->service->delete($id));
        $this->assertFalse((bool) $this->pdo->query('SELECT 1 FROM shows WHERE id = ' . $id)->fetchColumn());
    }

    /** A weekly show projects to its next matching weekday at/after now. */
    public function testUpcomingProjectsRecurring(): void
    {
        // A Wednesday (w=3) show at 20:00.
        $id = $this->make([
            'title' => 'ShowTest Weekly', 'is_recurring' => true,
            'day_of_week' => 3, 'start_time' => '20:00',
        ]);

        // "now" is Monday 2026-08-03 10:00 (w=1); next Wednesday is 2026-08-05.
        $now = new \DateTimeImmutable('2026-08-03 10:00:00', new \DateTimeZone(getenv('TZ') ?: 'Europe/Athens'));
        $upcoming = $this->service->upcoming($now, 20);

        $mine = null;
        foreach ($upcoming as $s) {
            if ((int) $s['id'] === $id) { $mine = $s; break; }
        }
        $this->assertNotNull($mine);
        $this->assertStringStartsWith('2026-08-05', $mine['next_start']);
    }

    /** A past one-off show is excluded; a future one is included and ordered. */
    public function testUpcomingHandlesOneOff(): void
    {
        $past = $this->make([
            'title' => 'ShowTest Past', 'is_recurring' => false,
            'show_date' => '2026-08-01', 'start_time' => '18:00',
        ]);
        $future = $this->make([
            'title' => 'ShowTest Future', 'is_recurring' => false,
            'show_date' => '2026-08-04', 'start_time' => '18:00',
        ]);

        $now = new \DateTimeImmutable('2026-08-03 10:00:00', new \DateTimeZone(getenv('TZ') ?: 'Europe/Athens'));
        $ids = array_map(fn ($s) => (int) $s['id'], $this->service->upcoming($now, 50));

        $this->assertTrue(in_array($future, $ids, true), 'future one-off is listed');
        $this->assertFalse(in_array($past, $ids, true), 'past one-off is excluded');
    }

    /** Inactive shows never appear in upcoming(). */
    public function testUpcomingExcludesInactive(): void
    {
        $id = $this->make([
            'title' => 'ShowTest Inactive', 'is_recurring' => true,
            'day_of_week' => 3, 'start_time' => '20:00', 'is_active' => false,
        ]);
        $now = new \DateTimeImmutable('2026-08-03 10:00:00', new \DateTimeZone(getenv('TZ') ?: 'Europe/Athens'));
        $ids = array_map(fn ($s) => (int) $s['id'], $this->service->upcoming($now, 50));
        $this->assertNotContains($id, $ids);
    }
}
