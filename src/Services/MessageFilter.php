<?php

namespace RadioChatBox\Services;

use Pramnos\Cache\FlatCache;
use Pramnos\Database\Database;
use RadioChatBox\Services\SettingsService;
class MessageFilter
{
    /**
     * Memoized "GIF feature enabled" flag. null = not yet resolved.
     * Held as a class property (not a function static) so tests can clear it
     * via resetCaches() after changing the gif_enabled setting.
     */
    private static ?bool $gifEnabledCache = null;

    /** Memoized profanity config: ['mode'=>string,'words'=>string[]]. */
    private static ?array $profanityCache = null;

    /**
     * Test seam: forget cached settings-derived state so a changed setting is
     * re-read on the next call. Mirrors Database::reset(); only needed in tests.
     */
    public static function resetCaches(): void
    {
        self::$gifEnabledCache = null;
        self::$profanityCache = null;
    }

    /**
     * Filter message for public chat
     * Replaces blocked content with ***
     */
    public static function filterPublicMessage(string $message): array
    {
        $originalMessage = $message;
        $replacements = [];

        // Protect GIF URLs from ALL filtering steps below. Their CDN paths hold
        // long digit runs (e.g. Klipy hex hashes) that the phone-number filter
        // would otherwise mangle, breaking the image. Restored at the end.
        $gifExtract = self::extractGifUrls($message);
        $message = $gifExtract['message'];
        $gifUrls = $gifExtract['map'];

        // First check for dangerous content (applies to all messages)
        $dangerousCheck = self::checkDangerousContent($message);
        if (!$dangerousCheck['safe']) {
            $message = self::replacePattern($message, $dangerousCheck['pattern'], '***');
            $replacements[] = $dangerousCheck['reason'];
        }

        // Check and replace URLs (except Tenor/Giphy GIF URLs)
        $urlCheck = self::replaceUrls($message);
        if ($urlCheck['replaced']) {
            $message = $urlCheck['message'];
            $replacements[] = 'URL removed';
        }

        // Check and replace phone numbers
        $phoneCheck = self::replacePhoneNumbers($message);
        if ($phoneCheck['replaced']) {
            $message = $phoneCheck['message'];
            $replacements[] = 'Phone number removed';
        }

        // Custom profanity filter (admin word list). In 'block' mode a match makes
        // the whole message not allowed; in 'mask' mode the words are starred out.
        $profanity = self::applyProfanityFilter($message);
        if ($profanity['blocked']) {
            $message = self::restoreGifUrls($message, $gifUrls);
            return [
                'allowed' => false,
                'reason' => 'Message blocked by the profanity filter',
                'filtered' => $message,
                'modified' => false,
                'replacements' => ['Profanity blocked'],
            ];
        }
        if ($profanity['modified']) {
            $message = $profanity['message'];
            $replacements[] = 'Profanity masked';
        }

        // Restore protected GIF URLs
        $message = self::restoreGifUrls($message, $gifUrls);

        return [
            'allowed' => true,
            'reason' => '',
            'filtered' => $message,
            'modified' => $message !== $originalMessage,
            'replacements' => $replacements
        ];
    }

    /**
     * Apply the admin-configured custom profanity word list.
     *
     * @return array{message:string, modified:bool, blocked:bool}
     */
    public static function applyProfanityFilter(string $message): array
    {
        $config = self::profanityConfig();
        if ($config['mode'] === 'off' || $config['words'] === []) {
            return ['message' => $message, 'modified' => false, 'blocked' => false];
        }

        // One alternation of word-bounded, case-insensitive terms. Unicode-aware so
        // Greek words match too; \b doesn't work for Greek, so we bound on
        // whitespace / start / end / punctuation via lookarounds.
        $escaped = array_map(static fn (string $w): string => preg_quote($w, '/'), $config['words']);
        $pattern = '/(?<![\p{L}\p{N}])(?:' . implode('|', $escaped) . ')(?![\p{L}\p{N}])/iu';

        if ($config['mode'] === 'block') {
            $blocked = preg_match($pattern, $message) === 1;
            return ['message' => $message, 'modified' => false, 'blocked' => $blocked];
        }

        // 'mask': replace each match with the same number of asterisks.
        $masked = preg_replace_callback(
            $pattern,
            static fn (array $m): string => str_repeat('*', max(1, mb_strlen($m[0]))),
            $message
        );
        $masked = $masked ?? $message;

        return ['message' => $masked, 'modified' => $masked !== $message, 'blocked' => false];
    }

