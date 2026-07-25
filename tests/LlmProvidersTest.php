<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RadioChatBox\LlmPricing;
use RadioChatBox\LlmProviders;
use RadioChatBox\LlmService;
use RadioChatBox\SettingsService;

/**
 * Covers the multi-provider setup: which API a bot talks to, with which
 * credentials, and how the request differs per provider.
 *
 * The point of the registry is that adding a provider is an entry in a table
 * rather than a change to the call path - and that one bot can run on a different
 * provider than the rest.
 */
class LlmProvidersTest extends TestCase
{
    // ------------------------------------------------------------------
    // The registry
    // ------------------------------------------------------------------

    public function testTheRegistryIsSelfConsistent(): void
    {
        foreach (LlmProviders::PROVIDERS as $id => $config) {
            $this->assertNotSame('', trim((string) $config['label']), "{$id} needs a label");
            $this->assertNotSame('', trim((string) $config['base_url']), "{$id} needs a base URL");
            $this->assertNotSame('', trim((string) $config['api_key_setting']), "{$id} needs a key setting");
            $this->assertNotEmpty($config['models'], "{$id} needs at least one model");

            // The default has to be one of the models actually offered.
            $this->assertArrayHasKey(
                $config['default_model'],
                $config['models'],
                "{$id}'s default model must be in its own list"
            );

            // Every offered model needs a price, or the panel shows "unpriced".
            foreach (array_keys($config['models']) as $model) {
                $this->assertNotNull(
                    (new LlmPricing())->forModel($model),
                    "{$model} is offered but has no seed price"
                );
            }
        }
    }

    public function testTheKeySettingsAreDistinctPerProvider(): void
    {
        $settings = [];
        foreach (array_keys(LlmProviders::PROVIDERS) as $id) {
            $settings[] = LlmProviders::apiKeySetting($id);
        }

        // Sharing one key setting would mean switching provider wipes the other's
        // credentials.
        $this->assertSame($settings, array_unique($settings));
    }

    /**
     * Every provider carries a FULL parameter set of its own, so all of them stay
     * configured at once and bots on different providers never share a value.
     */
    public function testNoSettingKeyIsSharedBetweenProviders(): void
    {
        $seen = [];

        foreach (array_keys(LlmProviders::PROVIDERS) as $id) {
            $keys = LlmProviders::settingKeys($id);

            foreach (['api_key', 'base_url', 'model', 'temperature', 'max_tokens'] as $parameter) {
                $this->assertArrayHasKey($parameter, $keys, "{$id} must configure {$parameter}");
                $key = $keys[$parameter];
                $this->assertNotNull($key, "{$id}.{$parameter} needs its own setting");

                $this->assertArrayNotHasKey(
                    $key,
                    $seen,
                    "{$key} is used by both {$id} and " . ($seen[$key] ?? '?')
                        . ' - changing one provider would change the other'
                );
                $seen[$key] = $id;
            }
        }
    }

    public function testAParameterAProviderDoesNotHaveIsNull(): void
    {
        // OpenAI has no documented reasoning switch, so there is nothing to store.
        $this->assertNull(LlmProviders::settingKey('openai', 'reasoning'));
        $this->assertSame('bot_llm_reasoning', LlmProviders::settingKey('deepseek', 'reasoning'));
        $this->assertNull(LlmProviders::settingKey('deepseek', 'nonsense'));
    }

    public function testTheAdminWhitelistCoversEveryProviderSetting(): void
    {
        $editable = (new \ReflectionClass(SettingsService::class))->getConstant('ADMIN_EDITABLE');

        foreach (LlmProviders::allSettingKeys() as $key) {
            // A key missing here was the original bug: saving reported success and
            // silently dropped the value.
            $this->assertContains($key, $editable, "{$key} must be admin-editable");
        }
    }

