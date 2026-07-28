<?php

namespace RadioChatBox\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use RadioChatBox\Services\CleanupService;
use RadioChatBox\Database;

/**
 * Covers the housekeeping the worker now runs on a schedule.
 *
 * Regression: three of these queries wrote `INTERVAL :days DAY`, which PostgreSQL parses
 * as a literal rather than a parameter - so every run failed with a syntax error, the
 * failure was swallowed into error_log, and the purge and archive silently never
 * happened. It only came to light when the scheduler started running them where someone
 * was watching the output.
 */
class CleanupServiceTest extends TestCase
{
    private PDO $pdo;
    private CleanupService $cleanup;

    protected function setUp(): void
    {
        $this->pdo = Database::getPDO();
        $this->cleanup = new CleanupService();
        $this->removeFixtures();
    }

    protected function tearDown(): void
    {
        $this->removeFixtures();
    }

    public function testAnOldDeletedMessageIsPurged(): void
    {
        // The service reports what it did through error_log, which PHPUnit attributes
        // to the test.
        $this->expectOutputRegex('/Purged/');

        $this->insertMessage('cleanuptest_old_deleted', 40, true);
        $this->insertMessage('cleanuptest_new_deleted', 2, true);

        // No exception, and a real count: the query used to fail before deleting anything.
        $purged = $this->cleanup->purgeOldDeletedMessages(30);

        $this->assertGreaterThanOrEqual(1, $purged);
        $this->assertFalse($this->messageExists('cleanuptest_old_deleted'));
        $this->assertTrue($this->messageExists('cleanuptest_new_deleted'), 'a recent one must stay');
    }

    public function testTheCutoffIsRespected(): void
    {
        $this->expectOutputRegex('/Purged/');
        $this->insertMessage('cleanuptest_20_days', 20, true);

        $this->assertSame(0, $this->cleanup->purgeOldDeletedMessages(30));
        $this->assertTrue($this->messageExists('cleanuptest_20_days'));

        // Same row, shorter window: now it goes.
        $this->assertGreaterThanOrEqual(1, $this->cleanup->purgeOldDeletedMessages(10));
        $this->assertFalse($this->messageExists('cleanuptest_20_days'));
    }

    public function testOldMessagesAreArchivedAndRemoved(): void
    {
        $this->expectOutputRegex('/Archived/');
        $this->insertMessage('cleanuptest_ancient', 120, false);

        $archived = $this->cleanup->archiveOldMessages(90);

        $this->assertGreaterThanOrEqual(1, $archived);
        $this->assertFalse($this->messageExists('cleanuptest_ancient'), 'it moves, it does not copy');

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM messages_archive WHERE username = ?');
        $stmt->execute(['cleanuptest_ancient']);
        $this->assertSame(1, (int) $stmt->fetchColumn());

        $this->pdo->prepare('DELETE FROM messages_archive WHERE username LIKE ?')
            ->execute(['cleanuptest_%']);
    }

    public function testRunAllReportsEveryStep(): void
    {
        $results = $this->cleanup->runAll();

        // The scheduler logs this, so a missing key would hide a step that stopped running.
        foreach (['expired_bans', 'stale_sessions', 'deleted_messages', 'expired_dm_blocks'] as $key) {
            $this->assertArrayHasKey($key, $results);
            $this->assertIsInt($results[$key]);
        }
    }

    private function insertMessage(string $username, int $daysAgo, bool $deleted): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO messages (message_id, username, message, ip_address, created_at, is_deleted)
             VALUES (:id, :username, :message, :ip, NOW() - make_interval(days => :days), :deleted)'
        );
        $stmt->bindValue(':id', 'cleanuptest_' . bin2hex(random_bytes(6)));
        $stmt->bindValue(':username', $username);
        $stmt->bindValue(':message', 'fixture');
        $stmt->bindValue(':ip', '127.0.0.1');
        $stmt->bindValue(':days', $daysAgo, PDO::PARAM_INT);
        $stmt->bindValue(':deleted', $deleted, PDO::PARAM_BOOL);
        $stmt->execute();
    }

    private function messageExists(string $username): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM messages WHERE username = ?');
        $stmt->execute([$username]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function removeFixtures(): void
    {
        $this->pdo->prepare('DELETE FROM messages WHERE username LIKE ?')->execute(['cleanuptest_%']);

        try {
            $this->pdo->prepare('DELETE FROM messages_archive WHERE username LIKE ?')
                ->execute(['cleanuptest_%']);
        } catch (\PDOException) {
            // The archive table is created on first use.
        }
    }
}
