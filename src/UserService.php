<?php

namespace RadioChatBox;

/**
 * User Service - Admin User Management with RBAC
 * 
 * Manages admin users with role-based access control.
 * Roles: root, administrator, moderator, simple_user
 */
class UserService
{
    private \Pramnos\Database\Database $db;
    private \Redis $redis;
    
    // Role hierarchy (higher number = more privileges)
    private const ROLE_LEVELS = [
        'simple_user' => 0,
        'moderator' => 1,
        'administrator' => 2,
        'root' => 3
    ];
    
    // Permissions for each role
    private const PERMISSIONS = [
        'root' => [
            'view_private_messages',
            'manage_settings',
            'manage_users',
            'manage_bans',
            'manage_blacklist',
            'view_messages',
            'create_root_users',
            'delete_root_users'
        ],
        'administrator' => [
            'view_private_messages',
            'manage_settings',
            'manage_users',
            'manage_bans',
            'manage_blacklist',
            'view_messages'
        ],
        'moderator' => [
            'view_messages',
            'view_bans',
            'view_blacklist'
        ],
        'simple_user' => []
    ];
    
    public function __construct()
    {
        $this->db = Database::getDb();
        $this->redis = Database::getRedis();
    }
    
    /**
     * Create a new admin user
     * 
     * @param string $username Username (3-50 characters)
     * @param string $password Plain text password
     * @param string $role User role (root, administrator, moderator, simple_user)
     * @param string|null $email Email address
     * @param int|null $createdBy ID of user creating this account
     * @param string|null $displayName Display name (optional)
     * @return array Success/error response
     */
    public function createUser(string $username, string $password, string $role, ?string $email = null, ?int $createdBy = null, ?string $displayName = null): array
    {
        // Validate username
        $username = trim($username);
        if (strlen($username) < 3 || strlen($username) > 50) {
            return ['success' => false, 'error' => 'Username must be 3-50 characters'];
        }
        
        // Validate role
        if (!in_array($role, ['root', 'administrator', 'moderator', 'simple_user'])) {
            return ['success' => false, 'error' => 'Invalid role'];
        }
        
        // Validate password
        if (strlen($password) < 8) {
            return ['success' => false, 'error' => 'Password must be at least 8 characters'];
        }
        
        // Hash password
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        
        try {
            $result = $this->db->queryBuilder()
                ->from('users')
                ->returning('id', 'username', 'role', 'email', 'display_name', 'created_at')
                ->insert([
                    'username'      => $username,
                    'password_hash' => $passwordHash,
                    'role'          => $role,
                    'email'         => $email,
                    'created_by'    => $createdBy,
                    'display_name'  => $displayName,
                ]);

            // The framework returns false on a DB error instead of throwing, so
            // inspect the driver error text: PostgreSQL reports a unique violation
            // (SQLSTATE 23505) as "duplicate key value violates unique constraint".
            if ($result === false) {
                Log::write("UserService::createUser error: "
                    . (string) ($this->db->getError()['message'] ?? 'insert failed'));
                return ['success' => false, 'error' => 'Database error'];
            }

            $user = $result->fetch();

            // Clear users cache
            $this->clearUsersCache();

            return [
                'success' => true,
                'user' => $this->sanitizeUser($user)
            ];

        } catch (\Throwable $e) {
            // The framework throws on SQL errors (rather than returning false).
            // PostgreSQL reports a unique violation (SQLSTATE 23505) as
            // "duplicate key value violates unique constraint".
            $msg = $e->getMessage();
            if (stripos($msg, 'duplicate key') !== false
                || stripos($msg, 'unique constraint') !== false) {
                return ['success' => false, 'error' => 'Username already exists'];
            }
            Log::write("UserService::createUser error: " . $msg);
            return ['success' => false, 'error' => 'Database error'];
        }
    }
    