    public function testAnUnknownProviderFallsBackToTheDefault(): void
    {
        $this->assertFalse(LlmProviders::isKnown('nope'));
        $this->assertSame(
            LlmProviders::PROVIDERS[LlmProviders::DEFAULT_PROVIDER],
            LlmProviders::config('nope'),
            'a bad value must still leave the caller with an endpoint'
        );
        $this->assertSame(LlmProviders::DEFAULT_PROVIDER, LlmProviders::resolve('nope'));
    }

    public function testAnExplicitProviderWinsOverTheConfiguredOne(): void
    {
        $settings = $this->settingsWith(['bot_llm_provider' => 'openai']);

        $this->assertSame('openai', LlmProviders::resolve('', $settings), 'the setting applies when nothing is passed');
        $this->assertSame('deepseek', LlmProviders::resolve('deepseek', $settings), 'a per-bot choice wins');
    }

    public function testEachModelIsClaimedByExactlyOneProvider(): void
    {
        $this->assertSame('deepseek', LlmProviders::providerForModel('deepseek-v4-flash'));
        $this->assertSame('openai', LlmProviders::providerForModel('gpt-5.4-mini'));
        $this->assertNull(LlmProviders::providerForModel('llama-on-my-laptop'));

        $this->assertCount(
            count(LlmProviders::PROVIDERS['deepseek']['models']) + count(LlmProviders::PROVIDERS['openai']['models']),
            LlmProviders::allModels(),
            'model ids must not collide between providers'
        );
    }

    /**
     * @return list<array{0:string,1:string,2:bool}>
     */
    public static function catalogueEntries(): array
    {
        return [
            ['deepseek', 'deepseek-v4-flash', true],
            ['deepseek', 'text-embedding-3-small', false],
            ['openai', 'gpt-5.4-mini', true],
            ['openai', 'gpt-5.6-sol', true],
            ['openai', 'o3-mini', true],
            // OpenAI's catalogue also lists everything below, none of it chat.
            ['openai', 'text-embedding-3-large', false],
            ['openai', 'gpt-4o-audio-preview', false],
            ['openai', 'gpt-4o-realtime-preview', false],
            ['openai', 'gpt-image-1', false],
            ['openai', 'gpt-4o-transcribe', false],
            ['openai', 'omni-moderation-latest', false],
            ['openai', 'whisper-1', false],
            ['openai', 'dall-e-3', false],
            ['openai', 'tts-1', false],
        ];
    }

    #[DataProvider('catalogueEntries')]
    public function testOnlyChatModelsAreOfferedFromACatalogue(string $provider, string $model, bool $expected): void
    {
        $this->assertSame($expected, LlmProviders::isChatModel($provider, $model), $model);
    }

    // ------------------------------------------------------------------
    // Building a client
    // ------------------------------------------------------------------

    public function testAClientUsesItsProvidersEndpointAndKey(): void
    {
        $settings = $this->settingsWith([
            'bot_llm_provider' => 'openai',
            'bot_openai_api_key' => 'openai-key',
            'bot_llm_api_key' => 'deepseek-key',
        ]);

        $llm = LlmService::fromSettings($settings);

        $this->assertSame('openai', $llm->getProvider());
        $this->assertSame('https://api.openai.com/v1', $llm->getBaseUrl());
        $this->assertTrue($llm->isConfigured(), 'it must pick up the OpenAI key, not the DeepSeek one');
    }

    public function testAProviderWithoutItsOwnKeyIsNotConfigured(): void
    {
        $settings = $this->settingsWith([
            'bot_llm_provider' => 'openai',
            'bot_llm_api_key' => 'deepseek-key',
            'bot_openai_api_key' => '',
        ]);

        // Falling back to the other provider's key would send it upstream.
        $this->assertFalse(LlmService::fromSettings($settings)->isConfigured());
    }

    public function testAModelBelongingToAnotherProviderIsNotSent(): void
    {
        $settings = $this->settingsWith([
            'bot_llm_provider' => 'openai',
            'bot_llm_model' => 'deepseek-v4-flash',
            'bot_openai_api_key' => 'k',
        ]);

        // Sending deepseek-v4-flash to OpenAI is an HTTP 400; use its default.
        $this->assertSame('gpt-5.4-mini', LlmService::fromSettings($settings)->getModel());
    }

