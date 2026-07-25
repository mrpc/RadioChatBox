<?php

namespace RadioChatBox\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use RadioChatBox\BotService;
use RadioChatBox\Database;
use RadioChatBox\LlmLog;
use RadioChatBox\LlmService;

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
        $this->pdo = Database::getPDO();
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare("DELETE FROM bot_llm_log WHERE fake_nickname LIKE 'llmtest%'")->execute();
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
        $settings = new class extends \RadioChatBox\SettingsService {
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
