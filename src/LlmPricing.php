<?php

namespace RadioChatBox;

use RadioChatBox\Services\SettingsService;
/**
 * Turns token counts into money.
 *
 * Token counts alone don't say much: for deepseek-v4-flash a cached input token
 * is 50x cheaper than an uncached one, so two calls with identical token counts
 * can differ wildly in cost. The three buckets the provider bills separately are
 * therefore priced separately.
 *
 * The provider exposes NO pricing endpoint - /models returns ids and owners only
 * - so the unit prices have to be configured. They live in the bot_llm_prices
 * setting (editable in the admin panel, so a price change never needs a deploy)
 * and SEED_PRICES below is only the value that setting starts out with. For what
 * was actually spent, in real money, use LlmAccount::balance() - that comes from
 * the provider.
 *
 * @see LlmAccount for the authoritative balance and the live model list
 */
class LlmPricing
{
    public const CURRENCY = 'USD';

    /**
     * USD per 1M tokens. A starting point for the setting, not a source of truth:
     * if a provider changes prices, edit the setting.
     *
     * DeepSeek: https://api-docs.deepseek.com/quick_start/pricing
     * OpenAI:   https://developers.openai.com/api/docs/pricing
     * (both checked 2026-07-25)
     *
     * @var array<string,array{cache_hit:float,cache_miss:float,output:float}>
     */
    public const SEED_PRICES = [
        // DeepSeek
        'deepseek-v4-flash' => ['cache_hit' => 0.0028, 'cache_miss' => 0.14, 'output' => 0.28],
        'deepseek-v4-pro' => ['cache_hit' => 0.003625, 'cache_miss' => 0.435, 'output' => 0.87],
        // OpenAI
        'gpt-5.6-sol' => ['cache_hit' => 0.50, 'cache_miss' => 5.00, 'output' => 30.00],
        'gpt-5.6-terra' => ['cache_hit' => 0.25, 'cache_miss' => 2.50, 'output' => 15.00],
        'gpt-5.6-luna' => ['cache_hit' => 0.10, 'cache_miss' => 1.00, 'output' => 6.00],
        'gpt-5.5' => ['cache_hit' => 0.50, 'cache_miss' => 5.00, 'output' => 30.00],
        'gpt-5.4' => ['cache_hit' => 0.25, 'cache_miss' => 2.50, 'output' => 15.00],
        'gpt-5.4-mini' => ['cache_hit' => 0.075, 'cache_miss' => 0.75, 'output' => 4.50],
        'gpt-5.4-nano' => ['cache_hit' => 0.02, 'cache_miss' => 0.20, 'output' => 1.25],
    ];

    private const BUCKETS = ['cache_hit', 'cache_miss', 'output'];

    /** @var array<string,array{cache_hit:float,cache_miss:float,output:float}> */
    private array $prices;

    private string $currency;

    /**
     * @param array<string,array<string,float>>|null $prices Null uses the seed
     */
    public function __construct(?array $prices = null, string $currency = self::CURRENCY)
    {
        $this->prices = $prices ?? self::SEED_PRICES;
        $this->currency = $currency;
    }

    /**
     * Prices as configured for this installation.
     */
    public static function fromSettings(?SettingsService $settings = null): self
    {
        $settings ??= new SettingsService();

        $configured = self::parse((string) $settings->get('bot_llm_prices', ''));

        return new self(
            $configured,
            trim((string) $settings->get('bot_llm_currency', '')) ?: self::CURRENCY
        );
    }

    /**
     * Parse the setting. Returns null when empty or unusable, so the caller falls
     * back to the seed rather than silently pricing everything at zero.
     *
     * @return array<string,array{cache_hit:float,cache_miss:float,output:float}>|null
     */
    public static function parse(string $json): ?array
    {
        $json = trim($json);
        if ($json === '') {
            return null;
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded) || $decoded === []) {
            return null;
        }

