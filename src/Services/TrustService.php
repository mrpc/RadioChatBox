<?php

namespace RadioChatBox\Services;

use Pramnos\Cache\FlatCache;
use Pramnos\Database\Database as PramnosDatabase;

/**
 * Decides whether a chat user is "trusted" — a registered account, or a guest who
 * has been around long enough (posted at least a configurable number of public
 * messages). Trust is used to relax anti-spam controls for established users while
 * keeping brand-new / throwaway nicknames on a tighter leash. The result is cached
 * briefly so it doesn't cost a query per message.
 */
class TrustService
{
    private const CACHE_TTL = 300; // 5 minutes

    private PramnosDatabase $db;
    private SettingsService $settings;

    public function __construct(?SettingsService $settings = null)
    {
        $this->db = PramnosDatabase::getInstance();
        $this->settings = $settings ?? new SettingsService();
    }

    /**
     * Whether the user is trusted. Registered users are always trusted; guests
     * become trusted once their public message count reaches the threshold.
     */
    public function isTrusted(string $username): bool
    {
        $username = trim($username);
        if ($username === '') {
            return false;
        }

        $cacheKey = 'trust:' . mb_strtolower($username);
        try {
            $cached = FlatCache::default()->get($cacheKey);
            if ($cached !== null) {
                return (bool) $cached;
            }
        } catch (\Throwable $e) {
            // fall through to compute
        }

        $trusted = $this->compute($username);

        try {
            FlatCache::default()->set($cacheKey, $trusted ? 1 : 0, self::CACHE_TTL);
        } catch (\Throwable $e) {
            // caching is best-effort
        }
        return $trusted;
    }

    private function compute(string $username): bool
    {
        // Registered account → trusted.
        try {
            $row = $this->db->preparedQuery(
                'SELECT 1 FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1',
                [$username]
            );
            if ($row && $row->fetchColumn() !== false) {
                return true;
            }
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('TrustService user check failed: ' . $e->getMessage(), 'radiochatbox');
        }
        // @codeCoverageIgnoreEnd

        $threshold = max(1, (int) $this->settings->get('trust_message_threshold', 20));
        try {
            $count = (int) $this->db->preparedQuery(
                'SELECT COUNT(*) FROM chat_messages WHERE LOWER(username) = LOWER(?) AND is_deleted = FALSE',
                [$username]
            )->fetchColumn();
            return $count >= $threshold;
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('TrustService count check failed: ' . $e->getMessage(), 'radiochatbox');
            return false;
        }
        // @codeCoverageIgnoreEnd
    }
}
