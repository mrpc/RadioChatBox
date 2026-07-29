<?php

namespace RadioChatBox\Services;

use Pramnos\Cache\FlatCache;
use Pramnos\Database\Database;
use RadioChatBox\Services\SettingsService;
use RadioChatBox\Services\LlmService;
/**
 * What the provider itself reports: the remaining balance and the models it
 * currently serves.
 *
 * This is the part of the cost picture that is not an estimate. The per-call cost
 * in the log is computed from configured unit prices (LlmPricing) because no
 * pricing endpoint exists; the balance here is real money straight from
 * GET /user/balance, and comparing the two shows when the configured prices have
 * gone stale.
 *
 * Balance snapshots are kept so "spent in the last 24h" can be answered in real
 * money: the drop between two snapshots is actual spend. A rise means the account
 * was topped up, which starts a new baseline rather than counting as negative
 * spend.
 *
 * Both calls are cached, since they are hit on every dashboard load while the
 * numbers move slowly (and /user/balance is rate-limited on the provider's side).
 */
class LlmAccount
{
    public const BALANCE_TTL = 300;
    public const MODELS_TTL = 21600;
    public const COSTS_TTL = 600;

    /** How often the worker records a balance snapshot. */
    public const SNAPSHOT_INTERVAL = 3600;

    private SettingsService $settings;
    private LlmService $llm;
    private string $provider;
    private ?LlmService $adminLlm = null;
    /** @var array<string,mixed> */
    private array $providerConfig;

    /**
     * @param LlmService|null $llm      Client for the public endpoints
     * @param LlmService|null $adminLlm Client for the organisation endpoints, which
     *                                  need a different credential (see adminClient())
     */
    public function __construct(
        ?SettingsService $settings = null,
        ?LlmService $llm = null,
        ?string $provider = null,
        ?LlmService $adminLlm = null
    ) {
        $this->adminLlm = $adminLlm;

        $this->settings = $settings ?? new SettingsService();
        $this->llm = $llm ?? LlmService::fromSettings($this->settings, $provider);
        // An explicit choice wins: the caller asked about a specific provider, which
        // may not be the one an injected client happens to be built for.
        $this->provider = $provider !== null
            ? LlmProviders::resolve($provider, $this->settings)
            : $this->llm->getProvider();
        $this->providerConfig = LlmProviders::config($this->provider);
    }

