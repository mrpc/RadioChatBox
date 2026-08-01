<?php

namespace RadioChatBox\Services;

use RadioChatBox\AdminAuth;
use Pramnos\Cache\FlatCache;
use Pramnos\Database\Database;
use Pramnos\Auth\Loginlockout;
/**
 * User Service - Admin User Management with RBAC
 * 
 * Manages admin users with role-based access control.
 * Roles: root, administrator, moderator, simple_user
 */
class UserService
{
    private \Pramnos\Database\Database $db;

    // The role hierarchy and permission map moved to the Authz facade when the
    // `users.role` enum was converged to the framework `users.usertype` integer.
    // The app still speaks role labels (create/update/display); Authz maps them
    // to/from usertype and reproduces the original permission decisions exactly.

    public function __construct()
    {
        $this->db = Database::getInstance();
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
        
        // Validate role (a label; converted to usertype for storage)
        if (!Authz::isValidLabel($role)) {
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
                ->returning('userid', 'username', 'usertype', 'email', 'display_name', 'created_at')
                ->insert([
                    'username'      => $username,
                    'password'      => $passwordHash,
                    'usertype'      => Authz::usertypeForLabel($role),
                    'email'         => $email,
                    'created_by'    => $createdBy,
                    'display_name'  => $displayName,
                ]);

            // The framework returns false on a DB error instead of throwing, so
            // inspect the driver error text: PostgreSQL reports a unique violation
            // (SQLSTATE 23505) as "duplicate key value violates unique constraint".
            if ($result === false) {
                \Pramnos\Logs\Logger::log("UserService::createUser error: "
                    . (string) ($this->db->getError()['message'] ?? 'insert failed'), 'radiochatbox');
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
            \Pramnos\Logs\Logger::log("UserService::createUser error: " . $msg, 'radiochatbox');
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
                $updateData['password'] = password_hash($value, PASSWORD_DEFAULT);
            } elseif ($field === 'role') {
                if (!Authz::isValidLabel($value)) {
                    return ['success' => false, 'error' => 'Invalid role'];
                }
                // The app updates a role LABEL; store it as the usertype ladder.
                $updateData['usertype'] = Authz::usertypeForLabel($value);
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
                ->where('userid', '=', $userId)
                ->returning('userid', 'username', 'usertype', 'email', 'display_name', 'is_active', 'updated_at')
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

        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log("UserService::updateUser error: " . $e->getMessage(), 'radiochatbox');
            return ['success' => false, 'error' => 'Database error'];
        }
        // @codeCoverageIgnoreEnd
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
                ->where('userid', '=', $userId)
                ->value('username');

            if ($username === null) {
                return ['success' => false, 'error' => 'User not found'];
            }

            // Delete user
            $this->db->queryBuilder()
                ->from('users')
                ->where('userid', '=', $userId)
                ->delete();

            // Clear caches
            $this->clearUsersCache();
            $this->clearUserSession($username);

            return ['success' => true];

        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log("UserService::deleteUser error: " . $e->getMessage(), 'radiochatbox');
            return ['success' => false, 'error' => 'Database error'];
        }
        // @codeCoverageIgnoreEnd
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
            $cached = FlatCache::default()->get($cacheKey);

            if (is_array($cached)) {
                return $cached;
            }
        // @codeCoverageIgnoreStart
        } catch (\Exception $e) {
            \Pramnos\Logs\Logger::log("UserService::getAllUsers Redis error: " . $e->getMessage(), 'radiochatbox');
            // Continue to database query if Redis fails
        }
        // @codeCoverageIgnoreEnd
        
