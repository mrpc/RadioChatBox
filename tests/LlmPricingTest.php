<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RadioChatBox\LlmPricing;
use RadioChatBox\SettingsService;

/**
 * Covers turning token counts into money.
 *
 * The unit prices are a setting rather than a constant because the provider
 * publishes no pricing endpoint (/models returns ids and owners only), so a price
 * change must be applicable without a deploy - and an unparseable table must not
 * end up silently pricing every call at zero.
 */
class LlmPricingTest extends TestCase
{
    // ------------------------------------------------------------------
    // Cost
    // ------------------------------------------------------------------

    public function testCachedInputIsBilledFarCheaperThanUncached(): void
    {
        $pricing = new LlmPricing([
            'm' => ['cache_hit' => 0.0028, 'cache_miss' => 0.14, 'output' => 0.28],
        ]);

        $cached = $pricing->cost('m', [
            'prompt_tokens' => 1000,
            'prompt_cache_hit_tokens' => 1000,
            'prompt_cache_miss_tokens' => 0,
            'completion_tokens' => 0,
        ]);
        $uncached = $pricing->cost('m', [
            'prompt_tokens' => 1000,
            'prompt_cache_hit_tokens' => 0,
            'prompt_cache_miss_tokens' => 1000,
            'completion_tokens' => 0,
        ]);

        // 1000 tokens: 1000 * 0.0028 / 1M vs 1000 * 0.14 / 1M
        $this->assertSame(0.0000028, $cached);
        $this->assertSame(0.00014, $uncached);
        $this->assertSame(50.0, round($uncached / $cached, 6), 'the cache discount is what makes prompt order matter');
    }

    public function testAllThreeBucketsAreAddedUp(): void
    {
        $pricing = new LlmPricing([
            'm' => ['cache_hit' => 1.0, 'cache_miss' => 10.0, 'output' => 100.0],
        ]);

        $cost = $pricing->cost('m', [
            'prompt_tokens' => 1_000_000,
            'prompt_cache_hit_tokens' => 600_000,
            'prompt_cache_miss_tokens' => 400_000,
            'completion_tokens' => 20_000,
        ]);

        // 0.6 * 1 + 0.4 * 10 + 0.02 * 100
        $this->assertSame(6.6, round((float) $cost, 6));
    }

    /**
     * A provider that reports no cache split billed it all as uncached, so that is
     * how it has to be costed - not as free.
     */
    public function testUsageWithoutACacheSplitIsBilledAsUncached(): void
    {
        $pricing = new LlmPricing(['m' => ['cache_hit' => 0.0, 'cache_miss' => 1.0, 'output' => 0.0]]);

        $this->assertSame(
            0.001,
            $pricing->cost('m', ['prompt_tokens' => 1000, 'completion_tokens' => 0])
        );
    }

    public function testAnUnpricedModelCostsNullRatherThanZero(): void
    {
        $pricing = new LlmPricing(['known' => ['cache_hit' => 1.0, 'cache_miss' => 1.0, 'output' => 1.0]]);

        // Null means "unknown": showing 0 would read as a free call.
        $this->assertNull($pricing->cost('some-other-model', ['prompt_tokens' => 5000]));
        $this->assertNull($pricing->forModel('some-other-model'));
        $this->assertNotNull($pricing->cost('known', ['prompt_tokens' => 5000]));
    }

    public function testMissingUsageFieldsCostNothingRatherThanFailing(): void
    {
        $pricing = new LlmPricing();

        $this->assertSame(0.0, $pricing->cost('deepseek-v4-flash', []));
    }

    // ------------------------------------------------------------------
    // The configured price table
    // ------------------------------------------------------------------

    public function testTheSeedTableCoversTheModelsOnOffer(): void
    {
        foreach (array_keys(\RadioChatBox\BotService::MODELS) as $model) {
            $this->assertNotNull(
                (new LlmPricing())->forModel($model),
                "a model offered in the admin dropdown needs a price: {$model}"
            );
        }
    }