    public function testEachProviderUsesItsOwnGenerationParameters(): void
    {
        $settings = $this->settingsWith([
            'bot_llm_api_key' => 'deepseek-key',
            'bot_llm_model' => 'deepseek-v4-pro',
            'bot_llm_temperature' => '0.7',
            'bot_llm_max_tokens' => '1200',
            'bot_openai_api_key' => 'openai-key',
            'bot_openai_model' => 'gpt-5.4-nano',
            'bot_openai_temperature' => '1.4',
            'bot_openai_max_tokens' => '2000',
        ]);

        $deepseek = LlmService::fromSettings($settings, 'deepseek');
        $openai = LlmService::fromSettings($settings, 'openai');

        // Two providers configured at the same time, neither reading the other's
        // values.
        $this->assertSame('deepseek-v4-pro', $deepseek->getModel());
        $this->assertSame(1200, $deepseek->getMaxTokens());
        $this->assertSame('gpt-5.4-nano', $openai->getModel());
        $this->assertSame(2000, $openai->getMaxTokens());
    }

    public function testAFakeUserCanOverrideTheProviderAndModel(): void
    {
        $settings = $this->settingsWith([
            'bot_llm_provider' => 'deepseek',
            'bot_llm_api_key' => 'deepseek-key',
            'bot_openai_api_key' => 'openai-key',
        ]);

        $bot = LlmService::forFakeUser(
            ['bot_llm_provider' => 'openai', 'bot_llm_model' => 'gpt-5.4-nano'],
            $settings
        );
        $other = LlmService::forFakeUser(['bot_llm_provider' => '', 'bot_llm_model' => ''], $settings);

        // Two bots, two providers, at the same time.
        $this->assertSame('openai', $bot->getProvider());
        $this->assertSame('gpt-5.4-nano', $bot->getModel());
        $this->assertSame('deepseek', $other->getProvider());
    }

    // ------------------------------------------------------------------
    // Request shape
    // ------------------------------------------------------------------

    public function testEachProviderGetsItsOwnTokenParameter(): void
    {
        $deepseek = new CapturingLlm(['provider' => 'deepseek', 'api_key' => 'k', 'max_tokens' => 700]);
        $deepseek->chat('sys', [['role' => 'user', 'content' => 'geia']]);

        $openai = new CapturingLlm(['provider' => 'openai', 'api_key' => 'k', 'max_tokens' => 700]);
        $openai->chat('sys', [['role' => 'user', 'content' => 'hi']]);

        $this->assertSame(700, $deepseek->lastPayload['max_tokens'] ?? null);
        $this->assertArrayNotHasKey('max_completion_tokens', $deepseek->lastPayload);

        $this->assertSame(700, $openai->lastPayload['max_completion_tokens'] ?? null);
        $this->assertArrayNotHasKey('max_tokens', $openai->lastPayload);
    }

    /**
     * thinking:{type:disabled} is DeepSeek's switch; sending it to a provider that
     * does not document one is a 400.
     */
    public function testTheReasoningSwitchIsOnlySentWhereItIsDocumented(): void
    {
        $deepseek = new CapturingLlm(['provider' => 'deepseek', 'api_key' => 'k', 'reasoning' => 'false']);
        $deepseek->chat('sys', [['role' => 'user', 'content' => 'geia']]);
        $this->assertSame(['type' => 'disabled'], $deepseek->lastPayload['thinking'] ?? null);

        $openai = new CapturingLlm(['provider' => 'openai', 'api_key' => 'k', 'reasoning' => 'false']);
        $openai->chat('sys', [['role' => 'user', 'content' => 'hi']]);
        $this->assertArrayNotHasKey('thinking', $openai->lastPayload);
    }