    /**
     * Update an existing admin user
     * 
     * @param int $userId User ID to update
     * @param array $updates Fields to update (password, email, role, is_active)
     * @return array Success/error response
     */
    public function updateUser(int $userId, array $updates): array
    {
        $allowedFields = ['password', 'email', 'role', 'is_active', 'display_name'];
        $updateData = [];

        foreach ($updates as $field => $value) {
            if (!in_array($field, $allowedFields)) {
                continue;
            }

            if ($field === 'password') {
                if (strlen($value) < 8) {
                    return ['success' => false, 'error' => 'Password must be at least 8 characters'];
                }
                $updateData['password_hash'] = password_hash($value, PASSWORD_DEFAULT);
            } elseif ($field === 'role') {
                if (!in_array($value, ['root', 'administrator', 'moderator', 'simple_user'])) {
                    return ['success' => false, 'error' => 'Invalid role'];
                }
                $updateData['role'] = $value;
            } elseif ($field === 'is_active') {
                $updateData['is_active'] = (bool)$value;
            } elseif ($field === 'email') {
                $updateData['email'] = $value;
            } elseif ($field === 'display_name') {
                $updateData['display_name'] = $value;
            }
        }

        if (empty($updateData)) {
            return ['success' => false, 'error' => 'No valid fields to update'];
        }

        try {
            $result = $this->db->queryBuilder()
                ->from('users')
                ->where('id', '=', $userId)
                ->returning('id', 'username', 'role', 'email', 'display_name', 'is_active', 'updated_at')
                ->update($updateData);

            $user = ($result && $result->numRows > 0) ? $result->fields : null;

            if (!$user) {
                return ['success' => false, 'error' => 'User not found'];
            }

            // Clear user cache
            $this->clearUsersCache();
            $this->clearUserSession($user['username']);

            // PERFORMANCE OPTIMIZATION FIX: Clear user-specific data cache
            $this->clearUserDataCache($user['username']);

            return [
                'success' => true,
                'user' => $this->sanitizeUser($user)
            ];

        } catch (\Throwable $e) {
            Log::write("UserService::updateUser error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Database error'];
        }
    }
    
    /**
     * Delete an admin user
     * 
     * @param int $userId User ID to delete
     * @return array Success/error response
     */
    public function deleteUser(int $userId): array
    {
        try {
            // Get user info before deleting
            $username = $this->db->queryBuilder()
                ->from('users')
                ->where('id', '=', $userId)
                ->value('username');

            if ($username === null) {
                return ['success' => false, 'error' => 'User not found'];
            }

            // Delete user
            $this->db->queryBuilder()
                ->from('users')
                ->where('id', '=', $userId)
                ->delete();

            // Clear caches
            $this->clearUsersCache();
            $this->clearUserSession($username);

            return ['success' => true];

        } catch (\Throwable $e) {
            Log::write("UserService::deleteUser error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Database error'];
        }
    }
    
    /**
     * Get all admin users
     * 
     * @param bool $includeInactive Include inactive users
     * @return array List of users
     */
    public function getAllUsers(bool $includeInactive = false): array
    {
        // Try to get from Redis cache first
        $cacheKey = 'users:list:' . ($includeInactive ? 'all' : 'active');
        
        try {
            $prefix = Database::getRedisPrefix();
            $cached = $this->redis->get($prefix . $cacheKey);
            
            if ($cached !== false) {
                $users = json_decode($cached, true);
                if (is_array($users)) {
                    return $users;
                }
            }
        } catch (\Exception $e) {
            Log::write("UserService::getAllUsers Redis error: " . $e->getMessage());
            // Continue to database query if Redis fails
        }
        
        try {
            $qb = $this->db->queryBuilder()
                ->from('users')
                ->select(['id', 'username', 'role', 'email', 'display_name', 'is_active', 'created_at', 'updated_at', 'last_login']);

            if (!$includeInactive) {
                $qb->whereRaw('is_active = TRUE');
            }

            $qb->orderByRaw(
                "CASE role
                    WHEN 'root' THEN 1
                    WHEN 'administrator' THEN 2
                    WHEN 'moderator' THEN 3
                    WHEN 'simple_user' THEN 4
                END"
            )->orderBy('username', 'asc');

            $users = $qb->getAll();

            $users = array_map([$this, 'sanitizeUser'], $users);
            
            // Cache the results for 5 minutes
            try {
                $prefix = Database::getRedisPrefix();
                $this->redis->setex($prefix . $cacheKey, 300, json_encode($users));
            } catch (\Exception $e) {
                Log::write("UserService::getAllUsers cache set error: " . $e->getMessage());
            }
            
            return $users;

        } catch (\Throwable $e) {
            Log::write("UserService::getAllUsers error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get user by ID
     * 
     * @param int $userId User ID
     * @return array|null User data or null if not found
     */
    public function getUserById(int $userId): ?array
    {
        try {
            $row = $this->db->queryBuilder()
                ->from('users')
                ->select(['id', 'username', 'role', 'email', 'is_active', 'created_at', 'updated_at', 'last_login'])
                ->where('id', '=', $userId)
                ->first();
            $user = ($row && $row->numRows > 0) ? $row->fields : null;

            return $user ? $this->sanitizeUser($user) : null;

        } catch (\Throwable $e) {
            Log::write("UserService::getUserById error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get user by username
     * 
     * @param string $username Username
     * @return array|null User data or null if not found
     */
    public function getUserByUsername(string $username): ?array
    {
        try {
            $row = $this->db->queryBuilder()
                ->from('users')
                ->select(['id', 'username', 'role', 'email', 'is_active', 'created_at', 'updated_at', 'last_login'])
                ->where('username', '=', $username)
                ->first();
            $user = ($row && $row->numRows > 0) ? $row->fields : null;

            return $user ? $this->sanitizeUser($user) : null;

        } catch (\Throwable $e) {
            Log::write("UserService::getUserByUsername error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Authenticate user with username/email and password
     * 
     * @param string $identifier Username or email address
     * @param string $password Plain text password
     * @return array|null User data if authenticated, null otherwise
     */
    public function authenticate(string $identifier, string $password): ?array
    {
        try {
            // Try both username and email
            $row = $this->db->queryBuilder()
                ->from('users')
                ->select(['id', 'username', 'password_hash', 'role', 'email', 'display_name', 'is_active'])
                ->whereRaw('username = %s OR email = %s', [$identifier, $identifier])
                ->first();
            $user = ($row && $row->numRows > 0) ? $row->fields : null;

            if (!$user) {
                return null;
            }
            
            // Check if user is active
            if (!$user['is_active']) {
                return null;
            }
            
            // Verify password
            if (!password_verify($password, $user['password_hash'])) {
                return null;
            }
            
            // Update last login
            $this->updateLastLogin($user['id']);
            
            return $this->sanitizeUser($user);
            
        } catch (\Throwable $e) {
            Log::write("UserService::authenticate error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Check if user has a specific permission
     * 
     * @param string $role User role
     * @param string $permission Permission to check
     * @return bool True if user has permission
     */
    public function hasPermission(string $role, string $permission): bool
    {
        if (!isset(self::PERMISSIONS[$role])) {
            return false;
        }
        
        return in_array($permission, self::PERMISSIONS[$role]);
    }
    
    /**
     * Check if user can manage another user (based on roles)
     * 
     * @param string $currentUserRole Role of the current user
     * @param string $targetUserRole Role of the user being managed
     * @return bool True if current user can manage target user
     */
    public function canManageUser(string $currentUserRole, string $targetUserRole): bool
    {
        // Root can manage everyone
        if ($currentUserRole === 'root') {
            return true;
        }
        
        // Administrator can manage everyone except root
        if ($currentUserRole === 'administrator' && $targetUserRole !== 'root') {
            return true;
        }
        
        // Others cannot manage users
        return false;
    }
    
    /**
     * Update last login timestamp
     * 
     * @param int $userId User ID
     */
    private function updateLastLogin(int $userId): void
    {
        try {
            $qb = $this->db->queryBuilder()->from('users');
            $qb->where('id', '=', $userId)->update(['last_login' => $qb->raw('CURRENT_TIMESTAMP')]);
        } catch (\Throwable $e) {
            Log::write("UserService::updateLastLogin error: " . $e->getMessage());
        }
    }
    
    /**
     * Remove sensitive data from user array
     * 
     * @param array $user User data
     * @return array Sanitized user data
     */
    private function sanitizeUser(array $user): array
    {
        unset($user['password_hash']);
        return $user;
    }
    
    /**
     * Clear users cache in Redis
     * ENHANCED: Also clears display name and user data caches from performance optimizations
     */
    private function clearUsersCache(): void
    {
        try {
            $prefix = Database::getRedisPrefix();
            // Clear both active and all users cache
            $this->redis->del($prefix . 'users:list:active');
            $this->redis->del($prefix . 'users:list:all');

            // PERFORMANCE OPTIMIZATION FIX: Clear all display name related caches
            // This ensures updated display names are reflected everywhere
            $this->redis->del($prefix . 'chat:all_users'); // Combined user list
            $this->redis->del($prefix . 'chat:messages'); // Message history
            $this->redis->del($prefix . 'chat:messages:hash'); // Message hash for replies

            // Note: Individual user_data:{username} caches will expire naturally in 5 minutes
            // or can be cleared per-user if we know which user was updated
        } catch (\Exception $e) {
            Log::write("UserService::clearUsersCache error: " . $e->getMessage());
        }
    }
    
    /**
     * Clear user session in Redis (force re-login)
     *
     * @param string $username Username
     */
    private function clearUserSession(string $username): void
    {
        try {
            $prefix = Database::getRedisPrefix();
            $this->redis->del($prefix . "admin_session:{$username}");
        } catch (\Exception $e) {
            Log::write("UserService::clearUserSession error: " . $e->getMessage());
        }
    }

    /**
     * Clear user-specific data caches (display_name, user_data)
     * PERFORMANCE OPTIMIZATION FIX: Clears caches added during optimization
     *
     * @param string $username Username
     */
    private function clearUserDataCache(string $username): void
    {
        try {
            $prefix = Database::getRedisPrefix();
            // Clear legacy display_name cache
            $this->redis->del($prefix . "display_name:{$username}");
            // Clear new user_data cache (includes both user_id and display_name)
            $this->redis->del($prefix . "user_data:{$username}");
        } catch (\Exception $e) {
            Log::write("UserService::clearUserDataCache error: " . $e->getMessage());
        }
    }

    /**
     * Get role level (for comparison)
     * 
     * @param string $role Role name
     * @return int Role level
     */
    public function getRoleLevel(string $role): int
    {
        return self::ROLE_LEVELS[$role] ?? 0;
    }
    
    /**
     * Get all available roles
     * 
     * @return array List of roles
     */
    public function getAvailableRoles(): array
    {
        return array_keys(self::ROLE_LEVELS);
    }
    
    /**
     * Get only real active users
     * 
     * @return array List of real active users
     */
    public function getActiveRealUsers(): array
    {
        // Fetch active users from the database. Predicates kept verbatim (this
        // method is currently unused; preserving its exact behaviour).
        return $this->db->queryBuilder()
            ->from('users')
            ->whereRaw('is_fake = 0 AND is_active = 1')
            ->getAll();
    }
}
