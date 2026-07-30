<?php

namespace RadioChatBox\Tests;

use Pramnos\Framework\Testing\TestDatabase;

use PDO;
use PHPUnit\Framework\TestCase;
use Pramnos\Http\Client;
use Pramnos\Http\ClientException;
use Pramnos\Http\ClientResponse;
use RadioChatBox\Services\BotService;
use Pramnos\Database\Database;
use RadioChatBox\Services\LlmLog;
use RadioChatBox\Services\LlmService;

/**
 * Covers the LLM client's configuration and its handling of a truncated
 * completion, plus the call log.
 *
 * Regression: deepseek-v4-* reason internally, so a 300-token budget was spent
 * entirely on reasoning (finish_reason "length", empty or half-written content).
 * The fragment was delivered as if it were a real reply, which is why replies
 * arrived cut off mid-word and made no sense.
 */
class LlmServiceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::connection();
    }

    protected function tearDown(): void
    {
        Client::resetFakes();
        $this->pdo->prepare("DELETE FROM bot_llm_log WHERE fake_nickname LIKE 'llmtest%'")->execute();
    }

    /** A real LlmService (not the request()-overriding double) pointed at a fake host. */
    private function fakedLlm(): LlmService
    {
        return (new LlmService([
            'provider' => 'deepseek',
            'api_key'  => 'test-key',
            'base_url' => 'https://llm.test/v1',
            'model'    => 'test-model',
        ]))->withLogContext(['fake_nickname' => 'llmtest_' . uniqid(), 'peer_username' => 'peer']);
    }

    /**
     * chat() end to end through the real request() (framework HTTP client, faked):
     * a well-formed completion is fetched, decoded and returned as {text,
     * finish_reason, usage}. This exercises the Client-based transport that the
     * request()-overriding test double bypasses.
     */
    public function testChatFetchesACompletionThroughTheHttpClient(): void
    {
        Client::fake(['*llm.test*' => ClientResponse::make([
            'choices' => [['message' => ['content' => '  hi there  '], 'finish_reason' => 'stop']],
            'usage'   => ['total_tokens' => 12],
        ])]);

        $result = $this->fakedLlm()->chat('sys', [['role' => 'user', 'content' => 'geia']]);

        $this->assertSame('hi there', $result['text']);
        $this->assertSame('stop', $result['finish_reason']);
        $this->assertSame(12, $result['usage']['total_tokens']);
    }

    /**
     * A non-2xx provider response surfaces as a RuntimeException carrying the HTTP
     * status and the provider's error message (the request() error branch).
     */
    public function testChatSurfacesAnHttpErrorFromTheProvider(): void
    {
        Client::fake(['*llm.test*' => ClientResponse::make(['error' => ['message' => 'invalid api key']], 401)]);

        try {
            $this->fakedLlm()->chat('sys', [['role' => 'user', 'content' => 'geia']]);
            $this->fail('an HTTP error must not be returned as a completion');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('HTTP 401', $e->getMessage());
            $this->assertStringContainsString('invalid api key', $e->getMessage());
        }
    }

    /**
     * A transport error (ClientException from the HTTP client) surfaces as
     * "LLM request failed: …" (the request() catch branch).
     */
    public function testChatSurfacesATransportError(): void
    {
        Client::fake(['*llm.test*' => static function (): ClientResponse {
            throw new ClientException('connection refused', 7);
        }]);

        try {
            $this->fakedLlm()->chat('sys', [['role' => 'user', 'content' => 'geia']]);
            $this->fail('a transport error must propagate as a failure');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('LLM request failed', $e->getMessage());
            $this->assertStringContainsString('connection refused', $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // Configuration
    // ------------------------------------------------------------------

    public function testTheTokenBudgetIsLargeEnoughForAReasoningModel(): void
    {
        // 300 was the old default and it could not fit reasoning plus an answer.
        $this->assertGreaterThanOrEqual(1000, LlmService::DEFAULT_MAX_TOKENS);
        $this->assertSame(LlmService::DEFAULT_MAX_TOKENS, (new LlmService())->getMaxTokens());
    }

    public function testTemperatureDefaultsToACalmerValue(): void
    {
        // 1.0 produced off-topic, garbled Greek; 0.8 keeps replies casual but
        // coherent. An explicit override still wins.
        $this->assertSame(0.8, (new LlmService())->getTemperature());
        $this->assertSame(0.5, (new LlmService(['temperature' => '0.5']))->getTemperature());
        $this->assertSame(0.8, (new LlmService(['temperature' => '']))->getTemperature());
    }

    public function testReasoningIsOffByDefault(): void
    {
        $this->assertFalse((new LlmService())->isReasoningEnabled());
    }

    /**
     * The setting arrives as a string from the settings table.
     */
    public function testReasoningAcceptsSettingStrings(): void
    {
        $this->assertTrue((new LlmService(['reasoning' => 'true']))->isReasoningEnabled());
        $this->assertTrue((new LlmService(['reasoning' => true]))->isReasoningEnabled());

        foreach (['false', '', '0', false, 0] as $off) {
            $this->assertFalse(
                (new LlmService(['reasoning' => $off]))->isReasoningEnabled(),
                'reasoning should stay off for ' . var_export($off, true)
            );
        }
    }

    public function testAnExplicitBudgetWins(): void
    {
        $this->assertSame(2500, (new LlmService(['max_tokens' => '2500']))->getMaxTokens());
        // Nonsense values fall back rather than sending 0.
        $this->assertSame(LlmService::DEFAULT_MAX_TOKENS, (new LlmService(['max_tokens' => '0']))->getMaxTokens());
    }

    public function testTheModelFallsBackToTheSupportedDefault(): void
    {
        $this->assertSame(BotService::defaultModel(), (new LlmService(['model' => '']))->getModel());
    }

    public function testChatRefusesWithoutAnApiKey(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/API key is not configured/');

        (new LlmService(['api_key' => '']))->chat('sys', [['role' => 'user', 'content' => 'geia']]);
    }

    public function testChatRefusesWithoutMessages(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new LlmService(['api_key' => 'k']))->chat('sys', []);
    }

    // ------------------------------------------------------------------
    // Truncated completions
    // ------------------------------------------------------------------

    public function testATruncatedReplyIsRejectedRatherThanDelivered(): void
    {
        $llm = new TruncatingLlm([
            'finish_reason' => 'length',
            'content' => 'Αχ, δεν ξέρω... είναι λίγο μπερδεμένα. Έ',
            'usage' => ['completion_tokens_details' => ['reasoning_tokens' => 260]],
        ]);

        try {
            $llm->chat('sys', [['role' => 'user', 'content' => 'geia']]);
            $this->fail('a truncated reply must not be returned as if it were complete');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('truncated', $e->getMessage());
            // The message has to name the cause, or the next person guesses again.
            $this->assertStringContainsString('reasoning', $e->getMessage());
        }
    }

    public function testACompleteReplyIsReturned(): void
    {
        $llm = new TruncatingLlm([
            'finish_reason' => 'stop',
            'content' => '  ναι ρε, ελεύθερη  ',
            'usage' => ['total_tokens' => 42],
        ]);

        $result = $llm->chat('sys', [['role' => 'user', 'content' => 'geia']]);

        $this->assertSame('ναι ρε, ελεύθερη', $result['text']);
        $this->assertSame('stop', $result['finish_reason']);
        $this->assertSame(42, $result['usage']['total_tokens']);
    }

    public function testAnEmptyCompletionIsRejected(): void
    {
        $llm = new TruncatingLlm(['finish_reason' => 'stop', 'content' => '', 'usage' => []]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/empty completion/');

        $llm->chat('sys', [['role' => 'user', 'content' => 'geia']]);
    }

    // ------------------------------------------------------------------
    // Call log
    // ------------------------------------------------------------------

    public function testASuccessfulCallIsLoggedWithItsTokenUsage(): void
    {
        $log = new LlmLog();
        $llm = (new TruncatingLlm(
            ['finish_reason' => 'stop', 'content' => 'ok re', 'usage' => ['total_tokens' => 7]],
            ['log' => $log]
        ))->withLogContext(['fake_nickname' => 'llmtest_bot', 'peer_username' => 'llmtest_peer']);

        $llm->chat('sys prompt', [['role' => 'user', 'content' => 'geia']]);

        $entry = $log->recent(1)[0];
        $this->assertSame('llmtest_bot', $entry['fake_nickname']);
        $this->assertSame('llmtest_peer', $entry['peer_username']);
        $this->assertSame('stop', $entry['finish_reason']);
        $this->assertSame('ok re', $entry['reply']);
        $this->assertSame('sys prompt', $entry['system_prompt']);
        $this->assertStringContainsString('total_tokens', (string) $entry['usage']);
        $this->assertNull($entry['error']);
    }

    public function testATruncatedCallIsLoggedAsAProblem(): void
    {
        $log = new LlmLog();
        $llm = (new TruncatingLlm(
            [
                'finish_reason' => 'length',
                'content' => 'μισή προ',
                'usage' => ['completion_tokens_details' => ['reasoning_tokens' => 300]],
            ],
            ['log' => $log]
        ))->withLogContext(['fake_nickname' => 'llmtest_bot', 'peer_username' => 'llmtest_peer']);

        try {
            $llm->chat('sys', [['role' => 'user', 'content' => 'geia']]);
        } catch (\RuntimeException) {
            // expected
        }

        $problems = $log->recent(5, true);
        $this->assertNotEmpty($problems, 'a truncated call must be findable in the log');
        $this->assertSame('length', $problems[0]['finish_reason']);
        $this->assertStringContainsString('truncated', (string) $problems[0]['error']);
    }

    public function testTheLogPagesAndFilters(): void
    {
        $log = new LlmLog();

        $log->record(['fake_nickname' => 'llmtest_bot', 'peer_username' => 'llmtest_peer', 'model' => 'm', 'finish_reason' => 'stop', 'reply' => 'fine']);
        $log->record(['fake_nickname' => 'llmtest_bot', 'peer_username' => 'llmtest_peer', 'model' => 'm', 'finish_reason' => 'length', 'error' => 'truncated']);
        $log->record(['fake_nickname' => 'llmtest_bot', 'peer_username' => 'llmtest_peer', 'model' => 'm', 'purpose' => 'summary', 'finish_reason' => 'stop']);

        $all = $log->page(10, 0, ['fake_nickname' => 'llmtest_bot']);
        $this->assertSame(3, $all['total']);

        // Newest first.
        $this->assertSame('summary', $all['entries'][0]['purpose']);

        $problems = $log->page(10, 0, ['fake_nickname' => 'llmtest_bot', 'problems_only' => true]);
        $this->assertSame(1, $problems['total']);
        $this->assertSame('length', $problems['entries'][0]['finish_reason']);

        $summaries = $log->page(10, 0, ['fake_nickname' => 'llmtest_bot', 'purpose' => 'summary']);
        $this->assertSame(1, $summaries['total']);

        // Paging.
        $firstPage = $log->page(2, 0, ['fake_nickname' => 'llmtest_bot']);
        $secondPage = $log->page(2, 2, ['fake_nickname' => 'llmtest_bot']);
        $this->assertCount(2, $firstPage['entries']);
        $this->assertCount(1, $secondPage['entries']);
    }

    public function testTheListIncludesTheConversationMessages(): void
    {
        // The calls table shows the peer's message and the reply inline, so the
        // list rows must carry the messages column, not only the reply.
        $log = new LlmLog();
        $messages = [
            ['role' => 'system', 'content' => 'you are a bot'],
            ['role' => 'user', 'content' => 'geia ti kaneis'],
        ];
        $log->record([
            'fake_nickname' => 'llmtest_bot',
            'peer_username' => 'llmtest_peer',
            'model' => 'm',
            'finish_reason' => 'stop',
            'messages' => $messages,
            'reply' => 'kala esy',
        ]);

        $entry = $log->page(1, 0, ['fake_nickname' => 'llmtest_bot'])['entries'][0];

        $this->assertArrayHasKey('messages', $entry);
        $this->assertSame($messages, json_decode($entry['messages'], true));
    }

    public function testASingleCallCanBeFetchedWithItsPrompt(): void
    {
        $log = new LlmLog();
        $log->record([
            'fake_nickname' => 'llmtest_bot',
            'model' => 'm',
            'system_prompt' => 'the full prompt',
            'messages' => [['role' => 'user', 'content' => 'geia']],
        ]);

        $id = (int) $log->page(1, 0, ['fake_nickname' => 'llmtest_bot'])['entries'][0]['id'];
        $entry = $log->find($id);

        $this->assertNotNull($entry);
        $this->assertSame('the full prompt', $entry['system_prompt']);
        $this->assertStringContainsString('geia', (string) $entry['messages']);
        $this->assertNull($log->find(0));
    }

    /**
     * Cost is stored per call so the money figures survive a price change: the
     * unit prices are an editable setting, and history must keep what a call
     * actually cost when it was made.
     */
    public function testACallIsCostedWhenItIsLogged(): void
    {
        $log = new LlmLog(null, new \RadioChatBox\Services\LlmPricing(
            ['test-model' => ['cache_hit' => 0.0, 'cache_miss' => 1.0, 'output' => 10.0]]
        ));

        $log->record([
            'fake_nickname' => 'llmtest_bot',
            'model' => 'test-model',
            'usage' => [
                'prompt_tokens' => 1000,
                'prompt_cache_hit_tokens' => 0,
                'prompt_cache_miss_tokens' => 1000,
                'completion_tokens' => 100,
                'total_tokens' => 1100,
            ],
            'finish_reason' => 'stop',
        ]);

        $entry = $log->page(1, 0, ['fake_nickname' => 'llmtest_bot'])['entries'][0];

        // 1000 * 1.0 / 1M + 100 * 10.0 / 1M
        $this->assertSame(0.002, round((float) $entry['cost'], 8));
        $this->assertSame('USD', $entry['currency']);

        $summary = $log->summary(24);
        $this->assertGreaterThanOrEqual(0.002, (float) $summary['cost']);
    }

    public function testACallWithNoConfiguredPriceIsLeftUncostedRatherThanFree(): void
    {
        $log = new LlmLog(null, new \RadioChatBox\Services\LlmPricing(
            ['other-model' => ['cache_hit' => 1.0, 'cache_miss' => 1.0, 'output' => 1.0]]
        ));

        $log->record([
            'fake_nickname' => 'llmtest_bot',
            'model' => 'test-model',
            'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 50],
        ]);

        $entry = $log->page(1, 0, ['fake_nickname' => 'llmtest_bot'])['entries'][0];

        // NULL, not 0: the admin panel must be able to say "unpriced".
        $this->assertNull($entry['cost']);
        // The window may hold other calls, so this asserts it is counted, not that
        // it is the only one.
        $this->assertGreaterThanOrEqual(1, (int) $log->summary(24)['uncosted_calls']);
    }

    /**
     * With two providers configured at once, "what did it cost" is not one number.
     */
    public function testTheSummaryCanBeSplitByProvider(): void
    {
        $log = new LlmLog(null, new \RadioChatBox\Services\LlmPricing([
            'cheap' => ['cache_hit' => 0.0, 'cache_miss' => 1.0, 'output' => 1.0],
            'dear' => ['cache_hit' => 0.0, 'cache_miss' => 100.0, 'output' => 100.0],
        ]));

        // The window holds whatever the installation has really been doing, so this
        // measures the contribution rather than the total.
        $before = $log->summaryByProvider(24);
        $costOf = static fn (array $rows, string $provider): float => (float) ($rows[$provider]['cost'] ?? 0);

        $log->record([
            'fake_nickname' => 'llmtest_bot',
            'provider' => 'deepseek',
            'model' => 'cheap',
            'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 0, 'total_tokens' => 1000],
        ]);
        $log->record([
            'fake_nickname' => 'llmtest_bot',
            'provider' => 'openai',
            'model' => 'dear',
            'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 0, 'total_tokens' => 1000],
        ]);

        $after = $log->summaryByProvider(24);

        $this->assertSame(0.001, round($costOf($after, 'deepseek') - $costOf($before, 'deepseek'), 8));
        $this->assertSame(0.1, round($costOf($after, 'openai') - $costOf($before, 'openai'), 8));

        // Dearest first, so the one to worry about is at the front.
        $this->assertSame(
            array_keys($after),
            array_values(array_map(
                static fn (array $row): string => (string) $row['provider'],
                $after
            )),
            'the keys must match the provider column'
        );
        $costs = array_map(static fn (array $row): float => (float) $row['cost'], $after);
        $sorted = $costs;
        rsort($sorted);
        $this->assertSame($sorted, array_values($costs), 'ordered by spend, dearest first');
    }

    public function testTheLogCanBePrunedAndSummarised(): void
    {
        $log = new LlmLog();

        $log->record([
            'fake_nickname' => 'llmtest_bot',
            'peer_username' => 'llmtest_peer',
            'model' => 'test-model',
            'usage' => ['total_tokens' => 100, 'completion_tokens_details' => ['reasoning_tokens' => 40]],
            'finish_reason' => 'stop',
            'duration_ms' => 500,
        ]);

        $summary = $log->summary(24);
        $this->assertGreaterThanOrEqual(1, (int) $summary['calls']);
        $this->assertGreaterThanOrEqual(100, (int) $summary['total_tokens']);
        $this->assertGreaterThanOrEqual(40, (int) $summary['reasoning_tokens']);

        // Backdate it past the window and prune.
        $this->pdo->prepare(
            "UPDATE bot_llm_log SET created_at = NOW() - make_interval(days => 30) WHERE fake_nickname = 'llmtest_bot'"
        )->execute();

        $this->assertGreaterThanOrEqual(1, $log->prune(7));
    }

    public function testLoggingCanBeTurnedOff(): void
    {
        $settings = new class extends \RadioChatBox\Services\SettingsService {
            public function get(string $key, mixed $default = null): mixed
            {
                return $key === 'bot_llm_log_enabled' ? 'false' : $default;
            }
        };

        $log = new LlmLog($settings);
        $this->assertFalse($log->isEnabled());

        $log->record(['fake_nickname' => 'llmtest_bot', 'model' => 'x']);

        $stmt = $this->pdo->query("SELECT COUNT(*) FROM bot_llm_log WHERE fake_nickname = 'llmtest_bot'");
        $this->assertSame(0, (int) $stmt->fetchColumn());
    }
}

/**
 * LlmService with the HTTP round-trip replaced by a canned response, so the
 * finish_reason handling and logging can be tested without network access.
 */
class TruncatingLlm extends LlmService
{
    /** @var array<string,mixed> */
    private array $canned;

    /**
     * @param array<string,mixed> $canned   finish_reason, content, usage
     * @param array<string,mixed> $overrides
     */
    public function __construct(array $canned, array $overrides = [])
    {
        parent::__construct($overrides + ['api_key' => 'test-key']);
        $this->canned = $canned;
    }

    protected function post(string $path, array $payload): array
    {
        return [
            'choices' => [[
                'finish_reason' => $this->canned['finish_reason'] ?? 'stop',
                'message' => ['content' => $this->canned['content'] ?? ''],
            ]],
            'usage' => $this->canned['usage'] ?? [],
        ];
    }
}