        try {
            $qb = $this->db->queryBuilder()
                ->from('users')
                ->select(['userid', 'username', 'usertype', 'email', 'display_name', 'is_active', 'created_at', 'updated_at', 'last_login']);

            if (!$includeInactive) {
                $qb->whereRaw('is_active = TRUE');
            }

            // Most-privileged first (root=99 → simple_user=0), then by name.
            $qb->orderBy('usertype', 'desc')->orderBy('username', 'asc');

            $users = $qb->getAll();

            $users = array_map([$this, 'sanitizeUser'], $users);
            
            // Cache the results for 5 minutes
            try {
                FlatCache::default()->set($cacheKey, $users, 300);
            // @codeCoverageIgnoreStart
            } catch (\Exception $e) {
                \Pramnos\Logs\Logger::log("UserService::getAllUsers cache set error: " . $e->getMessage(), 'radiochatbox');
            }
            // @codeCoverageIgnoreEnd
            
            return $users;

        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log("UserService::getAllUsers error: " . $e->getMessage(), 'radiochatbox');
            return [];
        }
        // @codeCoverageIgnoreEnd
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
                ->select(['userid', 'username', 'usertype', 'email', 'is_active', 'created_at', 'updated_at', 'last_login'])
                ->where('userid', '=', $userId)
                ->first();
            $user = ($row && $row->numRows > 0) ? $row->fields : null;

