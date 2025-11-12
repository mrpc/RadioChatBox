<?php

namespace RadioChatBox;

class AdminAuth
{
    /**
     * Verify admin authentication with database-based system
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
        
        // Rate limiting: Check for failed attempts
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if (!self::checkRateLimit($ipAddress)) {
            error_log("Admin auth rate limit exceeded for IP: {$ipAddress}");
            return false;
        }
        
        // Expect "Bearer <username>:<password>" format
        if (strpos($authHeader, 'Bearer ') !== 0) {
            return false;
        }
        
        $credentials = substr($authHeader, 7);
        
        // Check if it's username:password format
        if (strpos($credentials, ':') !== false) {
            list($username, $password) = explode(':', $credentials, 2);
            $isValid = self::authenticateDatabase($username, $password);
        } else {
            // Legacy password-only format - no longer supported
            error_log("Admin auth failed: Legacy password-only authentication is deprecated");
            $isValid = false;
        }
        
        // Track failed attempts
        if (!$isValid) {
            self::recordFailedAttempt($ipAddress);
        } else {
            self::clearFailedAttempts($ipAddress);
        }
        
        return $isValid;
    }
    
    /**
     * Authenticate against admin_users table
     * 
     * @param string $username Username
     * @param string $password Plain text password
     * @return bool True if authenticated
     */
    private static function authenticateDatabase(string $username, string $password): bool
    {
        try {
            $userService = new UserService();
            $user = $userService->authenticate($username, $password);
            
            if ($user) {
                // Store user info in session for role-based access
                self::setCurrentUser($user['username'], $user['role']);
                return true;
            }
            
            return false;
            
        } catch (\Exception $e) {
            error_log("AdminAuth::authenticateDatabase error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Store current authenticated user in Redis session
     * 
     * @param string $username Username
     * @param string $role User role
     */
    private static function setCurrentUser(string $username, string $role): void
    {
        try {
            $redis = Database::getRedis();
            $prefix = Database::getRedisPrefix();
            
            // Store session with user info (expires in 24 hours)
            $sessionData = json_encode([
                'username' => $username,
                'role' => $role,
                'authenticated_at' => time()
            ]);
            
            $redis->setex($prefix . "admin_session:{$username}", 86400, $sessionData);
            
        } catch (\Exception $e) {
            error_log("AdminAuth::setCurrentUser error: " . $e->getMessage());
        }
    }
    
    /**
     * Get current authenticated user from session
     * 
     * @return array|null User data (username, role) or null if not authenticated
     */
    public static function getCurrentUser(): ?array
    {
        try {
            // Extract credentials from Authorization header
            $authHeader = '';
            if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
                $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
            } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
                $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
            } elseif (function_exists('apache_request_headers')) {
                $headers = apache_request_headers();
                if (isset($headers['Authorization'])) {
                    $authHeader = $headers['Authorization'];
                }
            }
            
            if (empty($authHeader) || strpos($authHeader, 'Bearer ') !== 0) {
                return null;
            }
            
            $credentials = substr($authHeader, 7);
            
            // Only works with username:password format
            if (strpos($credentials, ':') === false) {
                return null;
            }
            
            list($username) = explode(':', $credentials, 2);
            
            $redis = Database::getRedis();
            $prefix = Database::getRedisPrefix();
            
            $sessionData = $redis->get($prefix . "admin_session:{$username}");
            if ($sessionData) {
                return json_decode($sessionData, true);
            }
            
            return null;
            
        } catch (\Exception $e) {
            error_log("AdminAuth::getCurrentUser error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Check if current user has a specific permission
     * 
     * @param string $permission Permission to check
     * @return bool True if user has permission
     */
    public static function hasPermission(string $permission): bool
    {
        $user = self::getCurrentUser();
        if (!$user) {
            return false;
        }
        
        $userService = new UserService();
        return $userService->hasPermission($user['role'], $permission);
    }
    
    /**
     * Require a specific permission (sends 403 if not authorized)
     * 
     * @param string $permission Permission to require
     */
    public static function requirePermission(string $permission): void
    {
        if (!self::hasPermission($permission)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Forbidden: Insufficient permissions']);
            exit;
        }
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
