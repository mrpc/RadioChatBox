<?php

namespace RadioChatBox;

/**
 * The LLM providers the bots can use.
 *
 * Everything provider-specific lives here so adding one is a matter of adding an
 * entry, not of touching the call path: which endpoint, which key setting, how the
 * token budget is named, whether reasoning can be switched off, whether a balance
 * can be read, and how to tell a chat model from the rest of the catalogue.
 *
 * They are all OpenAI-compatible /chat/completions endpoints, which is why raw
 * cURL is enough and no SDK is needed.
 *
 * Selected globally (bot_llm_provider) and overridable per fake user
 * (fake_users.bot_llm_provider), so different bots can run on different providers
 * at the same time.
 */
class LlmProviders
{
    public const DEFAULT_PROVIDER = 'deepseek';

    /**
     * A pseudo-provider for the global setting only: run on every provider,
     * choosing one per conversation. It is not a real endpoint, so it is never a
     * valid per-bot override — only a valid value of the global bot_llm_provider.
     */
    public const BOTH = 'both';

    /**
     * @var array<string,array<string,mixed>>
     */
    public const PROVIDERS = [
        'deepseek' => [
            'label' => 'DeepSeek',
            'base_url' => 'https://api.deepseek.com',
            'api_key_setting' => 'bot_llm_api_key',
            'base_url_setting' => 'bot_llm_base_url',
            'api_key_url' => 'https://platform.deepseek.com/',
            // Every provider carries its own full parameter set, so all of them
            // stay configured at once and bots on different providers run
            // independently. DeepSeek keeps the original bot_llm_* keys.
            'settings' => [
                'api_key' => 'bot_llm_api_key',
                'base_url' => 'bot_llm_base_url',
                'model' => 'bot_llm_model',
                'temperature' => 'bot_llm_temperature',
                'max_tokens' => 'bot_llm_max_tokens',
                'reasoning' => 'bot_llm_reasoning',
            ],
            'models_path' => '/models',
            // Reports the remaining balance, so real spend needs no estimating.
            'balance_path' => '/user/balance',
            'costs_path' => null,
            'admin_key_setting' => null,
            'token_param' => 'max_tokens',
            // thinking:{type:disabled} is how v4-* reasoning is switched off.
            'reasoning_param' => 'thinking',
            'supports_temperature' => true,
            'model_pattern' => '/^deepseek/i',
            'default_model' => 'deepseek-v4-flash',
            'models' => [
                'deepseek-v4-flash' => 'DeepSeek V4 Flash — fast and cheap, fits short chat replies',
                'deepseek-v4-pro' => 'DeepSeek V4 Pro — more capable, slower and pricier',
            ],
        ],
        'openai' => [
            'label' => 'OpenAI',
            'base_url' => 'https://api.openai.com/v1',
            'api_key_setting' => 'bot_openai_api_key',
            'base_url_setting' => 'bot_openai_base_url',
            'api_key_url' => 'https://platform.openai.com/api-keys',
            'settings' => [
                'api_key' => 'bot_openai_api_key',
                'base_url' => 'bot_openai_base_url',
                'model' => 'bot_openai_model',
                'temperature' => 'bot_openai_temperature',
                'max_tokens' => 'bot_openai_max_tokens',
                // No documented reasoning switch, so there is nothing to configure.
                'reasoning' => null,
                // Costs need an organisation-level admin key (sk-admin-...), which
                // is deliberately not the key used for chat requests.
                'admin_key' => 'bot_openai_admin_key',
            ],
            'models_path' => '/models',
            // No endpoint returns the remaining credit, but GET /organization/costs
            // returns real money spent - which is the figure that matters.
            'balance_path' => null,
            'costs_path' => '/organization/costs',
            'admin_key_setting' => 'bot_openai_admin_key',
            'token_param' => 'max_completion_tokens',
            // No documented switch we can rely on, so reasoning is left to the
            // model's own default rather than sending a parameter that may 400.
            'reasoning_param' => null,
            'supports_temperature' => true,
            // The catalogue also carries embeddings, audio and image models.
            'model_pattern' => '/^(gpt-|o[0-9])/i',
            'model_exclude_pattern' => '/(audio|realtime|image|vision-preview|transcribe|tts|search|embedding|moderation)/i',
            'default_model' => 'gpt-5.4-mini',
            'models' => [
                'gpt-5.6-sol' => 'GPT-5.6 Sol — most capable, priciest',
                'gpt-5.6-terra' => 'GPT-5.6 Terra — balanced',
                'gpt-5.6-luna' => 'GPT-5.6 Luna — cheaper, quick',
                'gpt-5.5' => 'GPT-5.5',
                'gpt-5.4' => 'GPT-5.4',
                'gpt-5.4-mini' => 'GPT-5.4 mini — cheap, fine for one-line chat',
                'gpt-5.4-nano' => 'GPT-5.4 nano — cheapest',
            ],
        ],
    ];