    /**
     * Resolve and memoize the profanity mode + word list from settings.
     *
     * @return array{mode:string, words:string[]}
     */
    private static function profanityConfig(): array
    {
        if (self::$profanityCache !== null) {
            return self::$profanityCache;
        }
        try {
            $settings = new SettingsService();
            $mode = (string) $settings->get('profanity_filter_mode', 'off');
            if (!in_array($mode, ['off', 'mask', 'block'], true)) {
                $mode = 'off';
            }
            $raw = (string) $settings->get('profanity_words', '');
            // Words separated by commas or newlines; trimmed, de-duplicated, non-empty.
            $words = array_values(array_unique(array_filter(array_map(
                'trim',
                preg_split('/[\r\n,]+/', $raw) ?: []
            ), static fn (string $w): bool => $w !== '')));
            self::$profanityCache = ['mode' => $mode, 'words' => $words];
        } catch (\Throwable $e) {
            self::$profanityCache = ['mode' => 'off', 'words' => []];
        }
        return self::$profanityCache;
    }

    /**
     * Filter message for private chat
     * Blocks blacklisted URLs and dangerous content
     */
    public static function filterPrivateMessage(string $message, string $ipAddress = ''): array
    {
        $originalMessage = $message;
        $replacements = [];

        // Check for dangerous content
        $dangerousCheck = self::checkDangerousContent($message);
        if (!$dangerousCheck['safe']) {
            $message = self::replacePattern($message, $dangerousCheck['pattern'], '***');
            $replacements[] = $dangerousCheck['reason'];
        }

        // Check for blacklisted URLs
        $blacklistCheck = self::checkBlacklistedUrls($message, $ipAddress);
        if ($blacklistCheck['found']) {
            $message = $blacklistCheck['message'];
            $replacements[] = 'Blacklisted URL removed';
        }

        // Custom profanity filter. In DMs 'block' is downgraded to masking — a
        // private message is never silently dropped, just cleaned.
        $profanity = self::applyProfanityFilter($message);
        if ($profanity['blocked']) {
            // Re-run in mask semantics so the words are starred rather than blocked.
            $profanity = ['message' => self::maskProfanity($message), 'modified' => true, 'blocked' => false];
        }
        if ($profanity['modified']) {
            $message = $profanity['message'];
            $replacements[] = 'Profanity masked';
        }

        return [
            'allowed' => true,
            'reason' => '',
            'filtered' => $message,
            'modified' => $message !== $originalMessage,
            'replacements' => $replacements
        ];
    }

    /** Mask profanity in a string regardless of the configured mode. */
    private static function maskProfanity(string $message): string
    {
        $config = self::profanityConfig();
        if ($config['words'] === []) {
            return $message;
        }
        $escaped = array_map(static fn (string $w): string => preg_quote($w, '/'), $config['words']);
        $pattern = '/(?<![\p{L}\p{N}])(?:' . implode('|', $escaped) . ')(?![\p{L}\p{N}])/iu';
        return preg_replace_callback(
            $pattern,
            static fn (array $m): string => str_repeat('*', max(1, mb_strlen($m[0]))),
            $message
        ) ?? $message;
    }

