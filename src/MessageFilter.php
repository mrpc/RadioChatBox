<?php

namespace RadioChatBox;

use PDO;

class MessageFilter
{
    /**
     * Filter message for public chat
     * Replaces blocked content with ***
     */
    public static function filterPublicMessage(string $message): array
    {
        $originalMessage = $message;
        $replacements = [];

        // First check for dangerous content (applies to all messages)
        $dangerousCheck = self::checkDangerousContent($message);
        if (!$dangerousCheck['safe']) {
            $message = self::replacePattern($message, $dangerousCheck['pattern'], '***');
            $replacements[] = $dangerousCheck['reason'];
        }

        // Check and replace URLs
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

        return [
            'allowed' => true,
            'reason' => '',
            'filtered' => $message,
            'modified' => $message !== $originalMessage,
            'replacements' => $replacements
        ];
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

        return [
            'allowed' => true,
            'reason' => '',
            'filtered' => $message,
            'modified' => $message !== $originalMessage,
            'replacements' => $replacements
        ];
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
     * Replace URLs in message
     */
    private static function replaceUrls(string $message): array
    {
        $originalMessage = $message;
        
        // Pattern to match various URL formats
        $urlPattern = '/\b(?:(?:https?|ftp|file):\/\/|www\.|ftp\.)[-A-Z0-9+&@#\/%=~_|$?!:,.]*[A-Z0-9+&@#\/%=~_|$]/i';
        $message = preg_replace($urlPattern, '***', $message);
        
        // Check for domain-like patterns
        $domainPattern = '/\b[a-z0-9]+([\-\.]{1}[a-z0-9]+)*\.(com|net|org|edu|gov|co|io|ai|app|dev|tech|info|biz|me|tv|cc|xyz|online|site|website|blog|shop|store)\b/i';
        $message = preg_replace($domainPattern, '***', $message);
        
        return [
            'message' => $message,
            'replaced' => $message !== $originalMessage
        ];
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
            // Try to get from Redis cache first
            $redis = Database::getRedis();
            $cacheKey = 'url_blacklist_patterns';
            $cacheTTL = 300; // 5 minutes
            
            $cachedData = $redis->get($cacheKey);
            
            if ($cachedData !== false) {
                $blacklist = json_decode($cachedData, true);
            } else {
                // Cache miss - fetch from database
                $db = Database::getPDO();
                $stmt = $db->query("SELECT pattern FROM url_blacklist");
                $blacklist = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                // Store in Redis cache
                $redis->setex($cacheKey, $cacheTTL, json_encode($blacklist));
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
            error_log("Error checking URL blacklist: " . $e->getMessage());
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
            $redis = Database::getRedis();
            $key = "violations:spam_url:{$ipAddress}";
            $violations = (int)$redis->get($key);
            
            // Increment violation counter
            $redis->incr($key);
            $redis->expire($key, 3600); // Track for 1 hour
            
            $violations++; // Current count
            
            // Auto-ban after 3 spam URL attempts
            if ($violations >= 3) {
                $db = Database::getPDO();
                
                // Check if already banned
                $stmt = $db->prepare("SELECT COUNT(*) FROM banned_ips WHERE ip_address = ? AND (banned_until IS NULL OR banned_until > NOW())");
                $stmt->execute([$ipAddress]);
                $alreadyBanned = $stmt->fetchColumn() > 0;
                
                if (!$alreadyBanned) {
                    // Auto-ban for 24 hours
                    $bannedUntil = date('Y-m-d H:i:s', time() + (24 * 3600));
                    $reason = "Automatic ban: Repeated spam URL attempts ({$violations} times)";
                    
                    $stmt = $db->prepare("
                        INSERT INTO banned_ips (ip_address, reason, banned_by, banned_until)
                        VALUES (?, ?, 'system', ?)
                    ");
                    $stmt->execute([$ipAddress, $reason, $bannedUntil]);
                    
                    // Invalidate cache
                    $redis->del('banned_ips');
                    
                    error_log("Auto-banned IP {$ipAddress} for spam URL violations (count: {$violations})");
                }
                
                // Clear violation counter
                $redis->del($key);
            } else {
                $remaining = 3 - $violations;
                error_log("Spam URL violation for {$ipAddress} (violations: {$violations}, {$remaining} more until auto-ban)");
            }
        } catch (\Exception $e) {
            error_log("Failed to track spam violation: " . $e->getMessage());
        }
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
