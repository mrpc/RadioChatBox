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
    private \PDO $db;
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
        $this->db = Database::getPDO();
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
            $stmt = $this->db->prepare("
                INSERT INTO users (username, password_hash, role, email, created_by, display_name)
                VALUES (:username, :password_hash, :role, :email, :created_by, :display_name)
                RETURNING id, username, role, email, display_name, created_at
            ");
            
            $stmt->execute([
                'username' => $username,
                'password_hash' => $passwordHash,
                'role' => $role,
                'email' => $email,
                'created_by' => $createdBy,
                'display_name' => $displayName
            ]);
            
            $user = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            // Clear users cache
            $this->clearUsersCache();
            
            return [
                'success' => true,
                'user' => $this->sanitizeUser($user)
            ];
            
        } catch (\PDOException $e) {
            if ($e->getCode() === '23505') { // Unique violation
                return ['success' => false, 'error' => 'Username already exists'];
            }
            error_log("UserService::createUser error: " . $e->getMessage());
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
        $setFields = [];
        $params = ['id' => $userId];
        
        foreach ($updates as $field => $value) {
            if (!in_array($field, $allowedFields)) {
                continue;
            }
            
            if ($field === 'password') {
                if (strlen($value) < 8) {
                    return ['success' => false, 'error' => 'Password must be at least 8 characters'];
                }
                $setFields[] = "password_hash = :password_hash";
                $params['password_hash'] = password_hash($value, PASSWORD_DEFAULT);
            } elseif ($field === 'role') {
                if (!in_array($value, ['root', 'administrator', 'moderator', 'simple_user'])) {
                    return ['success' => false, 'error' => 'Invalid role'];
                }
                $setFields[] = "role = :role";
                $params['role'] = $value;
            } elseif ($field === 'is_active') {
                $setFields[] = "is_active = :is_active";
                $params['is_active'] = (bool)$value;
            } elseif ($field === 'email') {
                $setFields[] = "email = :email";
                $params['email'] = $value;
            } elseif ($field === 'display_name') {
                $setFields[] = "display_name = :display_name";
                $params['display_name'] = $value;
            }
        }
        
        if (empty($setFields)) {
            return ['success' => false, 'error' => 'No valid fields to update'];
        }
        
        try {
            $sql = "UPDATE users SET " . implode(', ', $setFields) . " WHERE id = :id RETURNING id, username, role, email, display_name, is_active, updated_at";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            $user = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if (!$user) {
                return ['success' => false, 'error' => 'User not found'];
            }
            
            // Clear user cache
            $this->clearUsersCache();
            $this->clearUserSession($user['username']);
            
            return [
                'success' => true,
                'user' => $this->sanitizeUser($user)
            ];
            
        } catch (\PDOException $e) {
            error_log("UserService::updateUser error: " . $e->getMessage());
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
            $stmt = $this->db->prepare("SELECT username FROM users WHERE id = :id");
            $stmt->execute(['id' => $userId]);
            $user = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if (!$user) {
                return ['success' => false, 'error' => 'User not found'];
            }
            
            // Delete user
            $stmt = $this->db->prepare("DELETE FROM users WHERE id = :id");
            $stmt->execute(['id' => $userId]);
            
            // Clear caches
            $this->clearUsersCache();
            $this->clearUserSession($user['username']);
            
            return ['success' => true];
            
        } catch (\PDOException $e) {
            error_log("UserService::deleteUser error: " . $e->getMessage());
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
            error_log("UserService::getAllUsers Redis error: " . $e->getMessage());
            // Continue to database query if Redis fails
        }
        
        try {
            $sql = "SELECT id, username, role, email, display_name, is_active, created_at, updated_at, last_login 
                    FROM users";
            
            if (!$includeInactive) {
                $sql .= " WHERE is_active = TRUE";
            }
            
            $sql .= " ORDER BY 
                CASE role 
                    WHEN 'root' THEN 1
                    WHEN 'administrator' THEN 2
                    WHEN 'moderator' THEN 3
                    WHEN 'simple_user' THEN 4
                END,
                username ASC";
            
            $stmt = $this->db->query($sql);
            $users = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            $users = array_map([$this, 'sanitizeUser'], $users);
            
            // Cache the results for 5 minutes
            try {
                $prefix = Database::getRedisPrefix();
                $this->redis->setex($prefix . $cacheKey, 300, json_encode($users));
            } catch (\Exception $e) {
                error_log("UserService::getAllUsers cache set error: " . $e->getMessage());
            }
            
            return $users;
            
        } catch (\PDOException $e) {
            error_log("UserService::getAllUsers error: " . $e->getMessage());
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
            $stmt = $this->db->prepare("
                SELECT id, username, role, email, is_active, created_at, updated_at, last_login
                FROM users
                WHERE id = :id
            ");
            $stmt->execute(['id' => $userId]);
            $user = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            return $user ? $this->sanitizeUser($user) : null;
            
        } catch (\PDOException $e) {
            error_log("UserService::getUserById error: " . $e->getMessage());
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
            $stmt = $this->db->prepare("
                SELECT id, username, role, email, is_active, created_at, updated_at, last_login
                FROM users
                WHERE username = :username
            ");
            $stmt->execute(['username' => $username]);
            $user = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            return $user ? $this->sanitizeUser($user) : null;
            
        } catch (\PDOException $e) {
            error_log("UserService::getUserByUsername error: " . $e->getMessage());
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
            $stmt = $this->db->prepare("
                SELECT id, username, password_hash, role, email, display_name, is_active
                FROM users
                WHERE username = :identifier OR email = :identifier
            ");
            $stmt->execute(['identifier' => $identifier]);
            $user = $stmt->fetch(\PDO::FETCH_ASSOC);
            
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
            
        } catch (\PDOException $e) {
            error_log("UserService::authenticate error: " . $e->getMessage());
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
            $stmt = $this->db->prepare("UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = :id");
            $stmt->execute(['id' => $userId]);
        } catch (\PDOException $e) {
            error_log("UserService::updateLastLogin error: " . $e->getMessage());
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
     */
    private function clearUsersCache(): void
    {
        try {
            $prefix = Database::getRedisPrefix();
            // Clear both active and all users cache
            $this->redis->del($prefix . 'users:list:active');
            $this->redis->del($prefix . 'users:list:all');
        } catch (\Exception $e) {
            error_log("UserService::clearUsersCache error: " . $e->getMessage());
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
            error_log("UserService::clearUserSession error: " . $e->getMessage());
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
        // Fetch active users from the database
        $stmt = $this->db->prepare("SELECT * FROM users WHERE is_fake = 0 AND is_active = 1");
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
