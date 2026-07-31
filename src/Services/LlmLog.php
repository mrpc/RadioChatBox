<?php

namespace RadioChatBox\Services;

use RadioChatBox\Services\SettingsService;
use Pramnos\Database\Database as PramnosDatabase;

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

    private PramnosDatabase $db;
    private SettingsService $settings;
    private ?LlmPricing $pricing = null;

    public function __construct(?SettingsService $settings = null, ?LlmPricing $pricing = null)
    {
        $this->db = PramnosDatabase::getInstance();
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
            // Costed at write time: the unit prices can be edited, and history must
            // keep what the call actually cost when it was made.
            $usage = is_array($entry['usage'] ?? null) ? $entry['usage'] : [];
            $pricing = $this->pricing();

            $this->db->queryBuilder()->from('bot_llm_log')->insert([
                'fake_nickname' => $entry['fake_nickname'] ?? null,
                'peer_username' => $entry['peer_username'] ?? null,
                'purpose'       => (string) ($entry['purpose'] ?? 'reply'),
                'provider'      => $entry['provider'] ?? null,
                'model'         => (string) ($entry['model'] ?? ''),
                'endpoint'      => $entry['endpoint'] ?? null,
                'system_prompt' => $entry['system_prompt'] ?? null,
                'messages'      => self::encode($entry['messages'] ?? null),
                'max_tokens'    => $entry['max_tokens'] ?? null,
                'temperature'   => $entry['temperature'] ?? null,
                'reasoning'     => (bool) ($entry['reasoning'] ?? false),
                'http_status'   => $entry['http_status'] ?? null,
                'finish_reason' => $entry['finish_reason'] ?? null,
                'reply'         => $entry['reply'] ?? null,
                'usage'         => self::encode($entry['usage'] ?? null),
                'duration_ms'   => $entry['duration_ms'] ?? null,
                'error'         => $entry['error'] ?? null,
                'cost'          => $pricing->cost((string) ($entry['model'] ?? ''), $usage),
                'currency'      => $pricing->getCurrency(),
            ]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('LlmLog::record failed: ' . $e->getMessage(), 'radiochatbox');
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Most recent calls, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function recent(int $limit = 20, bool $problemsOnly = false): array
    {
        $qb = $this->db->queryBuilder()->from('bot_llm_log');
        if ($problemsOnly) {
            $qb->whereRaw("(error IS NOT NULL OR finish_reason <> 'stop')");
        }

        return $qb->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->limit(max(1, min(500, $limit)))
            ->getAll();
    }

    /**
     * Aggregate token spend and failures over the last $hours, for the admin
     * panel and for spotting a budget problem early.
     *
     * @return array<string,mixed>
     */
    public function summary(int $hours = 24): array
    {
        // Verbatim: PostgreSQL aggregate with FILTER, jsonb extraction and
        // make_interval — kept raw (a QueryBuilder rewrite would obscure it).
        $result = $this->db->preparedQuery(
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
             WHERE created_at > NOW() - make_interval(hours => :hours)",
            ['hours' => max(1, $hours)]
        );

        $row = $result ? $result->fetch() : null;

        return ($row === null || $row === false) ? [] : $row;
    }

    /**
     * The same figures as summary(), split by provider - so a panel running two
     * providers at once can say which one the money went to.
     *
     * @return array<string,array<string,mixed>> provider => totals
     */
    public function summaryByProvider(int $hours = 24): array
    {
        // Verbatim: same PG aggregate as summary(), grouped by provider.
        $result = $this->db->preparedQuery(
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
             ORDER BY SUM(cost) DESC NULLS LAST",
            ['hours' => max(1, $hours)]
        );

        $byProvider = [];
        foreach (($result ? $result->fetchAll() : []) as $row) {
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
        $row = $this->db->queryBuilder()
            ->from('bot_llm_log')
            ->where('id', '=', $id)
            ->first();

        return ($row && $row->numRows > 0) ? $row->fields : null;
    }

    /**
     * Log entries for the admin list: newest first, without the bulky prompt and
     * message payloads (fetched per entry via find()).
     *
     * @return array{entries:list<array<string,mixed>>,total:int}
     */
    public function page(int $limit = 25, int $offset = 0, array $filters = []): array
    {
        // Apply the same optional filters to both the COUNT and the page query.
        $applyFilters = static function ($qb) use ($filters) {
            if (!empty($filters['problems_only'])) {
                $qb->whereRaw("(error IS NOT NULL OR finish_reason <> 'stop')");
            }
            if (!empty($filters['fake_nickname'])) {
                $qb->where('fake_nickname', '=', $filters['fake_nickname']);
            }
            if (!empty($filters['peer_username'])) {
                $qb->where('peer_username', '=', $filters['peer_username']);
            }
            if (!empty($filters['purpose'])) {
                $qb->where('purpose', '=', $filters['purpose']);
            }
            return $qb;
        };

        $total = $applyFilters($this->db->queryBuilder()->from('bot_llm_log'))->count();

        $entries = $applyFilters(
            $this->db->queryBuilder()
                ->from('bot_llm_log')
                ->select([
                    'id', 'created_at', 'fake_nickname', 'peer_username', 'purpose', 'provider',
                    'model', 'reasoning', 'max_tokens', 'http_status', 'finish_reason', 'messages',
                    'reply', 'usage', 'duration_ms', 'error', 'cost', 'currency',
                ])
        )
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->limit(max(1, min(200, $limit)))
            ->offset(max(0, $offset))
            ->getAll();

        return ['entries' => $entries, 'total' => $total];
    }

    /**
     * Drop entries past the retention window. Returns the number deleted.
     */
    public function prune(?int $days = null): int
    {
        $days = $days ?? (int) $this->settings->get('bot_llm_log_retention_days', self::DEFAULT_RETENTION_DAYS);
        $days = max(1, min(365, $days));

        $result = $this->db->queryBuilder()
            ->from('bot_llm_log')
            ->whereRaw('created_at < NOW() - make_interval(days => %s)', [$days])
            ->delete();

        return $result ? $result->getAffectedRows() : 0;
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
