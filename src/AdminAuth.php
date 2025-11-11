<?php

namespace RadioChatBox;

class AdminAuth
{
    private const ADMIN_PASSWORD_HASH = '$2y$10$ZUCvW9SmSpOUwPtWC.XzL.mA0piFBy.DM8TKPHvkWdd0CsG121vCC'; // password: admin123
    
    /**
     * Get admin password hash from database or fallback to constant
     */
    private static function getPasswordHash(): string
    {
        try {
            $db = Database::getPDO();
            $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'admin_password_hash'");
            $stmt->execute();
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if ($result && !empty($result['setting_value'])) {
                return $result['setting_value'];
            }
        } catch (\Exception $e) {
            // Fallback to constant if database is not available
        }
        
        return self::ADMIN_PASSWORD_HASH;
    }
    
    /**
     * Verify admin authentication
     */
    public static function verify(): bool
    {
        // Try multiple ways to get the Authorization header
        $authHeader = '';
        
        // Method 1: Direct from $_SERVER
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
        }
        // Method 2: Apache specific
        elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        // Method 3: PHP auth header
        elseif (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            if (isset($headers['Authorization'])) {
                $authHeader = $headers['Authorization'];
            }
        }
        
        if (empty($authHeader)) {
            return false;
        }
        
        // Expect "Bearer <password>" format
        if (strpos($authHeader, 'Bearer ') !== 0) {
            return false;
        }
        
        $password = substr($authHeader, 7);
        
        // Rate limiting: Check for failed attempts
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if (!self::checkRateLimit($ipAddress)) {
            error_log("Admin auth rate limit exceeded for IP: {$ipAddress}");
            return false;
        }
        
        // Check against database or fallback to constant
        $adminPasswordHash = self::getPasswordHash();
        
        $isValid = password_verify($password, $adminPasswordHash);
        
        // Track failed attempts
        if (!$isValid) {
            self::recordFailedAttempt($ipAddress);
        } else {
            self::clearFailedAttempts($ipAddress);
        }
        
        return $isValid;
    }
    
    /**
     * Check rate limit for admin auth attempts
     * Max 5 attempts per 15 minutes
     */
    private static function checkRateLimit(string $ipAddress): bool
    {
        try {
            $redis = Database::getRedis();
            $prefix = Database::getRedisPrefix();
            $key = $prefix . "admin_auth_attempts:{$ipAddress}";
            $attempts = (int)$redis->get($key);
            
            // Allow max 5 attempts per 15 minutes
            return $attempts < 5;
        } catch (\Exception $e) {
            // If Redis fails, allow the attempt but log it
            error_log("Admin auth rate limit check failed: " . $e->getMessage());
            return true;
        }
    }
    
    /**
     * Record a failed authentication attempt
     */
    private static function recordFailedAttempt(string $ipAddress): void
    {
        try {
            $redis = Database::getRedis();
            $prefix = Database::getRedisPrefix();
            $key = $prefix . "admin_auth_attempts:{$ipAddress}";
            
            $redis->incr($key);
            $redis->expire($key, 900); // 15 minutes
        } catch (\Exception $e) {
            error_log("Failed to record admin auth attempt: " . $e->getMessage());
        }
    }
    
    /**
     * Clear failed attempts on successful auth
     */
    private static function clearFailedAttempts(string $ipAddress): void
    {
        try {
            $redis = Database::getRedis();
            $prefix = Database::getRedisPrefix();
            $key = $prefix . "admin_auth_attempts:{$ipAddress}";
            $redis->del($key);
        } catch (\Exception $e) {
            error_log("Failed to clear admin auth attempts: " . $e->getMessage());
        }
    }
    
    /**
     * Alias for verify() for consistency
     */
    public static function authenticate(): bool
    {
        return self::verify();
    }
    
    /**
     * Send unauthorized response
     */
    public static function unauthorized(): void
    {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    
    /**
     * Hash a password for storage
     */
    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }
}