    public function isConfigured(): bool
    {
        return $this->llm->isConfigured();
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    /**
     * Whether this provider reports a balance at all. OpenAI does not, so the
     * panel must say "not reported" rather than showing a failure.
     */
    public function supportsBalance(): bool
    {
        return $this->providerConfig['balance_path'] !== null;
    }

    /**
     * Whether this provider can report what was actually spent. OpenAI can
     * (GET /organization/costs) even though it reports no balance - but only to an
     * organisation admin key, which is a different credential from the chat key.
     */
    public function supportsCosts(): bool
    {
        return LlmProviders::costsPath($this->provider) !== null;
    }

    public function hasAdminKey(): bool
    {
        $setting = LlmProviders::adminKeySetting($this->provider);

        return $setting !== null && trim((string) $this->settings->get($setting, '')) !== '';
    }

    /**
     * Real money spent over the last $hours, straight from the provider's own
     * accounting rather than from our unit prices.
     *
     * Returns null when the provider has no such endpoint, no admin key is
     * configured, or the window is shorter than the smallest bucket the API offers
     * (a day) - a partial answer here would read as "cheaper than it is".
     *
     * @return array{spent:float,currency:string,days:int,source:string}|null
     */
    public function providerCosts(int $hours = 24): ?array
    {
        if (!$this->supportsCosts() || !$this->hasAdminKey()) {
            return null;
        }

        $days = (int) ceil(max(1, $hours) / 24);
        $key = 'llm:costs:' . $this->provider . ':' . $days;

        $cached = $this->cacheGet($key);
        if ($cached !== null) {
            return $cached;
        }

        // Buckets are daily and cut at UTC midnight, and start_time selects the bucket
        // that CONTAINS it - so "now minus 24h" returns yesterday's bucket, which for
        // the first day of use is empty. Align to midnight and ask for one bucket per
        // day, today included, or the answer reads as "spent nothing".
        $startTime = strtotime('today midnight UTC') - (($days - 1) * 86400);
        $path = LlmProviders::costsPath($this->provider)
            . '?start_time=' . $startTime
            . '&bucket_width=1d&limit=' . min(180, $days);

        try {
            $response = $this->adminClient()->get($path);
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('LlmAccount::providerCosts failed: ' . $e->getMessage(), 'radiochatbox');

            return null;
        }

        $spent = 0.0;
        $currency = LlmPricing::CURRENCY;

        foreach ($response['data'] ?? [] as $bucket) {
            foreach ($bucket['results'] ?? [] as $result) {
                $amount = $result['amount'] ?? null;
                if (!is_array($amount)) {
                    continue;
                }
                $spent += (float) ($amount['value'] ?? 0);
                $currency = strtoupper((string) ($amount['currency'] ?? $currency));
            }
        }

        $costs = [
            'spent' => $spent,
            'currency' => $currency,
            'days' => $days,
            'source' => 'provider',
        ];

        $this->cacheSet($key, $costs, self::COSTS_TTL);

        return $costs;
    }

    /**
     * What has been spent since the 1st of the month - the figure that maps to the
     * bill, for a provider that publishes no balance.
     *
     * @return array{spent:float,currency:string,days:int,source:string}|null
     */
    public function monthToDateCosts(): ?array
    {
        // Day of month, so the aligned window starts on the 1st.
        return $this->providerCosts(((int) gmdate('j')) * 24);
    }

    /**
     * A client authenticated with the organisation admin key, for the endpoints that
     * refuse a project key.
     */
    private function adminClient(): LlmService
    {
        if ($this->adminLlm !== null) {
            return $this->adminLlm;
        }

        $setting = (string) LlmProviders::adminKeySetting($this->provider);

        return new LlmService([
            'provider' => $this->provider,
            'api_key' => (string) $this->settings->get($setting, ''),
            'base_url' => (string) $this->settings->get(LlmProviders::baseUrlSetting($this->provider), ''),
        ]);
    }

    /**
     * Remaining balance, or null when it cannot be fetched (no key, provider
     * error, or an endpoint that does not implement it). Never throws: a missing
     * balance must not break the page it is shown on.
     *
     * @return array{currency:string,total:float,granted:float,topped_up:float,is_available:bool,fetched_at:int}|null
     */
    public function balance(bool $fresh = false): ?array
    {
        if (!$this->isConfigured() || !$this->supportsBalance()) {
            return null;
        }

        $key = 'llm:balance:' . $this->provider;

        if (!$fresh) {
            $cached = $this->cacheGet($key);
            if ($cached !== null) {
                return $cached;
            }
        }

        try {
            $response = $this->llm->get((string) $this->providerConfig['balance_path']);
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('LlmAccount::balance failed: ' . $e->getMessage(), 'radiochatbox');

            return null;
        }

        $info = $response['balance_infos'][0] ?? null;
        if (!is_array($info)) {
            return null;
        }

        // The provider may report several currencies; prefer the configured one.
        $preferred = trim((string) $this->settings->get('bot_llm_currency', '')) ?: LlmPricing::CURRENCY;
        foreach ($response['balance_infos'] as $candidate) {
            if (is_array($candidate) && ($candidate['currency'] ?? '') === $preferred) {
                $info = $candidate;
                break;
            }
        }

        $balance = [
            'currency' => (string) ($info['currency'] ?? LlmPricing::CURRENCY),
            'total' => (float) ($info['total_balance'] ?? 0),
            'granted' => (float) ($info['granted_balance'] ?? 0),
            'topped_up' => (float) ($info['topped_up_balance'] ?? 0),
            'is_available' => (bool) ($response['is_available'] ?? true),
            'fetched_at' => time(),
        ];

        $this->cacheSet($key, $balance, self::BALANCE_TTL);

        return $balance;
    }

    /**
     * The models the provider currently serves, as id => label. Falls back to the
     * built-in list when the API cannot be reached, so the admin dropdown is never
     * empty - but a live list means a model being retired (as deepseek-chat was)
     * shows up without a code change.
     *
     * @return array{models:array<string,string>,source:string}
     */
    public function models(bool $fresh = false): array
    {
        $fallback = ['models' => LlmProviders::models($this->provider), 'source' => 'built-in'];

        if (!$this->isConfigured()) {
            return $fallback;
        }

        $key = 'llm:models:' . $this->provider;

        if (!$fresh) {
            $cached = $this->cacheGet($key);
            if ($cached !== null) {
                return $cached;
            }
        }

        try {
            $response = $this->llm->get((string) $this->providerConfig['models_path']);
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('LlmAccount::models failed: ' . $e->getMessage(), 'radiochatbox');

            return $fallback;
        }

        $known = LlmProviders::models($this->provider);
        $models = [];

        foreach ($response['data'] ?? [] as $model) {
            $id = trim((string) ($model['id'] ?? ''));

            // The catalogue also lists embeddings, audio and image models.
            if ($id === '' || !LlmProviders::isChatModel($this->provider, $id)) {
                continue;
            }

            // Keep the descriptions we have for known ids; the API supplies none.
            $models[$id] = $known[$id] ?? $id;
        }

        if ($models === []) {
            return $fallback;
        }

        $result = ['models' => $models, 'source' => 'api'];
        $this->cacheSet($key, $result, self::MODELS_TTL);

        return $result;
    }

    /**
     * Record the current balance, at most once per SNAPSHOT_INTERVAL, so real
     * spend over a period can be measured. Returns the balance it stored, or null
     * when it skipped or could not fetch.
     *
     * @return array<string,mixed>|null
     */
    public function snapshot(bool $force = false): ?array
    {
        if (!$force && !$this->snapshotIsDue()) {
            return null;
        }

        $balance = $this->balance(true);
        if ($balance === null) {
            return null;
        }

        try {
            Database::getInstance()->queryBuilder()->from('bot_llm_balance')->insert([
                'provider'          => $this->provider,
                'currency'          => $balance['currency'],
                'total_balance'     => $balance['total'],
                'granted_balance'   => $balance['granted'],
                'topped_up_balance' => $balance['topped_up'],
            ]);
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('LlmAccount::snapshot failed: ' . $e->getMessage(), 'radiochatbox');

            return null;
        }

        return $balance;
    }

    private function snapshotIsDue(): bool
    {
        try {
            $last = Database::getInstance()->queryBuilder()
                ->from('bot_llm_balance')
                ->where('provider', '=', $this->provider)
                ->max('created_at');
        } catch (\Throwable) {
            return false;
        }

        if (!$last) {
            return true;
        }

        return (time() - strtotime((string) $last)) >= self::SNAPSHOT_INTERVAL;
    }

    /**
     * Real money spent over the last $hours, measured from the balance snapshots:
     * the sum of the drops between consecutive readings. Top-ups (a rise) are
     * reported separately instead of cancelling out spend.
     *
     * Returns null when there is not enough history yet to measure anything.
     *
     * @return array{spent:float,topped_up:float,currency:string,from:string,to:string,readings:int}|null
     */
    public function realSpend(int $hours = 24): ?array
    {
        // A provider that reports costs itself beats anything we can derive.
        if (!$this->supportsBalance()) {
            $costs = $this->providerCosts($hours);

            return $costs === null ? null : [
                'spent' => $costs['spent'],
                'topped_up' => 0.0,
                'currency' => $costs['currency'],
                'from' => '',
                'to' => '',
                'readings' => 0,
                'source' => 'provider',
            ];
        }

        try {
            $rows = Database::getInstance()->queryBuilder()
                ->from('bot_llm_balance')
                ->select(['created_at', 'currency', 'total_balance'])
                ->whereRaw('created_at > NOW() - make_interval(hours => %s)', [max(1, $hours)])
                ->where('provider', '=', $this->provider)
                ->orderBy('created_at', 'asc')
                ->getAll();
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('LlmAccount::realSpend failed: ' . $e->getMessage(), 'radiochatbox');

            return null;
        }

        if (count($rows) < 2) {
            return null;
        }

        $spent = 0.0;
        $toppedUp = 0.0;

        for ($i = 1, $n = count($rows); $i < $n; $i++) {
            $delta = (float) $rows[$i - 1]['total_balance'] - (float) $rows[$i]['total_balance'];

            if ($delta > 0) {
                $spent += $delta;
            } else {
                $toppedUp += -$delta;
            }
        }

        return [
            'spent' => $spent,
            'topped_up' => $toppedUp,
            'currency' => (string) $rows[0]['currency'],
            'from' => (string) $rows[0]['created_at'],
            'to' => (string) $rows[count($rows) - 1]['created_at'],
            'readings' => count($rows),
            'source' => 'balance',
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function cacheGet(string $key): ?array
    {
        // FlatCache serialises, so the array round-trips as-is; a miss (or Redis
        // being unavailable) returns null and the caller falls back to source.
        $value = FlatCache::default()->get($key);

        return is_array($value) ? $value : null;
    }

    /**
     * @param array<string,mixed> $value
     */
    private function cacheSet(string $key, array $value, int $ttl): void
    {
        // Caching is an optimisation; FlatCache no-ops gracefully without Redis.
        FlatCache::default()->set($key, $value, $ttl);
    }
}