            return $user ? $this->sanitizeUser($user) : null;

        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log("UserService::getUserById error: " . $e->getMessage(), 'radiochatbox');
            return null;
        }
        // @codeCoverageIgnoreEnd
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
                ->select(['userid', 'username', 'usertype', 'email', 'is_active', 'created_at', 'updated_at', 'last_login'])
                ->where('username', '=', $username)
                ->first();
            $user = ($row && $row->numRows > 0) ? $row->fields : null;

            return $user ? $this->sanitizeUser($user) : null;

        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log("UserService::getUserByUsername error: " . $e->getMessage(), 'radiochatbox');
            return null;
        }
        // @codeCoverageIgnoreEnd
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
        // Brute-force protection via the framework's native Loginlockout
        // (authserver.loginlockouts; progressive 3→60s, 5→5m, 7→15m, 10→1h). This
        // guards BOTH the chat and admin login, which both come through here.
        // Fail-open: any lockout-infrastructure error must NEVER block a genuine
        // login, so every lockout call is guarded.
        $lockId  = mb_strtolower(trim($identifier));
        $lockout = $this->loginLockout();
        if ($lockout !== null && $lockId !== '') {
            try {
                if (($lockout->getLockoutStatus('identifier', $lockId)['locked'] ?? false) === true) {
                    return null; // temporarily locked → treat as a failed login
                }
            } catch (\Throwable) {
                $lockout = null; // lockout store unavailable → don't use it this call
            }
        }

        try {
            // Try both username and email
            $row = $this->db->queryBuilder()
                ->from('users')
                ->select(['userid', 'username', 'password', 'usertype', 'email', 'display_name', 'is_active'])
                ->whereRaw('username = %s OR email = %s', [$identifier, $identifier])
                ->first();
            $user = ($row && $row->numRows > 0) ? $row->fields : null;

            if (!$user) {
                $this->recordAuthFailure($lockout, $lockId);
                return null;
            }

            // Check if user is active
            if (!$user['is_active']) {
                $this->recordAuthFailure($lockout, $lockId);
                return null;
            }

            // Verify password
            if (!password_verify($password, $user['password'])) {
                $this->recordAuthFailure($lockout, $lockId);
                return null;
            }

            // Success: reset the failure counter and update last login.
            $this->clearAuthLockout($lockout, $lockId);
            $this->updateLastLogin($user['userid']);

            return $this->sanitizeUser($user);

        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log("UserService::authenticate error: " . $e->getMessage(), 'radiochatbox');
            return null;
        }
        // @codeCoverageIgnoreEnd
    }

    /** Build a Loginlockout, or null if it cannot be constructed (fail-open). */
    private function loginLockout(): ?Loginlockout
    {
        try {
            return new Loginlockout();
        } catch (\Throwable) {
            return null;
        }
    }

    /** Record a failed login attempt; never throws into the login path. */
    private function recordAuthFailure(?Loginlockout $lockout, string $lockId): void
    {
        if ($lockout === null || $lockId === '') {
            return;
        }
        try {
            $lockout->recordFailedAttempt('identifier', $lockId);
        } catch (\Throwable) {
            // best effort
        }
    }

    /** Clear lockout state after a successful login; never throws. */
    private function clearAuthLockout(?Loginlockout $lockout, string $lockId): void
    {
        if ($lockout === null || $lockId === '') {
            return;
        }
        try {
            $lockout->clearSuccessfulLoginState('identifier', $lockId);
        } catch (\Throwable) {
            // best effort
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
        return Authz::can(Authz::usertypeForLabel($role), $permission);
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
        return Authz::canManage(
            Authz::usertypeForLabel($currentUserRole),
            Authz::usertypeForLabel($targetUserRole)
        );
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
            $qb->where('userid', '=', $userId)->update(['last_login' => $qb->raw('CURRENT_TIMESTAMP')]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log("UserService::updateLastLogin error: " . $e->getMessage(), 'radiochatbox');
        }
        // @codeCoverageIgnoreEnd
    }
    
    /**
     * Remove sensitive data from user array
     * 
     * @param array $user User data
     * @return array Sanitized user data
     */
    private function sanitizeUser(array $user): array
    {
        unset($user['password']);
        // The app/SPA speak role LABELS; derive one from the usertype ladder so
        // callers keep receiving a `role` key after the enum column was dropped.
        if (isset($user['usertype'])) {
            $user['role'] = Authz::labelForUsertype((int) $user['usertype']);
        }
        return $user;
    }
    
    /**
     * Clear users cache in Redis
     * ENHANCED: Also clears display name and user data caches from performance optimizations
     */
    private function clearUsersCache(): void
    {
        try {
            // Clear both active and all users cache
            FlatCache::default()->delete('users:list:active');
            FlatCache::default()->delete('users:list:all');

            // Clear all display-name-related caches so an updated name shows
            // everywhere. (chat:messages / chat:messages:hash are the message-history
            // Redis structures — deleting the key forces getHistory() to rebuild from
            // the DB; the structures themselves are re-modeled in Phase 8 Step 4.)
            FlatCache::default()->delete('chat:all_users'); // Combined user list
            FlatCache::default()->delete('chat:messages'); // Message history
            FlatCache::default()->delete('chat:messages:hash'); // Message hash for replies

            // Note: Individual user_data:{username} caches will expire naturally in 5 minutes
            // or can be cleared per-user if we know which user was updated
        // @codeCoverageIgnoreStart
        } catch (\Exception $e) {
            \Pramnos\Logs\Logger::log("UserService::clearUsersCache error: " . $e->getMessage(), 'radiochatbox');
        }
        // @codeCoverageIgnoreEnd
    }
    
    /**
     * Clear user session in Redis (force re-login)
     *
     * @param string $username Username
     */
    private function clearUserSession(string $username): void
    {
        // AdminAuth owns the admin_session keyspace — ask it to destroy the session.
        AdminAuth::destroySession($username);
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
            // Clear legacy display_name cache (write-less; kept for safety)
            FlatCache::default()->delete("display_name:{$username}");
            // Clear new user_data cache (includes both user_id and display_name)
            FlatCache::default()->delete("user_data:{$username}");
        // @codeCoverageIgnoreStart
        } catch (\Exception $e) {
            \Pramnos\Logs\Logger::log("UserService::clearUserDataCache error: " . $e->getMessage(), 'radiochatbox');
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Get role level (for comparison)
     * 
     * @param string $role Role name
     * @return int Role level
     */
    public function getRoleLevel(string $role): int
    {
        // Legacy 0..3 ordinal (root=3), kept for callers/tests that compare
        // relative rank. The stored ladder is the Authz usertype (0/50/90/99).
        return ['simple_user' => 0, 'moderator' => 1, 'administrator' => 2, 'root' => 3][$role] ?? 0;
    }

    /**
     * Get all available roles
     *
     * @return array List of roles
     */
    public function getAvailableRoles(): array
    {
        return Authz::availableRoleLabels();
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
