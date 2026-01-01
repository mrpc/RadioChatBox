<?php

namespace RadioChatBox;

use Redis;
use PDO;

class ChatService
{
    private Redis $redis;
    private PDO $pdo;
    private string $prefix;
    private const MESSAGES_KEY = 'chat:messages';
    private const PUBSUB_CHANNEL = 'chat:updates';
    private const RATE_LIMIT_PREFIX = 'ratelimit:';
    private const ACTIVE_USERS_KEY = 'chat:active_users';
    private const USER_UPDATE_CHANNEL = 'chat:user_updates';

    public function __construct()
    {
        $this->redis = Database::getRedis();
        $this->pdo = Database::getPDO();
        $this->prefix = Database::getRedisPrefix();
    }
    
    /**
     * Add prefix to Redis key for multi-instance support
     */
    private function prefixKey(string $key): string
    {
        return $this->prefix . $key;
    }

    /**
     * Post a new message to the chat
     */
    public function postMessage(string $username, string $message, string $ipAddress, string $sessionId = '', ?string $replyTo = null): array
    {
        // Validate inputs
        if (empty($username) || empty($message)) {
            throw new \InvalidArgumentException('Username and message are required');
        }
        
        // Message length validation
        $maxLength = 500;
        if (mb_strlen($message) > $maxLength) {
            throw new \InvalidArgumentException("Message too long (max {$maxLength} characters)");
        }
        
        if (mb_strlen($username) > 50) {
            throw new \InvalidArgumentException('Username too long (max 50 characters)');
        }

        // Check if IP is banned
        if ($this->isIPBanned($ipAddress)) {
            throw new \RuntimeException('Your IP address has been banned from the chat.');
        }

        // Check if nickname is banned
        if ($this->isNicknameBanned($username)) {
            throw new \RuntimeException('This nickname is not allowed.');
        }

        // Check rate limit
        if (!$this->checkRateLimit($ipAddress)) {
            throw new \RuntimeException('Rate limit exceeded. Please wait before sending another message.');
        }
        
        // Validate reply_to if provided
        $replyData = null;
        if (!empty($replyTo)) {
            $replyData = $this->getReplyMessageData($replyTo);
        }

        // Create message object
        $messageData = [
            'id' => uniqid('msg_', true),
            'username' => $username,
            'message' => $message,
            'timestamp' => time(),
            'ip' => $ipAddress,
            'reply_to' => $replyTo,
            'reply_data' => $replyData,
        ];

        // Store in Redis (for real-time)
        $this->redis->lPush($this->prefixKey(self::MESSAGES_KEY), json_encode($messageData));
        $this->redis->lTrim($this->prefixKey(self::MESSAGES_KEY), 0, Config::get('chat')['history_limit'] - 1);
        
        // No TTL needed - list is already limited by lTrim and messages are persisted in PostgreSQL

        // Publish to subscribers
        $this->redis->publish($this->prefixKey(self::PUBSUB_CHANNEL), json_encode($messageData));

        // Store in PostgreSQL (for persistence)
        $this->storeMessageInDB($messageData);

        return $messageData;
    }

    /**
     * Get message history from Redis
     */
    public function getHistory(int $limit = 50): array
    {
        $limit = min($limit, Config::get('chat')['history_limit']);
        $messages = $this->redis->lRange($this->prefixKey(self::MESSAGES_KEY), 0, $limit - 1);
        
        // If Redis is empty, fallback to PostgreSQL
        if (empty($messages)) {
            return $this->loadHistoryFromDB($limit);
        }
        
        $decodedMessages = array_map(function($msg) {
            return json_decode($msg, true);
        }, $messages);
        
        if (empty($decodedMessages)) {
            return $this->loadHistoryFromDB($limit);
        }
        
        // Get all message IDs
        $messageIds = array_filter(array_map(function($msg) {
            return $msg['id'] ?? null;
        }, $decodedMessages));
        
        if (empty($messageIds)) {
            return array_reverse($decodedMessages);
        }
        
        // Batch query to check which messages are deleted
        $placeholders = str_repeat('?,', count($messageIds) - 1) . '?';
        $stmt = $this->pdo->prepare("SELECT message_id FROM messages WHERE message_id IN ($placeholders) AND is_deleted = true");
        $stmt->execute(array_values($messageIds));
        $deletedIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $deletedIdsSet = array_flip($deletedIds);
        
        // Filter out deleted messages
        $filteredMessages = array_filter($decodedMessages, function($msg) use ($deletedIdsSet) {
            return !isset($msg['id']) || !isset($deletedIdsSet[$msg['id']]);
        });
        
        return array_reverse(array_values($filteredMessages));
    }

