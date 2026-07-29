<?php

namespace RadioChatBox\Tests;

use Pramnos\Framework\Testing\TestDatabase;

use PHPUnit\Framework\TestCase;
use RadioChatBox\Database;
use RadioChatBox\Services\LlmLog;
use RadioChatBox\Services\SettingsService;

/**
 * Integration tests for LlmLog against the real (shared) database, added when it
 * moved onto the framework QueryBuilder in Phase 7.
 *
 * Rows are tagged with a provider/nickname unique to the run so assertions
 * isolate this test's data from any other bot activity in the shared table, and
 * everything is removed in tearDown. The bot_llm_log_enabled setting is forced
 * on (snapshotting the prior value) because record() honours it and real admin
 * use may have turned it off.
 */
class LlmLogTest extends TestCase
{
    private LlmLog $log;
    private string $provider;
    private string $nick;
    private ?string $previousEnabled = null;

    protected function setUp(): void
    {
        parent::setUp();

        $pdo = TestDatabase::connection();
        $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $stmt->execute(['bot_llm_log_enabled']);
        $value = $stmt->fetchColumn();
        $this->previousEnabled = $value === false ? null : (string) $value;

        $pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value'
        )->execute(['bot_llm_log_enabled', 'true']);
        (new SettingsService())->invalidateCache();

        $this->log = new LlmLog();
        $suffix = substr(bin2hex(random_bytes(4)), 0, 8);
        $this->provider = 'testprov_' . $suffix;
        $this->nick = 'testnick_' . $suffix;
    }

    protected function tearDown(): void
    {
        $pdo = TestDatabase::connection();
        $pdo->prepare('DELETE FROM bot_llm_log WHERE provider = ? OR fake_nickname = ?')
            ->execute([$this->provider, $this->nick]);

        if ($this->previousEnabled === null) {
            $pdo->prepare('DELETE FROM settings WHERE setting_key = ?')->execute(['bot_llm_log_enabled']);
        } else {
            $pdo->prepare(
                'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                 ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value'
            )->execute(['bot_llm_log_enabled', $this->previousEnabled]);
        }
        (new SettingsService())->invalidateCache();

        parent::tearDown();
    }

    /** A minimal entry keyed to this run, with sensible defaults. */
    private function entry(array $overrides = []): array
    {
        return array_merge([
            'fake_nickname' => $this->nick,
            'peer_username' => 'peer',
            'provider'      => $this->provider,
            'model'         => 'test-model',
            'reasoning'     => false,
            'finish_reason' => 'stop',
            'messages'      => [['role' => 'user', 'content' => 'hi']],
            'usage'         => ['total_tokens' => 100],
        ], $overrides);
    }

    /**
     * record() inserts a row (through queryBuilder()->insert()); find() reads it
     * back with the boolean reasoning flag and the jsonb payloads intact.
     */
    public function testRecordAndFindRoundTrip(): void
    {
        $this->log->record($this->entry(['reasoning' => true, 'http_status' => 200]));

        $recent = $this->log->recent(50);
        $mine = array_values(array_filter($recent, fn($r) => $r['fake_nickname'] === $this->nick));
        $this->assertNotEmpty($mine, 'the recorded row must be retrievable');

        $found = $this->log->find((int) $mine[0]['id']);
        $this->assertIsArray($found);
        $this->assertSame($this->provider, $found['provider']);
        // reasoning is a boolean column → the framework Result returns a real bool.
        $this->assertTrue($found['reasoning']);
        $this->assertSame(200, (int) $found['http_status']);
    }

    /**
     * recent(problemsOnly: true) returns only rows with an error or a non-"stop"
     * finish_reason — the whereRaw predicate.
     */
    public function testRecentProblemsOnlyFilters(): void
    {
        $this->log->record($this->entry(['finish_reason' => 'stop']));
        $this->log->record($this->entry(['finish_reason' => 'length']));

        $problems = array_filter(
            $this->log->recent(200, true),
            fn($r) => $r['fake_nickname'] === $this->nick
        );

        $this->assertNotEmpty($problems);
        foreach ($problems as $row) {
            $this->assertNotSame('stop', $row['finish_reason']);
        }
    }

    /**
     * summaryByProvider() runs the raw PostgreSQL aggregate (COUNT FILTER, jsonb
     * SUM, make_interval). Isolating by a unique provider lets us assert exact
     * counts: two calls, one error, one truncated, 150 summed tokens.
     */
    public function testSummaryByProviderAggregates(): void
    {
        $this->log->record($this->entry(['usage' => ['total_tokens' => 100], 'error' => null, 'finish_reason' => 'stop']));
        $this->log->record($this->entry(['usage' => ['total_tokens' => 50], 'error' => 'boom', 'finish_reason' => 'length']));

        $byProvider = $this->log->summaryByProvider(24);
        $this->assertArrayHasKey($this->provider, $byProvider);

        $row = $byProvider[$this->provider];
        $this->assertSame(2, (int) $row['calls']);
        $this->assertSame(1, (int) $row['errors']);
        $this->assertSame(1, (int) $row['truncated']);
        $this->assertSame(150, (int) $row['total_tokens']);
    }

    /**
     * summary() returns the expected aggregate keys and counts this run's rows
     * within the window (global figures, so only a lower bound is asserted).
     */
    public function testSummaryReturnsExpectedShape(): void
    {
        $this->log->record($this->entry());

        $summary = $this->log->summary(24);
        foreach (['calls', 'errors', 'truncated', 'total_tokens', 'cost', 'currency'] as $key) {
            $this->assertArrayHasKey($key, $summary);
        }
        $this->assertGreaterThanOrEqual(1, (int) $summary['calls']);
    }

    /**
     * page() applies the fake_nickname filter to both the COUNT and the page
     * query, returning the matching total and entries.
     */
    public function testPageFiltersByNickname(): void
    {
        $this->log->record($this->entry());
        $this->log->record($this->entry());

        $page = $this->log->page(25, 0, ['fake_nickname' => $this->nick]);

        $this->assertSame(2, $page['total']);
        $this->assertCount(2, $page['entries']);
        foreach ($page['entries'] as $row) {
            $this->assertSame($this->nick, $row['fake_nickname']);
        }
    }

    /**
     * prune() deletes rows past the retention window via make_interval. A row
     * back-dated 10 days is removed by prune(7); a fresh row is kept.
     */
    public function testPruneRemovesRowsPastRetention(): void
    {
        // Fresh row (kept) via the service.
        $this->log->record($this->entry());

        // Back-dated row (should be pruned) inserted directly.
        TestDatabase::connection()->prepare(
            "INSERT INTO bot_llm_log (fake_nickname, provider, model, finish_reason, created_at)
             VALUES (?, ?, 'test-model', 'stop', NOW() - INTERVAL '10 days')"
        )->execute([$this->nick, $this->provider]);

        $deleted = $this->log->prune(7);
        $this->assertGreaterThanOrEqual(1, $deleted, 'the 10-day-old row must be pruned');

        // The fresh row for this run must survive.
        $survivors = array_filter(
            $this->log->recent(200),
            fn($r) => $r['fake_nickname'] === $this->nick
        );
        $this->assertNotEmpty($survivors, 'a within-window row must be kept');
    }
}