    /**
     * Check for dangerous content (XSS, injection, etc.)
     * Returns pattern that matched for replacement
     */
    private static function checkDangerousContent(string $message): array
    {
        // Check for script tags
        if (preg_match('/<script\b[^>]*>(.*?)<\/script>/is', $message, $matches)) {
            return ['safe' => false, 'reason' => 'Script tags not allowed', 'pattern' => $matches[0]];
        }

        // Check for script event handlers
        $eventHandlers = [
            'onload', 'onerror', 'onclick', 'onmouseover', 'onmouseout',
            'onkeydown', 'onkeyup', 'onfocus', 'onblur', 'onchange',
            'onsubmit', 'ondblclick', 'oncontextmenu', 'oninput',
            'onmouseenter', 'onmouseleave', 'onwheel', 'oncopy', 'onpaste'
        ];
        
        foreach ($eventHandlers as $handler) {
            if (preg_match('/' . $handler . '\s*=/i', $message, $matches)) {
                return ['safe' => false, 'reason' => 'Event handlers not allowed', 'pattern' => $matches[0]];
            }
        }

        // Check for javascript: protocol
        if (preg_match('/javascript\s*:/i', $message, $matches)) {
            return ['safe' => false, 'reason' => 'JavaScript protocol not allowed', 'pattern' => $matches[0]];
        }

        // Check for data: URLs with HTML/JS
        if (preg_match('/data:text\/html/i', $message, $matches)) {
            return ['safe' => false, 'reason' => 'Data URLs not allowed', 'pattern' => $matches[0]];
        }

        // Check for style tags
        if (preg_match('/<style\b[^>]*>(.*?)<\/style>/is', $message, $matches)) {
            return ['safe' => false, 'reason' => 'Style tags not allowed', 'pattern' => $matches[0]];
        }

        // Check for iframe, object, embed tags
        if (preg_match('/<(iframe|object|embed|applet)\b[^>]*>/i', $message, $matches)) {
            return ['safe' => false, 'reason' => 'Embedded content not allowed', 'pattern' => $matches[0]];
        }

        // Check for meta tags
        if (preg_match('/<meta\b[^>]*>/i', $message, $matches)) {
            return ['safe' => false, 'reason' => 'Meta tags not allowed', 'pattern' => $matches[0]];
        }

        // Check for base tags
        if (preg_match('/<base\b[^>]*>/i', $message, $matches)) {
            return ['safe' => false, 'reason' => 'Base tags not allowed', 'pattern' => $matches[0]];
        }

        // Check for link tags
        if (preg_match('/<link\b[^>]*>/i', $message, $matches)) {
            return ['safe' => false, 'reason' => 'Link tags not allowed', 'pattern' => $matches[0]];
        }

        // Check for form tags
        if (preg_match('/<form\b[^>]*>/i', $message, $matches)) {
            return ['safe' => false, 'reason' => 'Form tags not allowed', 'pattern' => $matches[0]];
        }

        // Check for input/textarea/button tags
        if (preg_match('/<(input|textarea|button|select)\b[^>]*>/i', $message, $matches)) {
            return ['safe' => false, 'reason' => 'Form inputs not allowed', 'pattern' => $matches[0]];
        }

        return ['safe' => true, 'reason' => '', 'pattern' => ''];
    }
    
    /**
     * Extract Tenor/Giphy/Klipy GIF URLs into placeholders so that later
     * filtering steps (URL removal, phone-number stripping) can't corrupt them.
     * GIF CDN URLs contain long digit runs in their path (e.g. Klipy hex hashes)
     * that the phone-number filter would otherwise replace with ***.
     * Returns ['message' => $withPlaceholders, 'map' => [placeholder => url]].
     */
    private static function extractGifUrls(string $message): array
    {
        $gifUrls = [];

        if (self::isGifEnabled()) {
            $gifPattern = '/https?:\/\/(?:media\.tenor\.com|media[0-9]*\.giphy\.com|i\.giphy\.com|[a-z0-9-]+\.klipy\.com)\/[^\s]+\.gif/i';
            preg_match_all($gifPattern, $message, $gifMatches);
            foreach ($gifMatches[0] as $idx => $gifUrl) {
                $placeholder = "___GIFPLACEHOLDER{$idx}___";
                $gifUrls[$placeholder] = $gifUrl;
                $message = str_replace($gifUrl, $placeholder, $message);
            }
        }

        return ['message' => $message, 'map' => $gifUrls];
    }

    /**
     * Restore GIF URL placeholders created by extractGifUrls().
     */
    private static function restoreGifUrls(string $message, array $gifUrls): string
    {
        foreach ($gifUrls as $placeholder => $gifUrl) {
            $message = str_replace($placeholder, $gifUrl, $message);
        }
        return $message;
    }