    public function testAValidTableIsParsed(): void
    {
        $parsed = LlmPricing::parse('{"x": {"cache_hit": 0.1, "cache_miss": "0.2", "output": 3}}');

        $this->assertSame(['x' => ['cache_hit' => 0.1, 'cache_miss' => 0.2, 'output' => 3.0]], $parsed);
    }

    /**
     * @return list<array{0:string}>
     */
    public static function badTables(): array
    {
        return [
            ['not json'],
            ['[]'],
            ['{}'],
            ['{"x": 5}'],
            ['{"x": {"cache_hit": 0.1}}'],
            ['{"x": {"cache_hit": 0.1, "cache_miss": 0.2}}'],
            ['{"x": {"cache_hit": "abc", "cache_miss": 0.2, "output": 3}}'],
            ['{"x": {"cache_hit": -1, "cache_miss": 0.2, "output": 3}}'],
        ];
    }

    #[DataProvider('badTables')]
    public function testAnUnusableTableIsRejectedRatherThanPricingAtZero(string $json): void
    {
        $this->assertNull(LlmPricing::parse($json), "must not accept: {$json}");
        $this->assertFalse(LlmPricing::isValid($json));
    }

    public function testAnEmptyTableMeansUseTheBuiltInOne(): void
    {
        $this->assertNull(LlmPricing::parse('  '));
        $this->assertTrue(LlmPricing::isValid(''), 'clearing the field falls back to the built-in prices');
        $this->assertSame(LlmPricing::SEED_PRICES, (new LlmPricing(null))->all());
    }

    public function testTheSeedTableIsOfferedAsEditableJson(): void
    {
        $seed = LlmPricing::seedJson();

        $this->assertSame(LlmPricing::SEED_PRICES, LlmPricing::parse($seed));
        $this->assertStringContainsString("\n", $seed, 'the admin panel shows it, so it should be readable');
    }

    public function testConfiguredPricesReplaceTheBuiltInOnes(): void
    {
        $settings = new class extends SettingsService {
            public function get(string $key, mixed $default = null): mixed
            {
                return match ($key) {
                    'bot_llm_prices' => '{"deepseek-v4-flash": {"cache_hit": 9, "cache_miss": 9, "output": 9}}',
                    'bot_llm_currency' => 'CNY',
                    default => $default,
                };
            }
        };

        $pricing = LlmPricing::fromSettings($settings);

        $this->assertSame(9.0, $pricing->forModel('deepseek-v4-flash')['output']);
        $this->assertSame('CNY', $pricing->getCurrency());
    }

    public function testAnUnusableSettingFallsBackToTheBuiltInPrices(): void
    {
        $settings = new class extends SettingsService {
            public function get(string $key, mixed $default = null): mixed
            {
                return $key === 'bot_llm_prices' ? 'garbage' : $default;
            }
        };

        // Falling back beats costing everything at zero.
        $this->assertSame(LlmPricing::SEED_PRICES, LlmPricing::fromSettings($settings)->all());
        $this->assertSame('USD', LlmPricing::fromSettings($settings)->getCurrency());
    }

    // ------------------------------------------------------------------
    // Display
    // ------------------------------------------------------------------

    public function testSmallAmountsStayVisible(): void
    {
        // A single reply costs a fraction of a cent; 2 decimals would show $0.00.
        $this->assertSame('$0.000022', LlmPricing::format(0.0000221));
        $this->assertSame('$0.000484', LlmPricing::format(0.000484));
        $this->assertSame('$1.25', LlmPricing::format(1.2543));
        $this->assertSame('$0', LlmPricing::format(0.0));
        $this->assertSame('-', LlmPricing::format(null), 'unknown must not look like free');
        $this->assertSame('¥0.50', LlmPricing::format(0.5, 'CNY'));
        $this->assertSame('EUR 0.50', LlmPricing::format(0.5, 'EUR'));
    }
}
