<?php

namespace RadioChatBox;

use PDO;

/**
 * Request/response log for LLM calls.
 *
 * Exists because a bad reply is otherwise undiagnosable: replies were arriving
 * cut off mid-word and the reason (the whole token budget being spent on
 * reasoning, `finish_reason: length`, empty content) was only visible in the
 * provider's response, which nothing kept.
 *
 * The prompt contains the conversation, which is already stored in
 * private_messages, so this adds no new class of data - but it does duplicate
 * it, hence the retention window and the off switch.
 */
class LlmLog
{
    public const DEFAULT_RETENTION_DAYS = 7;

    private PDO $pdo;
    private SettingsService $settings;
    private ?LlmPricing $pricing = null;

    public function __construct(?SettingsService $settings = null, ?LlmPricing $pricing = null)
    {
        $this->pdo = Database::getPDO();
        $this->settings = $settings ?? new SettingsService();
        $this->pricing = $pricing;
    }

    /**
     * The configured unit prices, loaded once per instance.
     */
    private function pricing(): LlmPricing
    {
        return $this->pricing ??= LlmPricing::fromSettings($this->settings);
    }

    public function isEnabled(): bool
    {
        return $this->settings->get('bot_llm_log_enabled', 'true') !== 'false';
    }

    /**
     * Record one call. Never throws: logging must not break a reply.
     *
     * @param array<string,mixed> $entry
     */
    public function record(array $entry): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO bot_llm_log
                    (fake_nickname, peer_username, purpose, provider, model, endpoint, system_prompt,
                     messages, max_tokens, temperature, reasoning, http_status, finish_reason,
                     reply, usage, duration_ms, error, cost, currency)
                 VALUES
                    (:fake_nickname, :peer_username, :purpose, :provider, :model, :endpoint, :system_prompt,
                     :messages, :max_tokens, :temperature, :reasoning, :http_status, :finish_reason,
                     :reply, :usage, :duration_ms, :error, :cost, :currency)'
            );

            $stmt->bindValue(':fake_nickname', $entry['fake_nickname'] ?? null);
            $stmt->bindValue(':peer_username', $entry['peer_username'] ?? null);
            $stmt->bindValue(':purpose', (string) ($entry['purpose'] ?? 'reply'));
            $stmt->bindValue(':provider', $entry['provider'] ?? null);
            $stmt->bindValue(':model', (string) ($entry['model'] ?? ''));
            $stmt->bindValue(':endpoint', $entry['endpoint'] ?? null);
            $stmt->bindValue(':system_prompt', $entry['system_prompt'] ?? null);
            $stmt->bindValue(':messages', self::encode($entry['messages'] ?? null));
            $stmt->bindValue(':max_tokens', $entry['max_tokens'] ?? null, PDO::PARAM_INT);
            $stmt->bindValue(':temperature', $entry['temperature'] ?? null);
            $stmt->bindValue(':reasoning', (bool) ($entry['reasoning'] ?? false), PDO::PARAM_BOOL);
            $stmt->bindValue(':http_status', $entry['http_status'] ?? null, PDO::PARAM_INT);
            $stmt->bindValue(':finish_reason', $entry['finish_reason'] ?? null);
            $stmt->bindValue(':reply', $entry['reply'] ?? null);
            $stmt->bindValue(':usage', self::encode($entry['usage'] ?? null));
            $stmt->bindValue(':duration_ms', $entry['duration_ms'] ?? null, PDO::PARAM_INT);
            $stmt->bindValue(':error', $entry['error'] ?? null);

            // Costed at write time: the unit prices can be edited, and history must
            // keep what the call actually cost when it was made.
            $usage = is_array($entry['usage'] ?? null) ? $entry['usage'] : [];
            $pricing = $this->pricing();
            $stmt->bindValue(':cost', $pricing->cost((string) ($entry['model'] ?? ''), $usage));
            $stmt->bindValue(':currency', $pricing->getCurrency());

            $stmt->execute();
        } catch (\Throwable $e) {
            error_log('LlmLog::record failed: ' . $e->getMessage());
        }
    }

    /**
     * Most recent calls, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function recent(int $limit = 20, bool $problemsOnly = false): array
    {
        $sql = 'SELECT * FROM bot_llm_log';
        if ($problemsOnly) {
            $sql .= " WHERE error IS NOT NULL OR finish_reason <> 'stop'";
        }
        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', max(1, min(500, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Aggregate token spend and failures over the last $hours, for the admin
     * panel and for spotting a budget problem early.
     *
     * @return array<string,mixed>
     */
    public function summary(int $hours = 24): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS calls,
                    COUNT(*) FILTER (WHERE error IS NOT NULL) AS errors,
                    COUNT(*) FILTER (WHERE finish_reason = 'length') AS truncated,
                    COALESCE(SUM((usage->>'total_tokens')::int), 0) AS total_tokens,
                    COALESCE(SUM(((usage->'completion_tokens_details')->>'reasoning_tokens')::int), 0) AS reasoning_tokens,
                    COALESCE(SUM((usage->>'prompt_cache_hit_tokens')::int), 0) AS cache_hit_tokens,
                    COALESCE(SUM(cost), 0) AS cost,
                    COUNT(*) FILTER (WHERE cost IS NULL) AS uncosted_calls,
                    MAX(currency) AS currency,
                    ROUND(AVG(duration_ms)) AS avg_duration_ms
             FROM bot_llm_log
             WHERE created_at > NOW() - make_interval(hours => :hours)"
        );
        $stmt->bindValue(':hours', max(1, $hours), PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? [] : $row;
    }

    /**
     * The same figures as summary(), split by provider - so a panel running two
     * providers at once can say which one the money went to.
     *
     * @return array<string,array<string,mixed>> provider => totals
     */
    public function summaryByProvider(int $hours = 24): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(provider, 'unknown') AS provider,
                    COUNT(*) AS calls,
                    COUNT(*) FILTER (WHERE error IS NOT NULL) AS errors,
                    COUNT(*) FILTER (WHERE finish_reason = 'length') AS truncated,
                    COALESCE(SUM((usage->>'total_tokens')::int), 0) AS total_tokens,
                    COALESCE(SUM(cost), 0) AS cost,
                    COUNT(*) FILTER (WHERE cost IS NULL) AS uncosted_calls,
                    MAX(currency) AS currency
             FROM bot_llm_log
             WHERE created_at > NOW() - make_interval(hours => :hours)
             GROUP BY COALESCE(provider, 'unknown')
             ORDER BY SUM(cost) DESC NULLS LAST"
        );
        $stmt->bindValue(':hours', max(1, $hours), PDO::PARAM_INT);
        $stmt->execute();

        $byProvider = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byProvider[(string) $row['provider']] = $row;
        }

        return $byProvider;
    }

    /**
     * One log entry with its full request and response, for the admin panel.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM bot_llm_log WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Log entries for the admin list: newest first, without the bulky prompt and
     * message payloads (fetched per entry via find()).
     *
     * @return array{entries:list<array<string,mixed>>,total:int}
     */
    public function page(int $limit = 25, int $offset = 0, array $filters = []): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['problems_only'])) {
            $where[] = "(error IS NOT NULL OR finish_reason <> 'stop')";
        }
        if (!empty($filters['fake_nickname'])) {
            $where[] = 'fake_nickname = :fake_nickname';
            $params['fake_nickname'] = $filters['fake_nickname'];
        }
        if (!empty($filters['peer_username'])) {
            $where[] = 'peer_username = :peer_username';
            $params['peer_username'] = $filters['peer_username'];
        }
        if (!empty($filters['purpose'])) {
            $where[] = 'purpose = :purpose';
            $params['purpose'] = $filters['purpose'];
        }

        $clause = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM bot_llm_log' . $clause);
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $stmt = $this->pdo->prepare(
            'SELECT id, created_at, fake_nickname, peer_username, purpose, provider, model, reasoning,
                    max_tokens, http_status, finish_reason, messages, reply, usage, duration_ms, error,
                    cost, currency
             FROM bot_llm_log' . $clause . '
             ORDER BY created_at DESC, id DESC
             LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', max(1, min(200, $limit)), PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        return ['entries' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    }

    /**
     * Drop entries past the retention window. Returns the number deleted.
     */
    public function prune(?int $days = null): int
    {
        $days = $days ?? (int) $this->settings->get('bot_llm_log_retention_days', self::DEFAULT_RETENTION_DAYS);
        $days = max(1, min(365, $days));

        $stmt = $this->pdo->prepare(
            'DELETE FROM bot_llm_log WHERE created_at < NOW() - make_interval(days => :days)'
        );
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * @param array<mixed>|null $value
     */
    private static function encode(?array $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? null : $json;
    }
}