    /**
     * Replace URLs in message
     */
    private static function replaceUrls(string $message): array
    {
        $originalMessage = $message;
        
        // Check if GIF feature is enabled
        $gifEnabled = self::isGifEnabled();
        
        // Extract Tenor, Giphy and Klipy GIF URLs to preserve them (only if GIF is enabled)
        $gifUrls = [];
        if ($gifEnabled) {
            $gifPattern = '/https?:\/\/(?:media\.tenor\.com|media[0-9]*\.giphy\.com|i\.giphy\.com|[a-z0-9-]+\.klipy\.com)\/[^\s]+\.gif/i';
            preg_match_all($gifPattern, $message, $gifMatches);
            if (!empty($gifMatches[0])) {
                foreach ($gifMatches[0] as $idx => $gifUrl) {
                    $placeholder = "___GIFPLACEHOLDER{$idx}___";
                    $gifUrls[$placeholder] = $gifUrl;
                    $message = str_replace($gifUrl, $placeholder, $message);
                }
            }
        }
        
        // Get whitelisted URL patterns
        $whitelistPatterns = self::getWhitelistedUrlPatterns();
        
        // Extract whitelisted URLs to preserve them
        $whitelistedUrls = [];
        if (!empty($whitelistPatterns)) {
            foreach ($whitelistPatterns as $pattern) {
                // Convert pattern to regex (support wildcards)
                $regexPattern = preg_quote($pattern, '/');
                $regexPattern = str_replace('\*', '.*', $regexPattern);
                $urlRegex = '/https?:\/\/(?:[a-z0-9-]+\.)*' . $regexPattern . '(?:\/[^\s]*)?/i';
                
                preg_match_all($urlRegex, $message, $matches);
                if (!empty($matches[0])) {
                    foreach ($matches[0] as $idx => $whitelistedUrl) {
                        $placeholder = "___WHITELIST{$idx}_{$pattern}___";
                        $whitelistedUrls[$placeholder] = $whitelistedUrl;
                        $message = str_replace($whitelistedUrl, $placeholder, $message);
                    }
                }
            }
        }
        
        // Pattern to match various URL formats (won't match placeholders)
        $urlPattern = '/\b(?:(?:https?|ftp|file):\/\/|www\.|ftp\.)[-A-Z0-9+&@#\/%=~_|$?!:,.]*[A-Z0-9+&@#\/%=~_|$]/i';
        $message = preg_replace($urlPattern, '***', $message);
        
        // Check for domain-like patterns (won't match placeholders due to underscores)
        $domainPattern = '/\b[a-z0-9]+([\-\.]{1}[a-z0-9]+)*\.(com|net|org|edu|gov|co|io|ai|app|dev|tech|info|biz|me|tv|cc|xyz|online|site|website|blog|shop|store)\b/i';
        $message = preg_replace($domainPattern, '***', $message);
        
        // Restore whitelisted URLs
        foreach ($whitelistedUrls as $placeholder => $whitelistedUrl) {
            $message = str_replace($placeholder, $whitelistedUrl, $message);
        }
        
        // Restore GIF URLs (only if GIF is enabled)
        foreach ($gifUrls as $placeholder => $gifUrl) {
            $message = str_replace($placeholder, $gifUrl, $message);
        }
        
        return [
            'message' => $message,
            'replaced' => $message !== $originalMessage
        ];
    }
    
    /**
     * Check if GIF feature is enabled in settings
     */
    private static function isGifEnabled(): bool
    {
        if (self::$gifEnabledCache === null) {
            try {
                $settingsService = new SettingsService();
                self::$gifEnabledCache = $settingsService->get('gif_enabled', 'true') === 'true';
            } catch (\Exception $e) {
                // Default to true if we can't check settings
                self::$gifEnabledCache = true;
            }
        }

        return self::$gifEnabledCache;
    }
    
    /**
     * Get whitelisted URL patterns from database (with Redis caching)
     */
    private static function getWhitelistedUrlPatterns(): array
    {
        static $patterns = null;
        
        if ($patterns !== null) {
            return $patterns;
        }
        
        try {
            $cachedData = FlatCache::default()->get('url_whitelist_patterns');

            if ($cachedData !== null) {
                $patterns = $cachedData;
            } else {
                // Cache miss - fetch from database
                $patterns = Database::getInstance()->queryBuilder()
                    ->from('url_whitelist')
                    ->orderBy('pattern')
                    ->pluck('pattern');

                // Store in cache (5 minutes)
                FlatCache::default()->set('url_whitelist_patterns', $patterns, 300);
            }

            return $patterns;
        } catch (\Exception $e) {
            \Pramnos\Logs\Logger::log("Error fetching URL whitelist: " . $e->getMessage(), 'radiochatbox');
            $patterns = [];
            return $patterns;
        }
    }
    
    /**
     * Replace phone numbers in message
     */
    private static function replacePhoneNumbers(string $message): array
    {
        $originalMessage = $message;
        
        // International format and common patterns
        $phonePatterns = [
            '/\+?[\d\s\-\(\)]{10,}/',
            '/(\(?\d{3}\)?[\s\.\-]?)?\d{3}[\s\.\-]?\d{4}/'
        ];
        
        foreach ($phonePatterns as $pattern) {
            $message = preg_replace($pattern, '***', $message);
        }
        
        return [
            'message' => $message,
            'replaced' => $message !== $originalMessage
        ];
    }
    
