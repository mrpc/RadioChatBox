<?php

namespace RadioChatBox\Services;

use Pramnos\Cache\FlatCache;

/**
 * Lightweight spam detection for public chat: catches a user posting the SAME
 * message repeatedly in a short window (the most common flooding pattern). State
 * lives in the cache (Redis) as a per-user, per-message rolling counter, so it is
 * cheap and self-expiring. Gated by the spam_detection_enabled setting.
 *
 * This complements — does not replace — the IP rate limit and slow mode.
 */
class SpamGuard
{
    private const DEFAULT_THRESHOLD = 3;   // identical messages within the window
    private const DEFAULT_WINDOW    = 30;  // seconds

    private SettingsService $settings;

    public function __construct(?SettingsService $settings = null)
    {
        $this->settings = $settings ?? new SettingsService();
    }

    /** Whether the feature is switched on. */
    public function isEnabled(): bool
    {
        return $this->settings->get('spam_detection_enabled', 'false') === 'true';
    }

    /**
     * Record this message and report whether it trips the duplicate-spam rule.
     * Returns true when the user has now sent the same message more than the
     * configured number of times inside the window (i.e. this send is spam).
     * A no-op returning false when the feature is off or the message is empty.
     */
    public function isDuplicateSpam(string $username, string $message): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        $normalized = $this->normalize($message);
        if ($normalized === '') {
            return false;
        }

        $threshold = max(2, (int) $this->settings->get('spam_duplicate_threshold', self::DEFAULT_THRESHOLD));
        $window = max(2, (int) $this->settings->get('spam_window_seconds', self::DEFAULT_WINDOW));

        $key = 'spam:dup:' . mb_strtolower(trim($username)) . ':' . md5($normalized);
        try {
            $count = (int) FlatCache::default()->increment($key, 1, $window);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            // If the counter backend is unavailable, don't block legitimate chat.
            return false;
        }
        // @codeCoverageIgnoreEnd

        return $count > $threshold;
    }

    /** Normalise a message for duplicate comparison (case/whitespace-insensitive). */
    private function normalize(string $message): string
    {
        $message = mb_strtolower(trim($message));
        return (string) preg_replace('/\s+/u', ' ', $message);
    }
}