    public function testAParameterTheApiRejectsIsRetriedWithoutIt(): void
    {
        // Providers rename these over time; one retry beats failing every reply
        // until someone edits the code.
        $llm = new RejectingLlm(
            ['provider' => 'openai', 'api_key' => 'k'],
            "Unsupported parameter: 'max_completion_tokens' is not supported"
        );

        $this->expectOutputRegex('/./');
        error_log('');

        $result = $llm->chat('sys', [['role' => 'user', 'content' => 'hi']]);

        $this->assertSame('fine', $result['text']);
        $this->assertSame(2, $llm->attempts);
        $this->assertArrayHasKey('max_tokens', $llm->lastPayload, 'it should retry with the other name');
        $this->assertArrayNotHasKey('max_completion_tokens', $llm->lastPayload);
    }

    public function testATemperatureTheApiRejectsIsDropped(): void
    {
        $llm = new RejectingLlm(
            ['provider' => 'openai', 'api_key' => 'k'],
            "Unsupported value: 'temperature' does not support 1.0 with this model"
        );

        $this->expectOutputRegex('/./');
        error_log('');

        $llm->chat('sys', [['role' => 'user', 'content' => 'hi']]);

        $this->assertArrayNotHasKey('temperature', $llm->lastPayload);
    }

    public function testAnUnrelatedErrorIsNotRetried(): void
    {
        $llm = new RejectingLlm(['provider' => 'openai', 'api_key' => 'k'], 'Incorrect API key provided', 401);

        try {
            $llm->chat('sys', [['role' => 'user', 'content' => 'hi']]);
            $this->fail('a 401 must not be retried');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Incorrect API key', $e->getMessage());
            $this->assertSame(1, $llm->attempts);
        }
    }

    // ------------------------------------------------------------------
    // Cost across providers
    // ------------------------------------------------------------------

    /**
     * DeepSeek reports the cache split directly; OpenAI reports only the cached
     * count. Both have to end up costed, or the panel shows a free call.
     */
    public function testOpenAiCachedTokensAreCostedFromItsOwnUsageShape(): void
    {
        $pricing = new LlmPricing();

        $cost = $pricing->cost('gpt-5.4-mini', [
            'prompt_tokens' => 1000,
            'prompt_tokens_details' => ['cached_tokens' => 800],
            'completion_tokens' => 100,
        ]);

        // 800 cached * 0.075 + 200 uncached * 0.75 + 100 out * 4.50, per 1M
        $this->assertSame(0.00066, round((float) $cost, 8));

        $split = LlmPricing::splitPromptTokens([
            'prompt_tokens' => 1000,
            'prompt_tokens_details' => ['cached_tokens' => 800],
        ]);
        $this->assertSame(['hit' => 800, 'miss' => 200], $split);
    }

    /**
     * @param array<string,string> $values
     */
    private function settingsWith(array $values): SettingsService
    {
        return new class ($values) extends SettingsService {
            /** @param array<string,string> $values */
            public function __construct(private array $values)
            {
                parent::__construct();
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->values[$key] ?? ($default === null ? '' : $default);
            }
        };
    }
}

/**
 * Records the payload instead of sending it.
 */
class CapturingLlm extends LlmService
{
    /** @var array<string,mixed> */
    public array $lastPayload = [];

    protected function post(string $path, array $payload): array
    {
        $this->lastPayload = $payload;

        return [
            'choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'ok']]],
            'usage' => ['total_tokens' => 5],
        ];
    }
}

/**
 * Fails the first attempt with a given API error, then succeeds - so the
 * parameter-retry logic can be exercised without network access.
 */
class RejectingLlm extends LlmService
{
    public int $attempts = 0;

    /** @var array<string,mixed> */
    public array $lastPayload = [];

    /**
     * @param array<string,mixed> $overrides
     */
    public function __construct(array $overrides, private string $error, private int $status = 400)
    {
        parent::__construct($overrides);
    }

    protected function post(string $path, array $payload): array
    {
        $this->attempts++;
        $this->lastPayload = $payload;

        if ($this->attempts === 1) {
            $this->lastStatus = $this->status;

            throw new \RuntimeException("LLM request failed with HTTP {$this->status}: {$this->error}");
        }

        return [
            'choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'fine']]],
            'usage' => ['total_tokens' => 5],
        ];
    }
}