        $prices = [];
        foreach ($decoded as $model => $buckets) {
            if (!is_string($model) || !is_array($buckets)) {
                return null;
            }

            $entry = [];
            foreach (self::BUCKETS as $bucket) {
                if (!isset($buckets[$bucket]) || !is_numeric($buckets[$bucket]) || (float) $buckets[$bucket] < 0) {
                    return null;
                }
                $entry[$bucket] = (float) $buckets[$bucket];
            }

            $prices[$model] = $entry;
        }

        return $prices;
    }

    /**
     * Whether a price table is valid, for rejecting a bad admin edit instead of
     * storing it and quietly costing everything at zero.
     */
    public static function isValid(string $json): bool
    {
        return trim($json) === '' || self::parse($json) !== null;
    }

    /**
     * The seed table as the admin panel shows it: prefilled when the setting is
     * empty, so the prices in force are always visible and editable.
     */
    public static function seedJson(): string
    {
        return (string) json_encode(self::SEED_PRICES, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    /**
     * @return array<string,array{cache_hit:float,cache_miss:float,output:float}>
     */
    public function all(): array
    {
        return $this->prices;
    }

    /**
     * Prices for a model, or null when it has none configured (a custom endpoint
     * or a model added after the prices were last edited).
     *
     * @return array{cache_hit:float,cache_miss:float,output:float}|null
     */
    public function forModel(string $model): ?array
    {
        return $this->prices[$model] ?? null;
    }

    /**
     * Cost of one call, or null when the model has no configured price - null
     * means "unknown", which must not be displayed as a confident 0.
     *
     * @param array<string,mixed> $usage The provider's usage object
     */
    public function cost(string $model, array $usage): ?float
    {
        $prices = $this->forModel($model);

        if ($prices === null) {
            return null;
        }

        ['hit' => $cacheHit, 'miss' => $cacheMiss] = self::splitPromptTokens($usage);
        $output = (int) ($usage['completion_tokens'] ?? 0);

        return (($cacheHit * $prices['cache_hit'])
            + ($cacheMiss * $prices['cache_miss'])
            + ($output * $prices['output'])) / 1_000_000;
    }

    /**
     * Split the prompt tokens into cached and uncached, whichever way the provider
     * reports it.
     *
     * DeepSeek gives the split directly (prompt_cache_hit_tokens /
     * prompt_cache_miss_tokens); OpenAI gives only the cached count
     * (prompt_tokens_details.cached_tokens) and the rest is uncached. A provider
     * that reports neither billed everything as uncached, which is how it must be
     * costed - not as free.
     *
     * @param array<string,mixed> $usage
     *
     * @return array{hit:int,miss:int}
     */
    public static function splitPromptTokens(array $usage): array
    {
        $prompt = (int) ($usage['prompt_tokens'] ?? 0);
        $hit = (int) ($usage['prompt_cache_hit_tokens'] ?? 0);
        $miss = (int) ($usage['prompt_cache_miss_tokens'] ?? 0);

        if ($hit === 0 && $miss === 0) {
            $hit = (int) ($usage['prompt_tokens_details']['cached_tokens'] ?? 0);
            $miss = max(0, $prompt - $hit);
        }

        return ['hit' => $hit, 'miss' => $miss];
    }

    /**
     * Money for humans. One reply costs a fraction of a cent, so a plain
     * two-decimal format would show every call as $0.00.
     */
    public static function format(?float $cost, string $currency = self::CURRENCY): string
    {
        if ($cost === null) {
            return '-';
        }

        $symbol = $currency === 'USD' ? '$' : ($currency === 'CNY' ? '¥' : $currency . ' ');

        if ($cost === 0.0) {
            return $symbol . '0';
        }

        if ($cost < 0.01) {
            return $symbol . rtrim(rtrim(number_format($cost, 6, '.', ''), '0'), '.');
        }

        return $symbol . number_format($cost, 2, '.', '');
    }
}
