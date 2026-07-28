<?php

namespace RadioChatBox\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use RadioChatBox\Services\BotService;
use RadioChatBox\Database;
use RadioChatBox\LlmAccount;
use RadioChatBox\Services\LlmService;
use RadioChatBox\Services\SettingsService;

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
        $this->clearLlmCache();
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DELETE FROM bot_llm_balance');

        // These tests share Redis with the running app, in both directions: a stub
        // balance must not be left behind for the panel to read, and a real cached
        // value must not be read here instead of the stub (which silently turned
        // every cost assertion into whatever the app last fetched).
        $this->clearLlmCache();
    }

    private function clearLlmCache(): void
    {
        $redis = Database::getRedis();

        foreach ($redis->keys(Database::getRedisPrefix() . 'llm:*') ?: [] as $key) {
            $redis->del($key);
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
    // Real spend where there is no balance
    // ------------------------------------------------------------------

    /**
     * OpenAI exposes no credit balance, but GET /organization/costs reports what was
     * really spent - so "no balance" must not mean "no real figure at all".
     */
    public function testAProviderWithoutABalanceReportsItsCostsInstead(): void
    {
        $account = $this->account(
            [
                '/organization/costs?start_time=X&bucket_width=1d&limit=7' => [
                    'data' => [
                        ['results' => [['amount' => ['value' => 1.25, 'currency' => 'usd']]]],
                        ['results' => [['amount' => ['value' => 0.75, 'currency' => 'usd']]]],
                    ],
                ],
            ],
            ['bot_llm_provider' => 'openai', 'bot_openai_admin_key' => 'sk-admin-x'],
            'openai'
        );

        $this->assertFalse($account->supportsBalance());
        $this->assertTrue($account->supportsCosts());
        $this->assertNull($account->balance(true), 'there is no balance endpoint to call');

        $costs = $account->providerCosts(7 * 24);

        $this->assertNotNull($costs);
        $this->assertSame(2.0, round($costs['spent'], 6), 'every daily bucket counts');
        $this->assertSame('USD', $costs['currency']);
        $this->assertSame('provider', $costs['source']);
    }

    /**
     * Regression: start_time selects the bucket that CONTAINS it, and buckets are cut
     * at UTC midnight - so asking for "now minus 24h" returned yesterday's bucket,
     * which was empty, and the panel reported $0 while the provider's own dashboard
     * showed real spend.
     */
    public function testTheCostsWindowIsAlignedToTheDailyBuckets(): void
    {
        $stub = new StubAccountLlm([]);
        $account = new LlmAccount(
            $this->settingsFor(['bot_llm_provider' => 'openai', 'bot_openai_admin_key' => 'sk-admin-x']),
            $stub,
            'openai',
            $stub
        );

        $account->providerCosts(24);
        $account->providerCosts(7 * 24);

        $paths = array_keys($stub->calls);
        $midnight = strtotime('today midnight UTC');

        $this->assertContains(
            '/organization/costs?start_time=' . $midnight . '&bucket_width=1d&limit=1',
            $paths,
            'a 24h window is today\'s bucket, not the one before it'
        );
        $this->assertContains(
            '/organization/costs?start_time=' . ($midnight - 6 * 86400) . '&bucket_width=1d&limit=7',
            $paths,
            'a 7d window needs seven buckets ending with today'
        );
    }

    public function testAStringAmountIsStillSummed(): void
    {
        // The API returns amounts as strings with thirty decimals.
        $account = $this->account(
            [
                '/organization/costs?start_time=X&bucket_width=1d&limit=1' => [
                    'data' => [['results' => [
                        ['amount' => ['value' => '0.004164000000000000000000000000', 'currency' => 'usd']],
                    ]]],
                ],
            ],
            ['bot_llm_provider' => 'openai', 'bot_openai_admin_key' => 'sk-admin-x'],
            'openai'
        );

        $this->assertSame(0.004164, round($account->providerCosts(24)['spent'], 8));
    }

    public function testAnEmptyBucketMeansNothingSpentYet(): void
    {
        $account = $this->account(
            ['/organization/costs?start_time=X&bucket_width=1d&limit=1' => ['data' => [['results' => []]]]],
            ['bot_llm_provider' => 'openai', 'bot_openai_admin_key' => 'sk-admin-x'],
            'openai'
        );

        $costs = $account->providerCosts(24);
        $this->assertNotNull($costs, 'an empty bucket is an answer, not a failure');
        $this->assertSame(0.0, $costs['spent']);
    }

    /**
     * A provider with no balance endpoint still needs a figure that maps to the bill.
     */
    public function testMonthToDateAsksFromTheFirstOfTheMonth(): void
    {
        $stub = new StubAccountLlm([]);
        $account = new LlmAccount(
            $this->settingsFor(['bot_llm_provider' => 'openai', 'bot_openai_admin_key' => 'sk-admin-x']),
            $stub,
            'openai',
            $stub
        );

        $account->monthToDateCosts();

        $day = (int) gmdate('j');
        $expected = strtotime('today midnight UTC') - (($day - 1) * 86400);

        $this->assertContains(
            '/organization/costs?start_time=' . $expected . '&bucket_width=1d&limit=' . $day,
            array_keys($stub->calls)
        );
    }

    public function testCostsNeedTheAdminKeyRatherThanTheChatKey(): void
    {
        $account = $this->account(
            ['/organization/costs' => ['data' => []]],
            ['bot_llm_provider' => 'openai', 'bot_openai_api_key' => 'sk-project', 'bot_openai_admin_key' => ''],
            'openai'
        );

        // The endpoint refuses a project key, so without an admin key we do not ask.
        $this->assertFalse($account->hasAdminKey());
        $this->assertNull($account->providerCosts(24));
    }

    public function testRealSpendFallsBackToTheProvidersOwnFigure(): void
    {
        $account = $this->account(
            [
                '/organization/costs?start_time=X&bucket_width=1d&limit=1' => [
                    'data' => [['results' => [['amount' => ['value' => 0.4, 'currency' => 'usd']]]]],
                ],
            ],
            ['bot_llm_provider' => 'openai', 'bot_openai_admin_key' => 'sk-admin-x'],
            'openai'
        );

        $spend = $account->realSpend(24);

        $this->assertNotNull($spend);
        $this->assertSame(0.4, round($spend['spent'], 6));
        $this->assertSame('provider', $spend['source']);
        $this->assertSame(0.0, $spend['topped_up'], 'a costs report says nothing about top-ups');
    }

    public function testADeepSeekAccountStillUsesItsBalanceSnapshots(): void
    {
        $this->seedBalances([['5.00', 3], ['4.90', 1]]);

        $spend = $this->account([])->realSpend(24);

        $this->assertSame('balance', $spend['source']);
        $this->assertSame(0.1, round($spend['spent'], 6));
    }

    public function testDeepSeekHasNoCostsEndpointToAsk(): void
    {
        $account = $this->account([]);

        // It reports a balance instead, which is the better figure anyway.
        $this->assertFalse($account->supportsCosts());
        $this->assertNull($account->providerCosts(24));
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
    private function account(array $responses, array $settingsOverrides = [], ?string $provider = null): LlmAccount
    {
        // The same stub answers the organisation endpoints, which in production use a
        // separate admin credential.
        $stub = new StubAccountLlm($responses);

        return new LlmAccount($this->settingsFor($settingsOverrides), $stub, $provider, $stub);
    }

    /**
     * @param array<string,string> $overrides
     */
    private function settingsFor(array $overrides): SettingsService
    {
        return new class ($overrides) extends SettingsService {
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

        // Costs carry a start_time that moves with the clock, so match on the shape.
        if ($response === null) {
            foreach ($this->responses as $pattern => $canned) {
                if (preg_replace('/start_time=\d+/', 'start_time=X', $path) === $pattern) {
                    $response = $canned;
                    break;
                }
            }
        }

        if ($response instanceof \Throwable) {
            throw $response;
        }

        return is_array($response) ? $response : [];
    }
}
