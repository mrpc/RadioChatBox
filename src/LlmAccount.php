<?php

namespace RadioChatBox;

use PDO;

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

    /** How often the worker records a balance snapshot. */
    public const SNAPSHOT_INTERVAL = 3600;

    private SettingsService $settings;
    private LlmService $llm;
    private string $provider;
    /** @var array<string,mixed> */
    private array $providerConfig;
    private ?\Redis $redis = null;

    public function __construct(?SettingsService $settings = null, ?LlmService $llm = null, ?string $provider = null)
    {
        $this->settings = $settings ?? new SettingsService();
        $this->llm = $llm ?? LlmService::fromSettings($this->settings, $provider);
        $this->provider = $this->llm->getProvider();
        $this->providerConfig = LlmProviders::config($this->provider);

        try {
            $this->redis = Database::getRedis();
        } catch (\Throwable) {
            // Caching is an optimisation; without Redis every call goes upstream.
            $this->redis = null;
        }
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
            error_log('LlmAccount::balance failed: ' . $e->getMessage());

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
            error_log('LlmAccount::models failed: ' . $e->getMessage());

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
            $stmt = Database::getPDO()->prepare(
                'INSERT INTO bot_llm_balance (provider, currency, total_balance, granted_balance, topped_up_balance)
                 VALUES (:provider, :currency, :total, :granted, :topped_up)'
            );
            $stmt->execute([
                'provider' => $this->provider,
                'currency' => $balance['currency'],
                'total' => $balance['total'],
                'granted' => $balance['granted'],
                'topped_up' => $balance['topped_up'],
            ]);
        } catch (\Throwable $e) {
            error_log('LlmAccount::snapshot failed: ' . $e->getMessage());

            return null;
        }

        return $balance;
    }

    private function snapshotIsDue(): bool
    {
        try {
            $stmt = Database::getPDO()->prepare('SELECT MAX(created_at) FROM bot_llm_balance WHERE provider = ?');
            $stmt->execute([$this->provider]);
            $last = $stmt->fetchColumn();
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
        try {
            $stmt = Database::getPDO()->prepare(
                'SELECT created_at, currency, total_balance
                 FROM bot_llm_balance
                 WHERE created_at > NOW() - make_interval(hours => :hours)
                   AND provider = :provider
                 ORDER BY created_at ASC'
            );
            $stmt->bindValue(':hours', max(1, $hours), PDO::PARAM_INT);
            $stmt->bindValue(':provider', $this->provider);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('LlmAccount::realSpend failed: ' . $e->getMessage());

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
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function cacheGet(string $key): ?array
    {
        if ($this->redis === null) {
            return null;
        }

        try {
            $raw = $this->redis->get(Database::getRedisPrefix() . $key);
        } catch (\Throwable) {
            return null;
        }

        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string,mixed> $value
     */
    private function cacheSet(string $key, array $value, int $ttl): void
    {
        if ($this->redis === null) {
            return;
        }

        try {
            $this->redis->setex(Database::getRedisPrefix() . $key, $ttl, (string) json_encode($value));
        } catch (\Throwable) {
            // Not worth failing a request over.
        }
    }
}