    /**
     * Provider ids with their labels, for a dropdown.
     *
     * @return array<string,string>
     */
    public static function available(): array
    {
        return array_map(
            static fn (array $provider): string => (string) $provider['label'],
            self::PROVIDERS
        );
    }

    public static function isKnown(string $provider): bool
    {
        return isset(self::PROVIDERS[$provider]);
    }

    /**
     * Valid values for the global provider setting: any real provider, or the
     * "both" pseudo-provider. Per-bot overrides must still be a real provider.
     */
    public static function isValidGlobalSelection(string $provider): bool
    {
        return $provider === self::BOTH || self::isKnown($provider);
    }

    /**
     * Configuration for a provider, falling back to the default one so a bad
     * value can never leave the caller without an endpoint to talk to.
     *
     * @return array<string,mixed>
     */
    public static function config(string $provider): array
    {
        return self::PROVIDERS[$provider] ?? self::PROVIDERS[self::DEFAULT_PROVIDER];
    }

    /**
     * Resolve the provider id: an explicit choice, else the configured default.
     */
    public static function resolve(?string $provider, ?SettingsService $settings = null): string
    {
        $provider = trim((string) $provider);

        if ($provider !== '' && self::isKnown($provider)) {
            return $provider;
        }

        if ($provider === '' && $settings !== null) {
            $configured = trim((string) $settings->get('bot_llm_provider', ''));
            if ($configured !== '' && self::isKnown($configured)) {
                return $configured;
            }
        }

        return self::DEFAULT_PROVIDER;
    }

    /**
     * The models this provider is known to serve, id => label.
     *
     * @return array<string,string>
     */
    public static function models(string $provider): array
    {
        return self::config($provider)['models'];
    }

    public static function defaultModel(string $provider): string
    {
        return (string) self::config($provider)['default_model'];
    }

    /**
     * Which setting holds this provider's credentials. Each provider has its own,
     * so switching back and forth does not mean re-entering a key.
     */
    public static function apiKeySetting(string $provider): string
    {
        return (string) self::config($provider)['api_key_setting'];
    }

    public static function baseUrlSetting(string $provider): string
    {
        return (string) self::config($provider)['base_url_setting'];
    }

    /**
     * The settings that configure one provider, as parameter => setting key. A
     * null key means the provider has no such knob (OpenAI and reasoning).
     *
     * @return array<string,string|null>
     */
    public static function settingKeys(string $provider): array
    {
        return self::config($provider)['settings'];
    }

    /**
     * The setting key for one parameter of one provider, or null when it does not
     * apply.
     */
    public static function settingKey(string $provider, string $parameter): ?string
    {
        return self::settingKeys($provider)[$parameter] ?? null;
    }

    /**
     * Whether this provider can report what was actually spent, and with which
     * credentials. OpenAI exposes costs (not a balance) and only to an admin key.
     */
    public static function costsPath(string $provider): ?string
    {
        return self::config($provider)['costs_path'];
    }

    public static function adminKeySetting(string $provider): ?string
    {
        return self::config($provider)['admin_key_setting'];
    }

    /**
     * Every setting key across all providers, for the admin whitelist.
     *
     * @return list<string>
     */
    public static function allSettingKeys(): array
    {
        $keys = [];
        foreach (self::PROVIDERS as $config) {
            foreach ($config['settings'] as $key) {
                if ($key !== null) {
                    $keys[] = $key;
                }
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Whether an id from the provider's catalogue is a chat model we can use.
     * OpenAI in particular also lists embeddings, audio and image models.
     */
    public static function isChatModel(string $provider, string $modelId): bool
    {
        $config = self::config($provider);

        if (!preg_match((string) $config['model_pattern'], $modelId)) {
            return false;
        }

        $exclude = $config['model_exclude_pattern'] ?? null;

        return $exclude === null || !preg_match((string) $exclude, $modelId);
    }

    /**
     * Every model across all providers, for validating a stored value and for
     * pricing lookups.
     *
     * @return array<string,string> model id => label
     */
    public static function allModels(): array
    {
        $models = [];
        foreach (self::PROVIDERS as $provider) {
            $models += $provider['models'];
        }

        return $models;
    }

    /**
     * Which provider serves a model id, or null when nothing claims it (a custom
     * endpoint, or a model newer than this table).
     */
    public static function providerForModel(string $model): ?string
    {
        foreach (self::PROVIDERS as $id => $config) {
            if (isset($config['models'][$model])) {
                return $id;
            }
        }

        return null;
    }
}
