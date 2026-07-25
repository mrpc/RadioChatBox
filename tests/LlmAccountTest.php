<?php

namespace RadioChatBox\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use RadioChatBox\BotService;
use RadioChatBox\Database;
use RadioChatBox\LlmAccount;
use RadioChatBox\LlmService;
use RadioChatBox\SettingsService;

/**
 * Covers the figures that come from the provider rather than from our own
 * arithmetic: the remaining balance (GET /user/balance) and the live model list
 * (GET /models).
 *
 * This matters because per-call cost is only an estimate - it is priced from a
 * configured table, since no pricing endpoint exists. The balance is real money,
 * and the snapshots are how "actually spent" is measured.
 */
class LlmAccountTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = Database::getPDO();
        $this->pdo->exec('DELETE FROM bot_llm_balance');
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DELETE FROM bot_llm_balance');

        // These tests share Redis with the running app; don't leave a stub balance
        // or model list behind for the admin panel to read.
        $redis = Database::getRedis();
        foreach (['llm:balance', 'llm:models'] as $key) {
            $redis->del(Database::getRedisPrefix() . $key);
        }
    }

    // ------------------------------------------------------------------
    // Balance
    // ------------------------------------------------------------------

    public function testTheBalanceIsReadFromTheProvider(): void
    {
        $account = $this->account([
            '/user/balance' => [
                'is_available' => true,
                'balance_infos' => [
                    ['currency' => 'USD', 'total_balance' => '4.99', 'granted_balance' => '0.00', 'topped_up_balance' => '4.99'],
                ],
            ],
        ]);

        $balance = $account->balance(true);

        $this->assertNotNull($balance);
        $this->assertSame('USD', $balance['currency']);
        $this->assertSame(4.99, $balance['total']);
        $this->assertSame(4.99, $balance['topped_up']);
        $this->assertTrue($balance['is_available']);
    }

    public function testTheConfiguredCurrencyIsPreferredWhenSeveralAreReported(): void
    {
        $account = $this->account(
            [
                '/user/balance' => [
                    'is_available' => true,
                    'balance_infos' => [
                        ['currency' => 'CNY', 'total_balance' => '110.00'],
                        ['currency' => 'USD', 'total_balance' => '15.00'],
                    ],
                ],
            ],
            ['bot_llm_currency' => 'USD']
        );

        $this->assertSame(15.0, $account->balance(true)['total']);
    }

    public function testAnInsufficientBalanceIsReportedAsSuch(): void
    {
        $account = $this->account([
            '/user/balance' => [
                'is_available' => false,
                'balance_infos' => [['currency' => 'USD', 'total_balance' => '0.00']],
            ],
        ]);

        // The panel colours this red: calls will start failing.
        $this->assertFalse($account->balance(true)['is_available']);
    }

    public function testAFailedBalanceCallReturnsNullInsteadOfBreakingThePage(): void
    {
        $account = $this->account(['/user/balance' => new \RuntimeException('HTTP 401: bad key')]);

        $this->expectOutputRegex('/./');
        error_log('');

        $this->assertNull($account->balance(true));
    }

    public function testAnUnexpectedResponseShapeIsNotTreatedAsABalance(): void
    {
        $this->assertNull($this->account(['/user/balance' => ['unexpected' => true]])->balance(true));
    }

    public function testWithoutAnApiKeyThereIsNoBalanceToShow(): void
    {
        $account = new LlmAccount(new SettingsService(), new LlmService(['api_key' => '']));

        $this->assertFalse($account->isConfigured());
        $this->assertNull($account->balance(true));
    }

    // ------------------------------------------------------------------
    // Model list
    // ------------------------------------------------------------------

    public function testTheModelListComesFromTheProvider(): void
    {
        $account = $this->account([
            '/models' => ['data' => [
                ['id' => 'deepseek-v4-flash'],
                ['id' => 'deepseek-v5-something'],
            ]],
        ]);

        $result = $account->models(true);

        $this->assertSame('api', $result['source']);
        $this->assertArrayHasKey('deepseek-v5-something', $result['models'], 'a new model must appear without a code change');
        // Known ids keep the description we wrote for them.
        $this->assertSame(BotService::MODELS['deepseek-v4-flash'], $result['models']['deepseek-v4-flash']);
    }

    public function testARetiredModelDisappearsFromTheList(): void
    {
        // deepseek-chat was retired and every call failed with HTTP 400 while it
        // was still hardcoded in the dropdown.
        $account = $this->account(['/models' => ['data' => [['id' => 'deepseek-v4-pro']]]]);

        $this->assertSame(['deepseek-v4-pro'], array_keys($account->models(true)['models']));
    }

    public function testTheBuiltInListIsUsedWhenTheProviderCannotBeReached(): void
    {
        $account = $this->account(['/models' => new \RuntimeException('network down')]);

        $this->expectOutputRegex('/./');
        error_log('');

        $result = $account->models(true);

        // Never leave the dropdown empty.
        $this->assertSame('built-in', $result['source']);
        $this->assertSame(BotService::MODELS, $result['models']);
    }

    public function testAnEmptyModelListFallsBackRatherThanOfferingNothing(): void
    {
        $this->assertSame('built-in', $this->account(['/models' => ['data' => []]])->models(true)['source']);
    }

    // ------------------------------------------------------------------
    // Caching
    // ------------------------------------------------------------------

    /**
     * Both endpoints are hit on every dashboard load while the numbers move
     * slowly, and /user/balance is rate-limited upstream.
     */
    public function testTheBalanceIsCachedBetweenCalls(): void
    {
        $llm = new StubAccountLlm([
            '/user/balance' => [
                'is_available' => true,
                'balance_infos' => [['currency' => 'USD', 'total_balance' => '4.99']],
            ],
        ]);
        $account = new LlmAccount(new SettingsService(), $llm);

        $account->balance(true);
        $this->assertSame(1, $llm->calls['/user/balance']);

        $account->balance();
        $this->assertSame(1, $llm->calls['/user/balance'], 'the second read should come from the cache');

        // ...but asking for it fresh always goes upstream.
        $account->balance(true);
        $this->assertSame(2, $llm->calls['/user/balance']);
    }

    public function testTheModelListIsCachedBetweenCalls(): void
    {
        $llm = new StubAccountLlm(['/models' => ['data' => [['id' => 'deepseek-v4-pro']]]]);
        $account = new LlmAccount(new SettingsService(), $llm);

        $account->models(true);
        $cached = $account->models();

        $this->assertSame(1, $llm->calls['/models']);
        $this->assertSame(['deepseek-v4-pro'], array_keys($cached['models']));
        $this->assertSame('api', $cached['source']);
    }

    // ------------------------------------------------------------------
    // Snapshots and real spend
    // ------------------------------------------------------------------

    public function testASnapshotRecordsTheBalance(): void
    {
        $account = $this->account([
            '/user/balance' => [
                'is_available' => true,
                'balance_infos' => [['currency' => 'USD', 'total_balance' => '4.99', 'granted_balance' => '0', 'topped_up_balance' => '4.99']],
            ],
        ]);

        $this->assertNotNull($account->snapshot(true));
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM bot_llm_balance')->fetchColumn());

        // Rate-limited: a second snapshot right away is skipped, so the provider is
        // not polled on every worker tick.
        $this->assertNull($account->snapshot());
    }

    public function testRealSpendIsTheDropBetweenReadings(): void
    {
        $this->seedBalances([['5.00', 3], ['4.90', 2], ['4.75', 1]]);

        $spend = $this->account([])->realSpend(24);

        $this->assertNotNull($spend);
        $this->assertSame(0.25, round($spend['spent'], 6));
        $this->assertSame(0.0, round($spend['topped_up'], 6));
        $this->assertSame(3, $spend['readings']);
    }

    public function testATopUpIsNotCountedAsNegativeSpend(): void
    {
        // 5.00 -> 4.80 (spent 0.20) -> 9.80 (topped up 5.00) -> 9.50 (spent 0.30)
        $this->seedBalances([['5.00', 4], ['4.80', 3], ['9.80', 2], ['9.50', 1]]);

        $spend = $this->account([])->realSpend(24);

        $this->assertSame(0.5, round($spend['spent'], 6), 'a top-up must not cancel out spend');
        $this->assertSame(5.0, round($spend['topped_up'], 6));
    }

    public function testASingleReadingCannotMeasureSpend(): void
    {
        $this->seedBalances([['5.00', 1]]);

        // One reading is a balance, not a difference - null rather than a fake 0.
        $this->assertNull($this->account([])->realSpend(24));
    }

    public function testReadingsOutsideTheWindowAreIgnored(): void
    {
        $this->seedBalances([['5.00', 240], ['4.00', 200]]);

        $this->assertNull($this->account([])->realSpend(24));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $responses path => response array or Throwable
     * @param array<string,string> $settingsOverrides
     */
    private function account(array $responses, array $settingsOverrides = []): LlmAccount
    {
        $settings = new class ($settingsOverrides) extends SettingsService {
            /** @param array<string,string> $overrides */
            public function __construct(private array $overrides = [])
            {
                parent::__construct();
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->overrides[$key] ?? parent::get($key, $default);
            }
        };

        return new LlmAccount($settings, new StubAccountLlm($responses));
    }

    /**
     * @param list<array{0:string,1:int}> $rows [total_balance, hours ago]
     */
    private function seedBalances(array $rows): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO bot_llm_balance (created_at, currency, total_balance)
             VALUES (NOW() - make_interval(hours => :hours), 'USD', :total)"
        );

        foreach ($rows as [$total, $hoursAgo]) {
            $stmt->execute(['hours' => $hoursAgo, 'total' => $total]);
        }
    }
}

/**
 * LlmService with the account endpoints answered from a canned map, so the
 * balance and model handling can be tested without network access.
 */
class StubAccountLlm extends LlmService
{
    /** @var array<string,int> How many times each path was requested */
    public array $calls = [];

    /** @param array<string,mixed> $responses */
    public function __construct(private array $responses)
    {
        parent::__construct(['api_key' => 'test-key']);
    }

    public function get(string $path): array
    {
        $this->calls[$path] = ($this->calls[$path] ?? 0) + 1;

        $response = $this->responses[$path] ?? null;

        if ($response instanceof \Throwable) {
            throw $response;
        }

        return is_array($response) ? $response : [];
    }
}