    /**
     * Check for blacklisted URLs in message
     * Uses Redis cache to avoid hitting PostgreSQL on every message
     */
    private static function checkBlacklistedUrls(string $message, string $ipAddress = ''): array
    {
        try {
            // Try the cache first
            $cachedData = FlatCache::default()->get('url_blacklist_patterns');

            if ($cachedData !== null) {
                $blacklist = $cachedData;
            } else {
                // Cache miss - fetch from database
                $blacklist = Database::getInstance()->queryBuilder()
                    ->from('url_blacklist')
                    ->pluck('pattern');

                // Store in cache (5 minutes)
                FlatCache::default()->set('url_blacklist_patterns', $blacklist, 300);
            }

            $originalMessage = $message;
            $violationDetected = false;
            
            foreach ($blacklist as $pattern) {
                // Escape special regex characters except *
                $regexPattern = preg_quote($pattern, '/');
                $regexPattern = str_replace('\*', '.*', $regexPattern);
                
                if (preg_match('/' . $regexPattern . '/i', $message)) {
                    $message = preg_replace('/' . $regexPattern . '/i', '***', $message);
                    $violationDetected = true;
                }
            }
            
            // Track spam URL violations for auto-ban
            if ($violationDetected && !empty($ipAddress)) {
                self::trackSpamViolation($ipAddress);
            }
            
            return [
                'found' => $message !== $originalMessage,
                'message' => $message
            ];
        } catch (\Exception $e) {
            \Pramnos\Logs\Logger::log("Error checking URL blacklist: " . $e->getMessage(), 'radiochatbox');
            return [
                'found' => false,
                'message' => $message
            ];
        }
    }
    
    /**
     * Track spam URL violations for auto-ban
     */
    private static function trackSpamViolation(string $ipAddress): void
    {
        try {
            // Atomic sliding-window counter via the Cache capability (Redis INCRBY
            // + 1h expiry); returns the new post-increment count.
            $key = "violations:spam_url:{$ipAddress}";
            $violations = FlatCache::default()->increment($key, 1, 3600);

            // Auto-ban after 3 spam URL attempts
            if ($violations >= 3) {
                $db = Database::getInstance();

                // Check if already banned
                $alreadyBanned = $db->queryBuilder()
                    ->from('banned_ips')
                    ->where('ip_address', '=', $ipAddress)
                    ->whereRaw('(banned_until IS NULL OR banned_until > NOW())')
                    ->exists();

                if (!$alreadyBanned) {
                    // Auto-ban for 24 hours
                    $bannedUntil = date('Y-m-d H:i:s', time() + (24 * 3600));
                    $reason = "Automatic ban: Repeated spam URL attempts ({$violations} times)";

                    $db->queryBuilder()->from('banned_ips')->insert([
                        'ip_address'   => $ipAddress,
                        'reason'       => $reason,
                        'banned_by'    => 'system',
                        'banned_until' => $bannedUntil,
                    ]);

                    // Invalidate cache
                    FlatCache::default()->delete('banned_ips');

                    \Pramnos\Logs\Logger::log("Auto-banned IP {$ipAddress} for spam URL violations (count: {$violations})", 'radiochatbox');
                }

                // Clear violation counter
                FlatCache::default()->delete($key);
            } else {
                $remaining = 3 - $violations;
                \Pramnos\Logs\Logger::log("Spam URL violation for {$ipAddress} (violations: {$violations}, {$remaining} more until auto-ban)", 'radiochatbox');
            }
        // @codeCoverageIgnoreStart
        } catch (\Exception $e) {
            \Pramnos\Logs\Logger::log("Failed to track spam violation: " . $e->getMessage(), 'radiochatbox');
        }
        // @codeCoverageIgnoreEnd
    }
    
    /**
     * Replace a specific pattern with replacement text
     */
    private static function replacePattern(string $message, string $pattern, string $replacement): string
    {
        if (empty($pattern)) {
            return $message;
        }
        return str_replace($pattern, $replacement, $message);
    }

    /**
     * Sanitize message for safe HTML output
     * This is a secondary defense layer
     */
    public static function sanitizeForOutput(string $message): string
    {
        // HTML entities encoding for all special characters
        return htmlspecialchars($message, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