    /**
     * Load message history from PostgreSQL (fallback when Redis is empty)
     */
    private function loadHistoryFromDB(int $limit = 50): array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT m.message_id, m.username, m.message, m.ip_address, m.created_at, m.reply_to,
                        r.username as reply_username, r.message as reply_message
                 FROM messages m
                 LEFT JOIN messages r ON m.reply_to = r.message_id
                 WHERE m.is_deleted = false 
                 ORDER BY m.created_at DESC 
                 LIMIT :limit'
            );
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Convert to the same format as Redis messages
            $messages = array_map(function($row) {
                $msg = [
                    'id' => $row['message_id'],
                    'username' => $row['username'],
                    'message' => $row['message'],
                    'timestamp' => strtotime($row['created_at']),
                    'ip' => $row['ip_address'],
                    'reply_to' => $row['reply_to'],
                ];
                
                // Add reply data if exists
                if (!empty($row['reply_to']) && !empty($row['reply_username'])) {
                    $msg['reply_data'] = [
                        'username' => $row['reply_username'],
                        'message' => mb_substr($row['reply_message'], 0, 100),
                    ];
                }
                
                return $msg;
            }, $rows);
            
            // Repopulate Redis cache with messages from DB
            // DB returns DESC (newest first), we need to push them so newest is at position 0
            // Use lPush which adds to the head, so push in reverse order (oldest first)
            if (!empty($messages)) {
                // Reverse so we push oldest first, making newest end up at position 0
                foreach (array_reverse($messages) as $msg) {
                    $this->redis->lPush($this->prefixKey(self::MESSAGES_KEY), json_encode($msg));
                }
                $this->redis->lTrim($this->prefixKey(self::MESSAGES_KEY), 0, Config::get('chat')['history_limit'] - 1);
            }
            
            // Return in chronological order (oldest first) to match getHistory() behavior
            return array_reverse($messages);
        } catch (PDOException $e) {
            error_log("Failed to load history from DB: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Check if user can send a message (rate limiting)
     */
    private function checkRateLimit(string $ipAddress): bool
    {
        // Get rate limit settings with caching
        $rateLimitMessages = 10; // default
        $rateLimitWindow = 60; // default
        
        try {
            $cacheKey = 'settings:rate_limit';
            $cached = $this->redis->get($this->prefixKey($cacheKey));
            
            if ($cached !== false) {
                $settings = json_decode($cached, true);
                $rateLimitMessages = $settings['messages'] ?? 10;
                $rateLimitWindow = $settings['window'] ?? 60;
            } else {
                // Cache miss - fetch from database
                $stmt = $this->pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
                
                $stmt->execute(['rate_limit_messages']);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($result) {
                    $rateLimitMessages = (int)$result['setting_value'];
                }
                
                $stmt->execute(['rate_limit_window']);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($result) {
                    $rateLimitWindow = (int)$result['setting_value'];
                }
                
                // Cache for 5 minutes
                $this->redis->setex($this->prefixKey($cacheKey), 300, json_encode([
                    'messages' => $rateLimitMessages,
                    'window' => $rateLimitWindow
                ]));
            }
        } catch (\PDOException $e) {
            // Use defaults if unable to fetch from database
            error_log("Failed to get rate limit settings: " . $e->getMessage());
        }
        
        $key = self::RATE_LIMIT_PREFIX . $ipAddress;
        $current = $this->redis->get($this->prefixKey($key));
        
        if ($current !== false && (int)$current >= $rateLimitMessages) {
            // Track repeated violations for auto-ban
            $this->trackViolation($ipAddress, 'rate_limit');
            return false;
        }
        
        // Increment counter
        $this->redis->incr($this->prefixKey($key));
        $this->redis->expire($this->prefixKey($key), $rateLimitWindow);
        
        return true;
    }
    
    /**
     * Track violations and auto-ban repeat offenders
     */
    private function trackViolation(string $ipAddress, string $violationType): void
    {
        try {
            $key = "violations:{$violationType}:{$ipAddress}";
            $violations = (int)$this->redis->get($this->prefixKey($key));
            
            // Increment violation counter
            $this->redis->incr($this->prefixKey($key));
            $this->redis->expire($this->prefixKey($key), 3600); // Track violations for 1 hour
            
            $violations++; // Current violation count
            
            // Auto-ban thresholds
            $thresholds = [
                'rate_limit' => 3,  // Ban after 3 rate limit violations in 1 hour
                'spam_url' => 3,    // Ban after 3 spam URL attempts in 1 hour
            ];
            
            $threshold = $thresholds[$violationType] ?? 5;
            
            if ($violations >= $threshold) {
                // Auto-ban for 24 hours
                $reason = "Automatic ban: Repeated {$violationType} violations ({$violations} times)";
                $this->banIP($ipAddress, $reason, 'system', 1); // 1 day ban
                
                // Clear violation counter
                $this->redis->del($this->prefixKey($key));
                
                error_log("Auto-banned IP {$ipAddress} for {$violationType} violations (count: {$violations})");
            } else {
                $remaining = $threshold - $violations;
                error_log("Violation tracked for {$ipAddress}: {$violationType} (violations: {$violations}, {$remaining} more until auto-ban)");
            }
        } catch (\Exception $e) {
            error_log("Failed to track violation: " . $e->getMessage());
        }
    }
    
    /**
     * Store message in PostgreSQL for permanent logging
     */
    private function storeMessageInDB(array $messageData): void
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO messages (message_id, username, message, ip_address, created_at, reply_to) 
                 VALUES (:message_id, :username, :message, :ip_address, :created_at, :reply_to)'
            );

            $stmt->execute([
                'message_id' => $messageData['id'],
                'username' => $messageData['username'],
                'message' => $messageData['message'],
                'ip_address' => $messageData['ip'],
                'created_at' => date('Y-m-d H:i:s', $messageData['timestamp']),
                'reply_to' => $messageData['reply_to'] ?? null,
            ]);
        } catch (\PDOException $e) {
            // Log error but don't fail the request
            error_log("Failed to store message in database: " . $e->getMessage());
        }
    }
    
    /**
     * Get reply message data for quoting
     */
    private function getReplyMessageData(string $messageId): ?array
    {
        try {
            // First check Redis cache
            $messages = $this->redis->lRange($this->prefixKey(self::MESSAGES_KEY), 0, -1);
            foreach ($messages as $msg) {
                $decoded = json_decode($msg, true);
                if (isset($decoded['id']) && $decoded['id'] === $messageId) {
                    return [
                        'username' => $decoded['username'],
                        'message' => mb_substr($decoded['message'], 0, 100), // Truncate to 100 chars for quote
                    ];
                }
            }
            
            // Fallback to database
            $stmt = $this->pdo->prepare(
                'SELECT username, message FROM messages WHERE message_id = :message_id AND is_deleted = false LIMIT 1'
            );
            $stmt->execute(['message_id' => $messageId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($row) {
                return [
                    'username' => $row['username'],
                    'message' => mb_substr($row['message'], 0, 100), // Truncate to 100 chars for quote
                ];
            }
        } catch (\PDOException $e) {
            error_log("Failed to get reply message data: " . $e->getMessage());
        }
        
        return null;
    }

    /**
     * Sanitize user input
     */
    private function sanitize(string $input, int $maxLength): string
    {
        $input = trim($input);
        $input = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
        return mb_substr($input, 0, $maxLength);
    }

    /**
     * Subscribe to Redis pub/sub for SSE
     */
    public function subscribe(callable $callback): void
    {
        $this->redis->subscribe([self::PUBSUB_CHANNEL, self::USER_UPDATE_CHANNEL], function($redis, $channel, $message) use ($callback) {
            if ($channel === self::PUBSUB_CHANNEL) {
                $callback($message);
            } elseif ($channel === self::USER_UPDATE_CHANNEL) {
                // Send user update event
                echo "event: users\n";
                echo "data: " . $message . "\n\n";
                flush();
            }
        });
    }

    /**
     * Check if a session is authenticated as a specific registered user
     * 
     * @param string $username The username to check
     * @param string $sessionId The session ID to verify
     * @return bool True if the session is authenticated as this user
     */
    private function isSessionAuthenticatedAsUser(string $username, string $sessionId): bool
    {
        try {
            // Check if this session has a user_id matching the registered username
            $stmt = $this->pdo->prepare(
                'SELECT s.user_id, u.username 
                 FROM sessions s 
                 INNER JOIN users u ON s.user_id = u.id 
                 WHERE s.session_id = :session_id AND u.username = :username'
            );
            $stmt->execute([
                'session_id' => $sessionId,
                'username' => $username
            ]);
            
            return $stmt->fetch(\PDO::FETCH_ASSOC) !== false;
        } catch (\PDOException $e) {
            error_log("Failed to check session authentication: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if nickname is already taken by an active user
     */
    public function isNicknameAvailable(string $nickname, string $sessionId = ''): bool
    {
        $nickname = $this->sanitize($nickname, 50);
        
        // Clean up inactive sessions first
        $this->cleanupInactiveSessions();
        
        // Check if this is a registered username
        $stmt = $this->pdo->prepare(
            'SELECT id, username FROM users WHERE username = :username'
        );
        $stmt->execute(['username' => $nickname]);
        $registeredUser = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        // If it's a registered username, only allow if the session is authenticated as that user
        if ($registeredUser !== false) {
            // Check if this session is authenticated as this user
            if (empty($sessionId)) {
                return false; // No session provided, cannot authenticate
            }
            
            return $this->isSessionAuthenticatedAsUser($nickname, $sessionId);
        }
        
        // Check if this is a fake user nickname (guests cannot use fake user nicknames)
        $stmt = $this->pdo->prepare(
            'SELECT id, nickname FROM fake_users WHERE nickname = :nickname'
        );
        $stmt->execute(['nickname' => $nickname]);
        $fakeUser = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        // If it's a fake user nickname, deny it for guests
        if ($fakeUser !== false) {
            return false;
        }
        
        // For non-registered nicknames, check if taken by another session
        $stmt = $this->pdo->prepare(
            'SELECT session_id FROM sessions WHERE LOWER(username) = LOWER(:username)'
        );
        $stmt->execute(['username' => $nickname]);
        $existingSession = $stmt->fetchColumn();
        
        // Available if no one has it, or if the same session already has it
        return $existingSession === false || $existingSession === $sessionId;
    }

    /**
     * Register a user as active
     */
    public function registerUser(string $username, string $sessionId, string $ipAddress, ?string $age = null, ?string $location = null, ?string $sex = null): bool
    {
        $username = $this->sanitize($username, 50);
        
        // Validate age if provided (18+ requirement)
        if ($age !== null) {
            $ageInt = intval($age);
            if ($ageInt < 18 || $ageInt > 120) {
                throw new \InvalidArgumentException('Age must be between 18 and 120');
            }
        }
        
        // Check if session is banned (from being kicked)
        $sessionBanKey = 'banned_session:' . $sessionId;
        if ($this->redis->exists($sessionBanKey)) {
            error_log("Registration blocked: session {$sessionId} is banned (kicked user)");
            return false;
        }
        
        // Check bans
        if ($this->isIPBanned($ipAddress) || $this->isNicknameBanned($username)) {
            return false;
        }
        
        // Check if username matches a registered user account
        $stmt = $this->pdo->prepare(
            'SELECT id, username FROM users WHERE username = :username'
        );
        $stmt->execute(['username' => $username]);
        $registeredUser = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if ($registeredUser !== false) {
            // This is a registered username - verify session is authenticated as this user
            if (!$this->isSessionAuthenticatedAsUser($username, $sessionId)) {
                error_log("Registration blocked: username '{$username}' is a registered account and session {$sessionId} is not authenticated as this user");
                return false;
            }
            // Authenticated users are allowed to have multiple sessions (different devices)
            // Continue to registration below
        } else {
            // Check if username matches a fake user nickname
            $stmt = $this->pdo->prepare(
                'SELECT id, nickname FROM fake_users WHERE nickname = :username'
            );
            $stmt->execute(['username' => $username]);
            $fakeUser = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if ($fakeUser !== false) {
                // Guests cannot use fake user nicknames
                error_log("Registration blocked: username '{$username}' is a fake user nickname");
                return false;
            }
            // For non-registered usernames, enforce one session per username
            $stmt = $this->pdo->prepare(
                'SELECT session_id FROM sessions WHERE username = :username'
            );
            $stmt->execute(['username' => $username]);
            $existingUser = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if ($existingUser && $existingUser['session_id'] !== $sessionId) {
                // Username is taken by another active session
                error_log("Registration blocked: username '{$username}' is already taken by another user");
                return false;
            }
        }
        
        try {
            // Insert or update active session
            // Note: ON CONFLICT now uses (username, session_id) to allow multiple sessions for authenticated users
            $stmt = $this->pdo->prepare(
                'INSERT INTO sessions (username, session_id, ip_address, last_heartbeat, joined_at)
                 VALUES (:username, :session_id, :ip_address, NOW(), NOW())
                 ON CONFLICT (username, session_id) DO UPDATE SET
                     ip_address = :ip_address,
                     last_heartbeat = NOW()'
            );
            
            $stmt->execute([
                'username' => $username,
                'session_id' => $sessionId,
                'ip_address' => $ipAddress,
            ]);
            
            // Track IP address in user_activity even if no message is sent yet
            $stmt = $this->pdo->prepare(
                'INSERT INTO user_activity (username, ip_address, first_seen, last_seen, message_count, user_id)
                 VALUES (:username, :ip_address, NOW(), NOW(), 0, :user_id)
                 ON CONFLICT (username) DO UPDATE SET
                     ip_address = :ip_address,
                     last_seen = NOW()'
            );
            
            $stmt->execute([
                'username' => $username,
                'ip_address' => $ipAddress,
                'user_id' => $registeredUser !== false ? $registeredUser['id'] : null,
            ]);
            
            // Store user profile if any profile data provided
            if ($age !== null || $location !== null || $sex !== null) {
                $stmt = $this->pdo->prepare(
                    'INSERT INTO user_profiles (username, session_id, age, location, sex)
                     VALUES (:username, :session_id, :age, :location, :sex)
                     ON CONFLICT (username, session_id) DO UPDATE SET
                         age = :age,
                         location = :location,
                         sex = :sex'
                );
                
                $stmt->execute([
                    'username' => $username,
                    'session_id' => $sessionId,
                    'age' => $age,
                    'location' => $location,
                    'sex' => $sex,
                ]);
            }
            
            // Also cache in Redis for faster access
            $this->redis->hSet(self::ACTIVE_USERS_KEY, $username, json_encode([
                'username' => $username,
                'joined_at' => time(),
            ]));
            
            // Publish user update to SSE subscribers
            $this->publishUserUpdate();
            
            return true;
        } catch (\PDOException $e) {
            error_log("Failed to register user: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Logout user and remove their session
     */
    public function logoutUser(string $sessionId): bool
    {
        try {
            // Delete session from database
            $stmt = $this->pdo->prepare(
                'DELETE FROM sessions WHERE session_id = :session_id'
            );
            
            $result = $stmt->execute(['session_id' => $sessionId]);
            
            // Remove from Redis cache
            $this->redis->hDel(self::ACTIVE_USERS_KEY, $sessionId);
            
            // Publish user update after logout
            $this->publishUserUpdate();
            
            return $result;
        } catch (\PDOException $e) {
            error_log("Failed to logout user: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update user heartbeat
     */
    public function updateHeartbeat(string $username, string $sessionId): bool
    {
        try {
            // Clean up inactive sessions first (this might change the user list)
            $this->cleanupInactiveSessions();
            
            $stmt = $this->pdo->prepare(
                'UPDATE sessions 
                 SET last_heartbeat = NOW() 
                 WHERE username = :username AND session_id = :session_id'
            );
            
            $result = $stmt->execute([
                'username' => $username,
                'session_id' => $sessionId,
            ]);
            
            // Publish user update after heartbeat
            $this->publishUserUpdate();
            
            return $result;
        } catch (\PDOException $e) {
            error_log("Failed to update heartbeat: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get session information for validation
     */
    public function getSessionInfo(string $username, string $sessionId): ?array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT s.username, s.session_id, s.user_id, s.ip_address, s.last_heartbeat, u.role as user_role
                 FROM sessions s
                 LEFT JOIN users u ON s.user_id = u.id
                 WHERE s.username = :username AND s.session_id = :session_id'
            );
            
            $stmt->execute([
                'username' => $username,
                'session_id' => $sessionId,
            ]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ?: null;
        } catch (\PDOException $e) {
            error_log("Failed to get session info: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get list of active users
     */
    public function getActiveUsers(): array
    {
        $this->cleanupInactiveSessions();
        
        try {
            // Use DISTINCT ON to get only one row per username (PostgreSQL specific)
            // This ensures users logged in from multiple devices/browsers only appear once
            $stmt = $this->pdo->query(
                'SELECT DISTINCT ON (a.username)
                    a.username, 
                    a.joined_at, 
                    a.last_heartbeat,
                    p.age,
                    p.location,
                    p.sex
                 FROM sessions a
                 LEFT JOIN user_profiles p ON a.username = p.username
                 ORDER BY a.username, a.joined_at ASC'
            );
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log("Failed to get active users: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get count of active users (real users only, not fake)
     */
    public function getActiveUserCount(): int
    {
        $this->cleanupInactiveSessions();
        
        try {
            $stmt = $this->pdo->query('SELECT COUNT(*) FROM sessions');
            return (int)$stmt->fetchColumn();
        } catch (\PDOException $e) {
            error_log("Failed to get active user count: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get all users including real and fake users
     * This is what should be used for the active users list display
     */
    public function getAllUsers(): array
    {
        // Get real users
        $realUsers = $this->getActiveUsers();
        
        // Get active fake users
        $fakeUserService = new FakeUserService();
        $fakeUsers = $fakeUserService->getActiveFakeUsers();
        
        // Transform fake users to match real user format
        $formattedFakeUsers = array_map(function($user) {
            return [
                'username' => $user['nickname'],
                'age' => $user['age'],
                'sex' => $user['sex'],
                'location' => $user['location'],
                'is_fake' => true,
                'joined_at' => null,
                'last_heartbeat' => null
            ];
        }, $fakeUsers);
        
        // Combine and return
        return array_merge($realUsers, $formattedFakeUsers);
    }

    /**
     * Balance fake users based on current real user count
     * Call this after user joins/leaves to maintain minimum user count
     */
    public function balanceFakeUsers(): void
    {
        $realUserCount = $this->getActiveUserCount();
        $fakeUserService = new FakeUserService();
        $fakeUserService->balanceFakeUsers($realUserCount);
        
        // Publish user update after balancing fake users
        $this->publishUserUpdate();
    }

    /**
     * Remove user from active users
     */
    public function removeUser(string $username, string $sessionId): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                'DELETE FROM sessions 
                 WHERE username = :username AND session_id = :session_id'
            );
            
            $stmt->execute([
                'username' => $username,
                'session_id' => $sessionId,
            ]);
            
            $this->redis->hDel(self::ACTIVE_USERS_KEY, $username);
            
            // Publish user update to SSE subscribers
            $this->publishUserUpdate();
            
            return true;
        } catch (\PDOException $e) {
            error_log("Failed to remove user: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Clean up inactive sessions (not seen in 5 minutes)
     */
    private function cleanupInactiveSessions(): void
    {
        try {
            $this->pdo->exec("SELECT cleanup_inactive_sessions()");
        } catch (\PDOException $e) {
            error_log("Failed to cleanup inactive sessions: " . $e->getMessage());
        }
    }

    /**
     * Check if IP address is banned
     * Uses Redis cache to avoid hitting PostgreSQL on every message
     */
    private function isIPBanned(string $ipAddress): bool
    {
        try {
                        // Try cache first
            $cacheKey = 'banned_ips';
            $cached = $this->redis->get($this->prefixKey($cacheKey));
            
            if ($cached !== false) {
                $bannedIPs = json_decode($cached, true);
            } else {
                // Cache miss - fetch from database
                $stmt = $this->pdo->query(
                    'SELECT ip_address FROM banned_ips 
                     WHERE banned_until IS NULL OR banned_until > NOW()'
                );
                $bannedIPs = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                // Cache for 5 minutes
                $this->redis->setex($this->prefixKey($cacheKey), 300, json_encode($bannedIPs));
            }
            
            return in_array($ipAddress, $bannedIPs, true);
        } catch (\PDOException $e) {
            error_log("Failed to check IP ban: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if nickname is banned
     * Uses Redis cache to avoid hitting PostgreSQL on every message
     */
    private function isNicknameBanned(string $nickname): bool
    {
        try {
            // Try cache first
            $cacheKey = 'banned_nicknames';
            $cached = $this->redis->get($this->prefixKey($cacheKey));
            
            if ($cached !== false) {
                $bannedNicknames = json_decode($cached, true);
            } else {
                // Cache miss - fetch from database
                $stmt = $this->pdo->query('SELECT LOWER(nickname) FROM banned_nicknames');
                $bannedNicknames = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                // Cache for 5 minutes
                $this->redis->setex($this->prefixKey($cacheKey), 300, json_encode($bannedNicknames));
            }
            
            return in_array(strtolower($nickname), $bannedNicknames, true);
        } catch (\PDOException $e) {
            error_log("Failed to check nickname ban: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get all messages from database with pagination
     */
    public function getAllMessages(int $limit = 100, int $offset = 0): array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT message_id, username, message, ip_address, created_at, is_deleted
                 FROM messages 
                 ORDER BY created_at DESC 
                 LIMIT :limit OFFSET :offset'
            );
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log("Failed to get all messages: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get total count of messages (for admin pagination)
     */
    public function getTotalMessagesCount(): int
    {
        try {
            $stmt = $this->pdo->query('SELECT COUNT(*) FROM messages');
            return (int)$stmt->fetchColumn();
        } catch (\PDOException $e) {
            error_log("Failed to get messages count: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get total count of active users (for admin pagination)
     */
    public function getTotalActiveUsersCount(): int
    {
        try {
            $stmt = $this->pdo->query('SELECT COUNT(*) FROM sessions');
            return (int)$stmt->fetchColumn();
        } catch (\PDOException $e) {
            error_log("Failed to get active users count: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Ban an IP address
     */
    public function banIP(string $ipAddress, string $reason = '', string $bannedBy = 'admin', ?int $durationDays = null): bool
    {
        try {
            $bannedUntil = $durationDays ? date('Y-m-d H:i:s', strtotime("+{$durationDays} days")) : null;
            
            $stmt = $this->pdo->prepare(
                'INSERT INTO banned_ips (ip_address, reason, banned_by, banned_until)
                 VALUES (:ip, :reason, :banned_by, :banned_until)
                 ON CONFLICT (ip_address) DO UPDATE SET
                     reason = :reason,
                     banned_until = :banned_until,
                     banned_by = :banned_by'
            );
            
            $result = $stmt->execute([
                'ip' => $ipAddress,
                'reason' => $reason,
                'banned_by' => $bannedBy,
                'banned_until' => $bannedUntil,
            ]);
            
            // Invalidate Redis cache
            if ($result) {
                $this->redis->del($this->prefixKey('banned_ips'));
            }
            
            return $result;
        } catch (\PDOException $e) {
            error_log("Failed to ban IP: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Unban an IP address
     */
    public function unbanIP(string $ipAddress): bool
    {
        try {
            $stmt = $this->pdo->prepare('DELETE FROM banned_ips WHERE ip_address = :ip');
            $result = $stmt->execute(['ip' => $ipAddress]);
            
            // Invalidate Redis cache
            if ($result) {
                $this->redis->del($this->prefixKey('banned_ips'));
            }
            
            return $result;
        } catch (\PDOException $e) {
            error_log("Failed to unban IP: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Ban a nickname
     */
    public function banNickname(string $nickname, string $reason = '', string $bannedBy = 'admin'): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO banned_nicknames (nickname, reason, banned_by)
                 VALUES (:nickname, :reason, :banned_by)
                 ON CONFLICT (nickname) DO UPDATE SET
                     reason = :reason,
                     banned_by = :banned_by'
            );
            
            $result = $stmt->execute([
                'nickname' => $nickname,
                'reason' => $reason,
                'banned_by' => $bannedBy,
            ]);
            
            // Remove from active sessions if currently online
            $this->pdo->prepare('DELETE FROM sessions WHERE LOWER(username) = LOWER(:nickname)')
                      ->execute(['nickname' => $nickname]);
            
            // Invalidate Redis cache
            if ($result) {
                $this->redis->del($this->prefixKey('banned_nicknames'));
            }
            
            return $result;
        } catch (\PDOException $e) {
            error_log("Failed to ban nickname: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Unban a nickname
     */
    public function unbanNickname(string $nickname): bool
    {
        try {
            $stmt = $this->pdo->prepare('DELETE FROM banned_nicknames WHERE LOWER(nickname) = LOWER(:nickname)');
            $result = $stmt->execute(['nickname' => $nickname]);
            
            // Invalidate Redis cache
            if ($result) {
                $this->redis->del($this->prefixKey('banned_nicknames'));
            }
            
            return $result;
        } catch (\PDOException $e) {
            error_log("Failed to unban nickname: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get all banned IPs
     */
    public function getBannedIPs(): array
    {
        try {
            $stmt = $this->pdo->query(
                'SELECT ip_address, reason, banned_at, banned_until, banned_by
                 FROM banned_ips 
                 ORDER BY banned_at DESC'
            );
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log("Failed to get banned IPs: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get all banned nicknames
     */
    public function getBannedNicknames(): array
    {
        try {
            $stmt = $this->pdo->query(
                'SELECT nickname, reason, banned_at, banned_by
                 FROM banned_nicknames 
                 ORDER BY banned_at DESC'
            );
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log("Failed to get banned nicknames: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Publish active user update to SSE subscribers
     */
    private function publishUserUpdate(): void
    {
        try {
            $this->cleanupInactiveSessions();
            
            // Use getAllUsers() to include fake users
            $users = $this->getAllUsers();
            $count = count($users);
            
            $updateData = json_encode([
                'count' => $count,
                'users' => $users
            ]);
            
            $this->redis->publish(self::USER_UPDATE_CHANNEL, $updateData);
        } catch (\Exception $e) {
            error_log("Failed to publish user update: " . $e->getMessage());
        }
    }
    
    /**
     * Get a setting value from database
     */
    public function getSetting(string $key, $default = null)
    {
        try {
            $stmt = $this->pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
            $stmt->execute([$key]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $result ? $result['setting_value'] : $default;
        } catch (\PDOException $e) {
            error_log("Failed to get setting: " . $e->getMessage());
            return $default;
        }
    }
    
    /**
     * Get multiple settings at once
     */
    public function getSettings(array $keys = []): array
    {
        try {
            if (empty($keys)) {
                $stmt = $this->pdo->query("SELECT setting_key, setting_value FROM settings");
            } else {
                $placeholders = str_repeat('?,', count($keys) - 1) . '?';
                $stmt = $this->pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ($placeholders)");
                $stmt->execute($keys);
            }
            
            $settings = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
            
            return $settings;
        } catch (\PDOException $e) {
            error_log("Failed to get settings: " . $e->getMessage());
            return [];
        }
    }
}
