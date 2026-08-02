<?php

namespace RadioChatBox\Services;

use Pramnos\Database\Database as PramnosDatabase;

/**
 * Escalating moderator warnings. Each warning is a row; warnings may expire.
 * When a user reaches the configured number of ACTIVE warnings they are
 * auto-timed-out (threshold + duration come from settings). Complements the
 * report queue and manual timeout.
 */
class WarningService
{
    private PramnosDatabase $db;
    private SettingsService $settings;

    public function __construct(?SettingsService $settings = null)
    {
        $this->db = PramnosDatabase::getInstance();
        $this->settings = $settings ?? new SettingsService();
    }

    /**
     * Record a warning against a user. If this pushes their active-warning count
     * to the configured threshold, auto-timeout them.
     *
     * @return array{warning_id:int, active_count:int, auto_timed_out:bool}
     * @throws \InvalidArgumentException on an empty username.
     */
    public function warn(string $username, string $moderator, ?string $reason = null): array
    {
        $username = trim($username);
        if ($username === '') {
            throw new \InvalidArgumentException('username is required');
        }

        $result = $this->db->queryBuilder()->from('user_warnings')->returning('id')->insert([
            'username'  => mb_substr($username, 0, 50),
            'moderator' => mb_substr($moderator !== '' ? $moderator : 'admin', 0, 100),
            'reason'    => ($reason !== null && trim($reason) !== '') ? mb_substr(trim($reason), 0, 1000) : null,
        ]);
        $warningId = ($result && isset($result->fields['id'])) ? (int) $result->fields['id'] : 0;

        $activeCount = $this->activeCount($username);
        $autoTimedOut = false;

        [$threshold, $minutes] = $this->autoTimeoutConfig();
        if ($threshold > 0 && $activeCount >= $threshold) {
            (new ChatService())->timeoutUser($username, $minutes * 60);
            $autoTimedOut = true;
            try {
                (new ModerationLog())->record(
                    $moderator !== '' ? $moderator : 'system',
                    'auto_timeout',
                    $username,
                    "auto-timeout after {$activeCount} warnings ({$minutes}m)"
                );
            } catch (\Throwable $e) {
                // Non-fatal.
            }
        }

        return ['warning_id' => $warningId, 'active_count' => $activeCount, 'auto_timed_out' => $autoTimedOut];
    }

    /** Count of a user's currently-active (non-expired) warnings. */
    public function activeCount(string $username): int
    {
        $username = trim($username);
        if ($username === '') {
            return 0;
        }
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $row = $this->db->preparedQuery(
            'SELECT COUNT(*) AS c FROM user_warnings
             WHERE username = :u AND (expires_at IS NULL OR expires_at > :now)',
            ['u' => $username, 'now' => $now]
        );
        $val = $row ? $row->fetchColumn() : 0;
        return (int) $val;
    }

    /**
     * A user's warnings, newest first (for the admin dossier).
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(string $username, int $limit = 50): array
    {
        $username = trim($username);
        if ($username === '') {
            return [];
        }
        return $this->db->queryBuilder()
            ->from('user_warnings')
            ->where('username', '=', $username)
            ->orderBy('created_at', 'desc')
            ->limit(max(1, min($limit, 200)))
            ->getAll();
    }

    /** Remove a warning by id (mistaken warning). Returns whether id was valid. */
    public function remove(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        $this->db->queryBuilder()->from('user_warnings')->where('id', '=', $id)->delete();
        return true;
    }

    /**
     * The auto-timeout threshold (0 = disabled) and duration in minutes, read
     * from settings with sane defaults.
     *
     * @return array{0:int, 1:int}
     */
    private function autoTimeoutConfig(): array
    {
        $threshold = $this->settings->get('warning_auto_timeout_threshold');
        $minutes = $this->settings->get('warning_auto_timeout_minutes');
        $threshold = ($threshold === null || $threshold === '') ? 3 : (int) $threshold;
        $minutes = ($minutes === null || $minutes === '') ? 60 : (int) $minutes;
        return [max(0, $threshold), max(1, $minutes)];
    }
}
