<?php

namespace RadioChatBox;

use Pramnos\Database\Database;

use Pramnos\Cache\FlatCache;

use RadioChatBox\Services\UserService;
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
            \Pramnos\Logs\Logger::log("Admin auth rate limit exceeded for IP: {$ipAddress}", 'radiochatbox');
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
            \Pramnos\Logs\Logger::log("Admin auth failed: Legacy password-only authentication is deprecated", 'radiochatbox');
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
     * Authenticate against users table
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
            \Pramnos\Logs\Logger::log("AdminAuth::authenticateDatabase error: " . $e->getMessage(), 'radiochatbox');
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
            // Cache the authenticated user's info for 24h so getCurrentUser() skips
            // a DB role lookup per request. This is a cache, not a login session —
            // auth itself is the per-request Bearer check in verify().
            FlatCache::default()->set("admin_session:{$username}", [
                'username' => $username,
                'role' => $role,
                'authenticated_at' => time(),
            ], 86400);
        } catch (\Exception $e) {
            \Pramnos\Logs\Logger::log("AdminAuth::setCurrentUser error: " . $e->getMessage(), 'radiochatbox');
        }
    }

    /**
     * Destroy an admin's cached auth session (forces re-login).
     *
     * The `admin_session:<username>` cache namespace is owned by AdminAuth; other
     * services (e.g. UserService, when a user's role/status changes) ask here
     * rather than reaching into the cache key themselves.
     */
    public static function destroySession(string $username): void
    {
        try {
            FlatCache::default()->delete("admin_session:{$username}");
        } catch (\Exception $e) {
            \Pramnos\Logs\Logger::log("AdminAuth::destroySession error: " . $e->getMessage(), 'radiochatbox');
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
            
            list($identifier) = explode(':', $credentials, 2);
            
            // The identifier could be either username or email
            // First try to look up directly in case it's a username with active session
            $sessionData = FlatCache::default()->get("admin_session:{$identifier}");
            if (is_array($sessionData)) {
                return $sessionData;
            }
            
            // If not found, look up the identifier in database to get the actual username
            // This handles the case where someone logged in with email instead of username
            try {
                $lookup = Database::getInstance()->queryBuilder()
                    ->from('users')
                    ->select(['username'])
                    ->whereRaw('username = %s OR email = %s', [$identifier, $identifier])
                    ->first();
                $user = ($lookup && $lookup->numRows > 0) ? $lookup->fields : false;

                if ($user) {
                    $actualUsername = $user['username'];
                    // Try to get session with the actual username
                    $sessionData = FlatCache::default()->get("admin_session:{$actualUsername}");
                    if (is_array($sessionData)) {
                        return $sessionData;
                    }
                }
            } catch (\Exception $e) {
                \Pramnos\Logs\Logger::log("AdminAuth::getCurrentUser database lookup error: " . $e->getMessage(), 'radiochatbox');
            }
            
            return null;
            
        } catch (\Exception $e) {
            \Pramnos\Logs\Logger::log("AdminAuth::getCurrentUser error: " . $e->getMessage(), 'radiochatbox');
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
            $attempts = FlatCache::default()->counter("admin_auth_attempts:{$ipAddress}");

            // Allow max 5 attempts per 15 minutes
            return $attempts < 5;
        } catch (\Exception $e) {
            // If Redis fails, allow the attempt but log it
            \Pramnos\Logs\Logger::log("Admin auth rate limit check failed: " . $e->getMessage(), 'radiochatbox');
            return true;
        }
    }
    
    /**
     * Record a failed authentication attempt
     */
    private static function recordFailedAttempt(string $ipAddress): void
    {
        try {
            // Atomic sliding-window counter (Redis INCRBY + 15-minute expiry).
            FlatCache::default()->increment("admin_auth_attempts:{$ipAddress}", 1, 900);
        } catch (\Exception $e) {
            \Pramnos\Logs\Logger::log("Failed to record admin auth attempt: " . $e->getMessage(), 'radiochatbox');
        }
    }
    
    /**
     * Clear failed attempts on successful auth
     */
    private static function clearFailedAttempts(string $ipAddress): void
    {
        try {
            FlatCache::default()->delete("admin_auth_attempts:{$ipAddress}");
        } catch (\Exception $e) {
            \Pramnos\Logs\Logger::log("Failed to clear admin auth attempts: " . $e->getMessage(), 'radiochatbox');
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
