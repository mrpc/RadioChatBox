<?php

namespace RadioChatBox\Services;

/**
 * Automatic moderation driven by the report queue: once a user has accumulated a
 * configurable number of PENDING reports against them, act automatically
 * (timeout or nickname ban). Staff (moderator+) are always exempt. Every action
 * is written to the moderation log with an `auto_*` verb so it is distinguishable
 * from a human moderator's action and shows up in the audit trail.
 *
 * Escalation rule: "N pending reports => auto action" (N and the action are
 * settings). Idempotent — a user already timed out / banned is not re-actioned.
 */
class AutoModService
{
    private SettingsService $settings;
    private ChatService $chat;

    public function __construct(?SettingsService $settings = null, ?ChatService $chat = null)
    {
        $this->settings = $settings ?? new SettingsService();
        $this->chat = $chat ?? new ChatService();
    }

    /**
     * Evaluate a user after a new report was filed against them. Returns a short
     * description of the action taken, or null when nothing was done (feature off,
     * below threshold, staff-exempt, or already actioned).
     */
    public function onReport(string $reportedUsername): ?string
    {
        $reportedUsername = trim($reportedUsername);
        if ($reportedUsername === '') {
            return null;
        }
        if ($this->settings->get('automod_enabled', 'false') !== 'true') {
            return null;
        }

        // Staff are never auto-moderated.
        if ($this->isStaff($reportedUsername)) {
            return null;
        }

        $threshold = max(2, (int) $this->settings->get('automod_report_threshold', 5));
        $pending = $this->pendingReportCount($reportedUsername);
        if ($pending < $threshold) {
            return null;
        }

        $action = (string) $this->settings->get('automod_action', 'timeout');

        if ($action === 'ban') {
            // Idempotent: don't re-ban an already-banned nickname.
            if ($this->chat->nicknameIsBanned($reportedUsername)) {
                return null;
            }
            $this->chat->banNickname(
                $reportedUsername,
                "auto-mod: {$pending} pending reports",
                'auto-mod'
            );
            $this->log('auto_ban', $reportedUsername, "{$pending} pending reports (threshold {$threshold})");
            return "auto-banned {$reportedUsername}";
        }

        // Default: timeout. Skip if the user is already timed out.
        if ($this->chat->getTimeoutRemaining($reportedUsername) > 0) {
            return null;
        }
        $minutes = max(1, (int) $this->settings->get('automod_timeout_minutes', 60));
        $this->chat->timeoutUser($reportedUsername, $minutes * 60);
        $this->log('auto_timeout', $reportedUsername, "{$pending} pending reports (threshold {$threshold}, {$minutes}m)");
        return "auto-timed-out {$reportedUsername} for {$minutes}m";
    }

    /** Count of pending reports filed against a user. */
    private function pendingReportCount(string $username): int
    {
        try {
            return (new ReportService())->countPendingAgainst($username);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('AutoModService::pendingReportCount failed: ' . $e->getMessage(), 'radiochatbox');
            return 0;
        }
        // @codeCoverageIgnoreEnd
    }

    /** Whether the user is a moderator or above (exempt from auto-mod). */
    private function isStaff(string $username): bool
    {
        try {
            $row = \Pramnos\Database\Database::getInstance()->preparedQuery(
                'SELECT usertype FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1',
                [$username]
            );
            $usertype = $row ? $row->fetchColumn() : false;
            return $usertype !== false && (int) $usertype >= Authz::MODERATOR;
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('AutoModService::isStaff failed: ' . $e->getMessage(), 'radiochatbox');
            return false;
        }
        // @codeCoverageIgnoreEnd
    }

    private function log(string $action, string $target, string $details): void
    {
        try {
            (new ModerationLog())->record('auto-mod', $action, $target, $details);
        } catch (\Throwable $e) {
            // Non-fatal.
        }
    }
}
