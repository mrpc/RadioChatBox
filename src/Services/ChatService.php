<?php

namespace RadioChatBox\Services;

use Pramnos\Broadcasting\BroadcastingManager;
use Pramnos\Cache\FlatCache;
use RadioChatBox\KickRegistry;
use RadioChatBox\MessageHistory;
use Pramnos\Database\Database as PramnosDatabase;

class ChatService
{
    private PramnosDatabase $db;
    private MessageHistory $messageHistory;
    private const PUBSUB_CHANNEL = 'chat:updates';
    private const RATE_LIMIT_PREFIX = 'ratelimit:';
    private const USER_UPDATE_CHANNEL = 'chat:user_updates';

    public function __construct()
    {
        $this->db = PramnosDatabase::getInstance();
        $this->messageHistory = new MessageHistory();
    }

    /**
     * Post a new message to the chat
     */
    public function postMessage(string $username, string $message, string $ipAddress, string $sessionId = '', ?string $replyTo = null, ?string $pinnedTrack = null): array
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

        // Normalize the optional pinned track (snapshot of now-playing).
        $pinnedTrack = $pinnedTrack !== null ? trim($pinnedTrack) : null;
        if ($pinnedTrack === '') {
            $pinnedTrack = null;
        }
        if ($pinnedTrack !== null) {
            $pinnedTrack = mb_substr($pinnedTrack, 0, 500);
        }
        
        if (mb_strlen($username) > 50) {
            throw new \InvalidArgumentException('Username too long (max 50 characters)');
        }

        // A banned (IP/nickname) or kicked user cannot communicate with anyone.
        $banReason = $this->communicationBlockReason($username, $ipAddress, $sessionId);
        if ($banReason !== null) {
            throw new \RuntimeException($banReason);
        }

        // Check rate limit
        if (!$this->checkRateLimit($ipAddress)) {
            throw new \RuntimeException('Rate limit exceeded. Please wait before sending another message.');
        }

        // Slow mode: an admin-set minimum gap between a user's messages (0 = off).
        $this->enforceSlowMode($username);
        
        // Validate reply_to if provided
        $replyData = null;
        if (!empty($replyTo)) {
            $replyData = $this->getReplyMessageData($replyTo);
        }

        // PERFORMANCE OPTIMIZATION: Get user data once and pass to storeMessageInDB
        // This eliminates duplicate query for same user data
        $userData = $this->getUserDataForMessage($username);

        // Create message object
        $messageData = [
            'id' => uniqid('msg_', true),
            'username' => $username,
            'display_name' => $userData['display_name'],
            'message' => $message,
            'timestamp' => time(),
            'ip' => $ipAddress,
            'reply_to' => $replyTo,
            'reply_data' => $replyData,
            'user_id' => $userData['user_id'], // Pass user_id to avoid re-query
            'pinned_track' => $pinnedTrack,
        ];

        // Store in Redis (recent-history cache for real-time) + the O(1) reply
        // lookup hash, both owned by the MessageHistory repository.
        $this->messageHistory->append($messageData);
        $this->messageHistory->cacheReply($messageData['id'], [
            'username' => $messageData['username'],
            'display_name' => $messageData['display_name'],
            'message' => $messageData['message'],
        ]);

        // Sending a message IS activity: refresh the sender's presence so a user
        // whose heartbeat lapsed (mobile background, flaky network) does not show
        // as offline while actively chatting.
        $this->touchPresence($username, $sessionId, $ipAddress, isset($userData['user_id']) ? (int) $userData['user_id'] : null);

        // Publish to subscribers
        BroadcastingManager::instance()->broadcast(self::PUBSUB_CHANNEL, 'message', $messageData);

        // Live-refresh cue for the admin (messages tab + dashboard) so it never
        // polls for new public messages. Rides the admin channel as a signal, not
        // a user-facing notification. Best-effort.
        try {
            BroadcastingManager::instance()->broadcast(
                'chat:admin_notifications',
                'messages_changed',
                ['signal' => 'messages_changed']
            );
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('ChatService: messages_changed signal failed: ' . $e->getMessage(), 'radiochatbox');
        }
        // @codeCoverageIgnoreEnd

        // Store in PostgreSQL (for persistence) - user data already fetched
        $this->storeMessageInDB($messageData);

        return $messageData;
    }

    /**
     * Lightweight presence refresh (no cleanup/publish overhead): bumps
     * last_heartbeat for the sender's session. Any interaction that reaches this
     * keeps the user "online". Best-effort — never fails the surrounding action.
     */
    private function touchPresence(string $username, string $sessionId, string $ipAddress = '', ?int $userId = null): void
    {
        $this->refreshPresence($username, $sessionId, $ipAddress, $userId);
    }

    /**
     * Mark a user online by refreshing (or recreating) their presence session.
     * An UPSERT, not a bare UPDATE: if an inactivity cleanup already removed the
     * row, a plain UPDATE would touch nothing and the user — who is clearly active,
     * since they just did something — would stay "offline". Called on every message
     * send (public and DM) so an actively-chatting user always shows online.
     * Best-effort; never fails the surrounding action.
     */
    public function refreshPresence(string $username, string $sessionId, string $ipAddress = '', ?int $userId = null): void
    {
        if ($username === '' || $sessionId === '') {
            return;
        }
        $ip = $ipAddress !== '' ? $ipAddress : '0.0.0.0'; // ip_address is NOT NULL
        try {
            $qb = $this->db->queryBuilder()->from('presence_sessions');
            // Insert a fresh presence row, or (on the username+session unique
            // conflict) refresh the heartbeat and ip. user_id is left out of the
            // conflict-update so an existing row keeps its user link.
            $qb->upsert(
                [
                    'username'       => $username,
                    'session_id'     => $sessionId,
                    'ip_address'     => $ip,
                    'user_id'        => $userId,
                    'last_heartbeat' => $qb->raw('NOW()'),
                    'joined_at'      => $qb->raw('NOW()'),
                ],
                ['username', 'session_id'],
                ['last_heartbeat', 'ip_address']
            );
        } catch (\Throwable) {
            // presence is best-effort
        }
    }

    /**
     * Get message history from Redis
     *
     * OPTIMIZED: Now uses Redis HASH to track deleted messages instead of querying DB on every load.
     * Display names are cached separately with their own TTL.
     */
    public function getHistory(int $limit = 50): array
    {
        $limit = min($limit, (int) envvar('CHAT_HISTORY_LIMIT', 100));
        $decodedMessages = $this->messageHistory->recent($limit);

        // If the cache is empty, fallback to PostgreSQL
        if (empty($decodedMessages)) {
            return $this->loadHistoryFromDB($limit);
        }

        // Filter out deleted messages using the tombstone hash (PERFORMANCE OPTIMIZATION)
        // This eliminates the need to query the database on every history load
        $filteredMessages = [];

        foreach ($decodedMessages as $msg) {
            $messageId = $msg['id'] ?? null;
            if (!$messageId) {
                continue;
            }

            // Check the tombstone hash for deleted status (O(1) operation)
            if ($this->messageHistory->isDeleted($messageId)) {
                continue; // Skip deleted messages
            }

            // Get current display_name from cache (already cached per-user)
            if (isset($msg['username'])) {
                $cachedDisplayName = $this->getDisplayNameForUsername($msg['username']);
                if ($cachedDisplayName !== null) {
                    $msg['display_name'] = $cachedDisplayName;
                }
            }

            $filteredMessages[] = $msg;
        }

        return array_reverse($filteredMessages);
    }

    /**
     * Load message history from PostgreSQL (fallback when Redis is empty)
     */
    private function loadHistoryFromDB(int $limit = 50): array
    {
        try {
            $result = $this->db->preparedQuery(
                'SELECT m.message_id, m.username, m.message, m.ip_address, m.created_at, m.edited_at, m.reply_to, m.pinned_track,
                        r.username as reply_username, r.message as reply_message,
                        u.display_name, ru.display_name as reply_display_name
                 FROM chat_messages m
                 LEFT JOIN chat_messages r ON m.reply_to = r.message_id
                 LEFT JOIN users u ON m.user_id = u.userid
                 LEFT JOIN users ru ON r.user_id = ru.userid
                 WHERE m.is_deleted = false
                 ORDER BY m.created_at DESC
                 LIMIT :limit',
                ['limit' => $limit]
            );
            $rows = $result ? $result->fetchAll() : [];

            // Convert to the same format as Redis messages
            $messages = array_map(function($row) {
                $msg = [
                    'id' => $row['message_id'],
                    'username' => $row['username'],
                    'display_name' => $row['display_name'],
                    'message' => $row['message'],
                    'timestamp' => strtotime($row['created_at']),
                    'edited_at' => $row['edited_at'] ? (new \DateTimeImmutable($row['edited_at']))->format('c') : null,
                    'ip' => $row['ip_address'],
                    'reply_to' => $row['reply_to'],
                    'pinned_track' => $row['pinned_track'] ?? null,
                ];
                
                // Add reply data if exists
                if (!empty($row['reply_to']) && !empty($row['reply_username'])) {
                    $msg['reply_data'] = [
                        'username' => $row['reply_username'],
                        'display_name' => $row['reply_display_name'],
                        'message' => mb_substr($row['reply_message'], 0, 100),
                    ];
                }
                
                return $msg;
            }, $rows);
            
            // Repopulate Redis cache with messages from DB
            // DB returns DESC (newest first), we need to push them so newest is at position 0
            // Use lPush which adds to the head, so push in reverse order (oldest first)
            if (!empty($messages)) {
                // Rebuild the recent-history cache from the DB rows (newest-first).
                $this->messageHistory->replace($messages);
            }
            
            // Return in chronological order (oldest first) to match getHistory() behavior
            return array_reverse($messages);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log("Failed to load history from DB: " . $e->getMessage(), 'radiochatbox');
            return [];
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Get message history with offset for pagination (infinite scroll)
     * Returns older messages starting from a given offset
     */
    public function getHistoryWithOffset(int $limit = 50, int $offset = 0): array
    {
        try {
            $result = $this->db->preparedQuery(
                'SELECT m.message_id, m.username, m.message, m.ip_address, m.created_at, m.edited_at, m.reply_to, m.pinned_track,
                        r.username as reply_username, r.message as reply_message,
                        u.display_name, ru.display_name as reply_display_name
                 FROM chat_messages m
                 LEFT JOIN chat_messages r ON m.reply_to = r.message_id
                 LEFT JOIN users u ON m.user_id = u.userid
                 LEFT JOIN users ru ON r.user_id = ru.userid
                 WHERE m.is_deleted = false
                 ORDER BY m.created_at DESC
                 LIMIT :limit OFFSET :offset',
                ['limit' => min($limit, 100), 'offset' => $offset]
            );
            $rows = $result ? $result->fetchAll() : [];

            // Convert to the same format as Redis messages
            $messages = array_map(function($row) {
                $msg = [
                    'id' => $row['message_id'],
                    'username' => $row['username'],
                    'display_name' => $row['display_name'],
                    'message' => $row['message'],
                    'timestamp' => strtotime($row['created_at']),
                    'edited_at' => $row['edited_at'] ? (new \DateTimeImmutable($row['edited_at']))->format('c') : null,
                    'ip' => $row['ip_address'],
                    'reply_to' => $row['reply_to'],
                    'pinned_track' => $row['pinned_track'] ?? null,
                ];

                // Add reply data if exists
                if (!empty($row['reply_to']) && !empty($row['reply_username'])) {
                    $msg['reply_data'] = [
                        'username' => $row['reply_username'],
                        'display_name' => $row['reply_display_name'],
                        'message' => mb_substr($row['reply_message'], 0, 100),
                    ];
                }

                return $msg;
            }, $rows);

            // Return in chronological order (oldest first)
            return array_reverse($messages);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log("Failed to load paginated history: " . $e->getMessage(), 'radiochatbox');
            return [];
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Check if user can send a message (rate limiting)
     *
     * OPTIMIZED: Fetches both rate limit settings in single query instead of two
     */
    private function checkRateLimit(string $ipAddress): bool
    {
        // Get rate limit settings with caching
        $rateLimitMessages = 10; // default
        $rateLimitWindow = 60; // default

        try {
            // Rate-limit *settings* are a recomputable cache (the per-IP counters
            // below are state and stay on raw Redis).
            $cached = FlatCache::default()->get('settings:rate_limit');

            if (is_array($cached)) {
                $rateLimitMessages = $cached['messages'] ?? 10;
                $rateLimitWindow = $cached['window'] ?? 60;
            } else {
                // PERFORMANCE OPTIMIZATION: Fetch both settings in ONE query instead of two
                $result = $this->db->query(
                    "SELECT setting, value FROM settings
                     WHERE setting IN ('rate_limit_messages', 'rate_limit_window')"
                );
                $rows = $result ? $result->fetchAll() : [];
                $results = [];
                foreach ($rows as $r) {
                    $vals = array_values($r);
                    $results[$vals[0]] = $vals[1];
                }

                $rateLimitMessages = isset($results['rate_limit_messages'])
                    ? (int)$results['rate_limit_messages']
                    : 10;
                $rateLimitWindow = isset($results['rate_limit_window'])
                    ? (int)$results['rate_limit_window']
                    : 60;

                // Cache for 5 minutes
                FlatCache::default()->set('settings:rate_limit', [
                    'messages' => $rateLimitMessages,
                    'window' => $rateLimitWindow,
                ], 300);
            }
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            // Use defaults if unable to fetch from database
            \Pramnos\Logs\Logger::log("Failed to get rate limit settings: " . $e->getMessage(), 'radiochatbox');
        }
        // @codeCoverageIgnoreEnd

        $key = self::RATE_LIMIT_PREFIX . $ipAddress;

        if (FlatCache::default()->counter($key) >= $rateLimitMessages) {
            // Track repeated violations for auto-ban
            $this->trackViolation($ipAddress, 'rate_limit');
            return false;
        }

        // Atomic sliding-window counter (Redis INCRBY + window expiry).
        FlatCache::default()->increment($key, 1, $rateLimitWindow);

        return true;
    }
    
    /**
     * The chat-wide "slow mode" gap (seconds) an admin has configured, cached for
     * a minute. 0 (the default) means slow mode is off.
     */
    private function getSlowModeSeconds(): int
    {
        $cached = FlatCache::default()->get('settings:slow_mode');
        if ($cached !== null && $cached !== false) {
            return (int) $cached;
        }
        $seconds = 0;
        try {
            $result = $this->db->query("SELECT value FROM settings WHERE setting = 'slow_mode_seconds'");
            $val = $result ? $result->fetchColumn() : false;
            $seconds = ($val === false || $val === null) ? 0 : max(0, (int) $val);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Failed to read slow_mode_seconds: ' . $e->getMessage(), 'radiochatbox');
        }
        // @codeCoverageIgnoreEnd
        FlatCache::default()->set('settings:slow_mode', $seconds, 60);
        return $seconds;
    }

    /**
     * Enforce slow mode: reject a message when the same user posted less than
     * `slow_mode_seconds` ago, with a message stating how long to wait. Keyed per
     * user (lowercased), the marker self-expires after the window.
     *
     * @throws \RuntimeException when the user must still wait.
     */
    private function enforceSlowMode(string $username): void
    {
        $seconds = $this->getSlowModeSeconds();
        if ($seconds <= 0 || $username === '') {
            return;
        }
        $key = 'slowmode:' . mb_strtolower(trim($username));
        $last = FlatCache::default()->get($key);
        if ($last !== null && $last !== false) {
            $wait = $seconds - (time() - (int) $last);
            if ($wait > 0) {
                throw new \RuntimeException("Slow mode is on — wait {$wait}s before sending another message.");
            }
        }
        FlatCache::default()->set($key, time(), $seconds);
    }

    /**
     * Track violations and auto-ban repeat offenders
     */
    private function trackViolation(string $ipAddress, string $violationType): void
    {
        try {
            $key = "violations:{$violationType}:{$ipAddress}";
            // Atomic sliding-window counter; returns the new post-increment count.
            $violations = FlatCache::default()->increment($key, 1, 3600);

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
                FlatCache::default()->delete($key);
                
                \Pramnos\Logs\Logger::log("Auto-banned IP {$ipAddress} for {$violationType} violations (count: {$violations})", 'radiochatbox');
            } else {
                $remaining = $threshold - $violations;
                \Pramnos\Logs\Logger::log("Violation tracked for {$ipAddress}: {$violationType} (violations: {$violations}, {$remaining} more until auto-ban)", 'radiochatbox');
            }
        // @codeCoverageIgnoreStart
        } catch (\Exception $e) {
            \Pramnos\Logs\Logger::log("Failed to track violation: " . $e->getMessage(), 'radiochatbox');
        }
        // @codeCoverageIgnoreEnd
    }
    
    /**
     * Store message in PostgreSQL for permanent logging
     *
     * OPTIMIZED: Now receives user_id and display_name from messageData to avoid duplicate query
     */
    private function storeMessageInDB(array $messageData): void
    {
        try {
            // PERFORMANCE OPTIMIZATION: Use user_id and display_name from messageData
            // These were already fetched in postMessage() - no need to query again
            $userId = $messageData['user_id'] ?? null;
            $displayName = $messageData['display_name'] ?? null;

            $result = $this->db->preparedQuery(
                'INSERT INTO chat_messages (message_id, username, user_id, display_name, message, ip_address, created_at, reply_to, pinned_track)
                 VALUES (:message_id, :username, :user_id, :display_name, :message, :ip_address, :created_at, :reply_to, :pinned_track)',
                [
                    'message_id' => $messageData['id'],
                    'username' => $messageData['username'],
                    'user_id' => $userId,
                    'display_name' => $displayName,
                    'message' => $messageData['message'],
                    'ip_address' => $messageData['ip'],
                    'created_at' => date('Y-m-d H:i:s', $messageData['timestamp']),
                    'reply_to' => $messageData['reply_to'] ?? null,
                    'pinned_track' => $messageData['pinned_track'] ?? null,
                ]
            );

            if (!$result) {
                \Pramnos\Logs\Logger::log("Failed to store message in database - execute returned false. Errors: " . json_encode($this->db->getError()), 'radiochatbox');
            }
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            // Log error with full details but don't fail the request
            \Pramnos\Logs\Logger::log("Failed to store message in database (PDOException): " . $e->getMessage() . " | Code: " . $e->getCode(), 'radiochatbox');
            \Pramnos\Logs\Logger::log("Message ID: " . ($messageData['id'] ?? 'null'), 'radiochatbox');
        }
        // @codeCoverageIgnoreEnd
    }
    
    /**
     * Get reply message data for quoting
     *
     * OPTIMIZED: Uses Redis HASH for O(1) lookup instead of O(n) list scan
     */
    private function getReplyMessageData(string $messageId): ?array
    {
        try {
            // PERFORMANCE OPTIMIZATION: O(1) reply lookup via the message hash.
            $decoded = $this->messageHistory->getReply($messageId);
            if ($decoded) {
                return [
                    'username' => $decoded['username'],
                    'display_name' => $decoded['display_name'] ?? null,
                    'message' => mb_substr($decoded['message'], 0, 100), // Truncate to 100 chars for quote
                ];
            }

            // Fallback to database
            $result = $this->db->preparedQuery(
                'SELECT m.username, m.message, u.display_name
                 FROM chat_messages m
                 LEFT JOIN users u ON m.user_id = u.userid
                 WHERE m.message_id = :message_id AND m.is_deleted = false
                 LIMIT 1',
                ['message_id' => $messageId]
            );
            $row = ($result && $result->numRows > 0) ? $result->fields : null;

            if ($row) {
                $replyData = [
                    'username' => $row['username'],
                    'display_name' => $row['display_name'],
                    'message' => mb_substr($row['message'], 0, 100), // Truncate to 100 chars for quote
                ];

                // Cache for future lookups.
                $this->messageHistory->cacheReply($messageId, [
                    'username' => $row['username'],
                    'display_name' => $row['display_name'],
                    'message' => $row['message'],
                ]);

                return $replyData;
            }
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log("Failed to get reply message data: " . $e->getMessage(), 'radiochatbox');
        // @codeCoverageIgnoreStart
        } catch (\Exception $e) {
        // @codeCoverageIgnoreEnd
            \Pramnos\Logs\Logger::log("Redis error in getReplyMessageData: " . $e->getMessage(), 'radiochatbox');
        }
        // @codeCoverageIgnoreEnd

        return null;
    }
    
    /**
     * Get user data (user_id and display_name) for message storage
     * OPTIMIZED: Fetches both id and display_name in single query with caching
     *
     * @param string $username
     * @return array ['user_id' => int|null, 'display_name' => string|null]
     */
    private function getUserDataForMessage(string $username): array
    {
        try {
            // Check Redis cache first (cache both fields together)
            $cacheKey = 'user_data:' . $username;
            $cached = FlatCache::default()->get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }

            // Fetch from database (single query for both fields)
            $result = $this->db->preparedQuery(
                'SELECT userid, display_name FROM users WHERE username = :username AND is_active = true LIMIT 1',
                ['username' => $username]
            );
            $row = ($result && $result->numRows > 0) ? $result->fields : null;

            $userData = [
                'user_id' => $row ? $row['userid'] : null,
                'display_name' => $row ? $row['display_name'] : null,
            ];

            // Cache result for 5 minutes
            FlatCache::default()->set($cacheKey, $userData, 300);

            return $userData;
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log("Failed to get user data for username: " . $e->getMessage(), 'radiochatbox');
            return ['user_id' => null, 'display_name' => null];
        }
    }

    /**
     * Get display name for a username (from authenticated users table)
     * Returns null if user is not authenticated or has no display_name
     */
    private function getDisplayNameForUsername(string $username): ?string
    {
        // Use the optimized getUserDataForMessage method
        $userData = $this->getUserDataForMessage($username);
        return $userData['display_name'];
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
            $result = $this->db->preparedQuery(
                'SELECT s.user_id, u.username 
                 FROM presence_sessions s
                 INNER JOIN users u ON s.user_id = u.userid
                 WHERE s.session_id = :session_id AND u.username = :username',
                [
                    'session_id' => $sessionId,
                    'username' => $username
                ]
            );

            return ($result && $result->numRows > 0);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log("Failed to check session authentication: " . $e->getMessage(), 'radiochatbox');
            return false;
        }
        // @codeCoverageIgnoreEnd
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
        $result = $this->db->preparedQuery(
            'SELECT userid, username FROM users WHERE username = :username',
            ['username' => $nickname]
        );
        $registeredUser = ($result && $result->numRows > 0) ? $result->fields : null;

        // If it's a registered username, only allow if the session is authenticated as that user
        if ($registeredUser !== null) {
            // Check if this session is authenticated as this user
            if (empty($sessionId)) {
                return false; // No session provided, cannot authenticate
            }
            
            return $this->isSessionAuthenticatedAsUser($nickname, $sessionId);
        }
        
        // Check if this is a fake user nickname (guests cannot use fake user nicknames)
        $result = $this->db->preparedQuery(
            'SELECT id, nickname FROM fake_users WHERE nickname = :nickname',
            ['nickname' => $nickname]
        );
        $fakeUser = ($result && $result->numRows > 0) ? $result->fields : null;

        // If it's a fake user nickname, deny it for guests
        if ($fakeUser !== null) {
            return false;
        }

        // For non-registered nicknames, check if taken by another session
        $result = $this->db->preparedQuery(
            'SELECT session_id FROM presence_sessions WHERE LOWER(username) = LOWER(:username)',
            ['username' => $nickname]
        );
        $existingSession = $result ? $result->fetchColumn() : false;
        
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
        if ((new KickRegistry())->isKicked($sessionId)) {
            \Pramnos\Logs\Logger::log("Registration blocked: session {$sessionId} is banned (kicked user)", 'radiochatbox');
            return false;
        }
        
        // Check bans
        if ($this->isIPBanned($ipAddress) || $this->isNicknameBanned($username)) {
            return false;
        }
        
        // Check if username matches a registered user account
        $result = $this->db->preparedQuery(
            'SELECT userid, username FROM users WHERE username = :username',
            ['username' => $username]
        );
        $registeredUser = ($result && $result->numRows > 0) ? $result->fields : null;

        if ($registeredUser !== null) {
            // This is a registered username - verify session is authenticated as this user
            if (!$this->isSessionAuthenticatedAsUser($username, $sessionId)) {
                \Pramnos\Logs\Logger::log("Registration blocked: username '{$username}' is a registered account and session {$sessionId} is not authenticated as this user", 'radiochatbox');
                return false;
            }
            // Authenticated users are allowed to have multiple sessions (different devices)
            // Continue to registration below
        } else {
            // Check if username conflicts with any user's display name
            $result = $this->db->preparedQuery(
                'SELECT userid, username FROM users WHERE display_name = :username',
                ['username' => $username]
            );
            $userWithDisplayName = ($result && $result->numRows > 0) ? $result->fields : null;

            if ($userWithDisplayName !== null) {
                \Pramnos\Logs\Logger::log("Registration blocked: username '{$username}' conflicts with a registered user's display name", 'radiochatbox');
                return false;
            }
            
            // Check if username matches a fake user nickname
            $result = $this->db->preparedQuery(
                'SELECT id, nickname FROM fake_users WHERE nickname = :username',
                ['username' => $username]
            );
            $fakeUser = ($result && $result->numRows > 0) ? $result->fields : null;

            if ($fakeUser !== null) {
                // Guests cannot use fake user nicknames
                \Pramnos\Logs\Logger::log("Registration blocked: username '{$username}' is a fake user nickname", 'radiochatbox');
                return false;
            }
            // For non-registered usernames, enforce one session per username
            $result = $this->db->preparedQuery(
                'SELECT session_id FROM presence_sessions WHERE username = :username',
                ['username' => $username]
            );
            $existingUser = ($result && $result->numRows > 0) ? $result->fields : null;

            if ($existingUser && $existingUser['session_id'] !== $sessionId) {
                // Username is taken by another active session
                \Pramnos\Logs\Logger::log("Registration blocked: username '{$username}' is already taken by another user", 'radiochatbox');
                return false;
            }
        }
        
        try {
            // Insert or update active session
            // Note: ON CONFLICT now uses (username, session_id) to allow multiple sessions for authenticated users
            $this->db->preparedQuery(
                'INSERT INTO presence_sessions (username, session_id, ip_address, user_id, last_heartbeat, joined_at)
                 VALUES (:username, :session_id, :ip_address, :user_id, NOW(), NOW())
                 ON CONFLICT (username, session_id) DO UPDATE SET
                     ip_address = :ip_address,
                     user_id = :user_id,
                     last_heartbeat = NOW()',
                [
                    'username' => $username,
                    'session_id' => $sessionId,
                    'ip_address' => $ipAddress,
                    'user_id' => $registeredUser !== null ? $registeredUser['userid'] : null,
                ]
            );

            // Track IP address in user_activity even if no message is sent yet
            $this->db->preparedQuery(
                'INSERT INTO user_activity (username, ip_address, first_seen, last_seen, message_count, user_id)
                 VALUES (:username, :ip_address, NOW(), NOW(), 0, :user_id)
                 ON CONFLICT (username) DO UPDATE SET
                     ip_address = :ip_address,
                     last_seen = NOW()',
                [
                    'username' => $username,
                    'ip_address' => $ipAddress,
                    'user_id' => $registeredUser !== null ? $registeredUser['userid'] : null,
                ]
            );

            // Store user profile if any profile data provided
            if ($age !== null || $location !== null || $sex !== null) {
                $this->db->preparedQuery(
                    'INSERT INTO user_profiles (username, session_id, age, location, sex)
                     VALUES (:username, :session_id, :age, :location, :sex)
                     ON CONFLICT (username, session_id) DO UPDATE SET
                         age = :age,
                         location = :location,
                         sex = :sex',
                    [
                        'username' => $username,
                        'session_id' => $sessionId,
                        'age' => $age,
                        'location' => $location,
                        'sex' => $sex,
                    ]
                );
            }
            
            // Publish user update to SSE subscribers
            $this->publishUserUpdate();
            
            return true;
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log("Failed to register user: " . $e->getMessage(), 'radiochatbox');
            return false;
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Logout user and remove their session
     */
    public function logoutUser(string $sessionId): bool
    {
        try {
            // Delete session from database
            $result = $this->db->preparedQuery(
                'DELETE FROM presence_sessions WHERE session_id = :session_id',
                ['session_id' => $sessionId]
            );

            // Publish user update after logout
            $this->publishUserUpdate();

            return $result !== false;
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log("Failed to logout user: " . $e->getMessage(), 'radiochatbox');
            return false;
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Update user heartbeat
     *
     * OPTIMIZED: Rate-limits user list updates to reduce SSE spam
     */
    public function updateHeartbeat(string $username, string $sessionId): bool
    {
        try {
            // Clean up inactive sessions first (this might change the user list)
            $this->cleanupInactiveSessions();

            $result = $this->db->preparedQuery(
                'UPDATE presence_sessions
                 SET last_heartbeat = NOW()
                 WHERE username = :username AND session_id = :session_id',
                [
                    'username' => $username,
                    'session_id' => $sessionId,
                ]
            );

            // PERFORMANCE OPTIMIZATION: Only publish user updates every 10 seconds
            // Heartbeats happen frequently (every 10-30s per user), no need to spam SSE
            $this->publishUserUpdateThrottled();

            return $result !== false;
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log("Failed to update heartbeat: " . $e->getMessage(), 'radiochatbox');
            return false;
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Publish user update with throttling (max once per 10 seconds)
     * Reduces SSE spam from frequent heartbeat updates
     */
    private function publishUserUpdateThrottled(): void
    {
        try {
            $rateLimitKey = 'user_update:last_publish';
            $lastPublish = FlatCache::default()->get($rateLimitKey);

            if ($lastPublish !== null) {
                // Published recently, skip it
                return;
            }

            // Set rate limit lock for 10 seconds
            FlatCache::default()->set($rateLimitKey, time(), 10);

            // Actually publish the update
            $this->publishUserUpdate();
        // @codeCoverageIgnoreStart
        } catch (\Exception $e) {
            \Pramnos\Logs\Logger::log("Failed to throttle user update: " . $e->getMessage(), 'radiochatbox');
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Get session information for validation
     */
    public function getSessionInfo(string $username, string $sessionId): ?array
    {
        try {
            $stmt = $this->db->preparedQuery(
                'SELECT s.username, s.session_id, s.user_id, s.ip_address, s.last_heartbeat, u.usertype as user_usertype
                 FROM presence_sessions s
                 LEFT JOIN users u ON s.user_id = u.userid
                 WHERE s.username = :username AND s.session_id = :session_id',
                [
                    'username' => $username,
                    'session_id' => $sessionId,
                ]
            );

            $result = ($stmt && $stmt->numRows > 0) ? $stmt->fields : null;
            if ($result !== null) {
                // Preserve the JSON contract: expose a role LABEL (was u.role),
                // now derived from the usertype ladder. Guests (no user row) → null.
                $result['user_role'] = isset($result['user_usertype']) && $result['user_usertype'] !== null
                    ? Authz::labelForUsertype((int) $result['user_usertype'])
                    : null;
            }
            return $result ?: null;
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log("Failed to get session info: " . $e->getMessage(), 'radiochatbox');
            return null;
        }
        // @codeCoverageIgnoreEnd
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
            $stmt = $this->db->query(
                'SELECT DISTINCT ON (a.username)
                    a.username, 
                    a.joined_at, 
                    a.last_heartbeat,
                    p.age,
                    p.location,
                    p.sex,
                    u.display_name
                 FROM presence_sessions a
                 LEFT JOIN user_profiles p ON a.username = p.username
                 LEFT JOIN users u ON a.user_id = u.userid
                 ORDER BY a.username, a.joined_at ASC'
            );

            return $stmt->fetchAll();
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log("Failed to get active users: " . $e->getMessage(), 'radiochatbox');
            return [];
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Get count of active users (real users only, not fake)
     */
    public function getActiveUserCount(): int
    {
        $this->cleanupInactiveSessions();
        
        try {
            $stmt = $this->db->query('SELECT COUNT(*) FROM presence_sessions');
            return (int)$stmt->fetchColumn();
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log("Failed to get active user count: " . $e->getMessage(), 'radiochatbox');
            return 0;
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Get all users including real and fake users
     * This is what should be used for the active users list display
     *
     * OPTIMIZED: Caches combined user list for 30 seconds to reduce DB load
     */
    public function getAllUsers(): array
    {
        // PERFORMANCE OPTIMIZATION: Cache combined user list
        $cacheKey = 'chat:all_users';
        $cached = FlatCache::default()->get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

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
        $allUsers = array_merge($realUsers, $formattedFakeUsers);

        // Cache for 30 seconds (short TTL because user list changes frequently)
        FlatCache::default()->set($cacheKey, $allUsers, 30);

        return $allUsers;
    }

    /**
     * Invalidate the combined user list cache
     * Call this when users join, leave, or fake users are balanced
     */
    private function invalidateUserListCache(): void
    {
        try {
            FlatCache::default()->delete('chat:all_users');
        // @codeCoverageIgnoreStart
        } catch (\Exception $e) {
            \Pramnos\Logs\Logger::log("Failed to invalidate user list cache: " . $e->getMessage(), 'radiochatbox');
        }
        // @codeCoverageIgnoreEnd
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
            $this->db->preparedQuery(
                'DELETE FROM presence_sessions
                 WHERE username = :username AND session_id = :session_id',
                [
                    'username' => $username,
                    'session_id' => $sessionId,
                ]
            );

            // Publish user update to SSE subscribers
            $this->publishUserUpdate();
            
            return true;
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log("Failed to remove user: " . $e->getMessage(), 'radiochatbox');
            return false;
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Clean up inactive sessions (not seen in 5 minutes)
     *
     * OPTIMIZED: Rate-limited to run max once per 30 seconds to prevent table lock storms
     */
    private function cleanupInactiveSessions(): void
    {
        try {
            // PERFORMANCE OPTIMIZATION: Rate limit cleanup to max once per 30 seconds
            $rateLimitKey = 'cleanup:last_run';
            $lastRun = FlatCache::default()->get($rateLimitKey);

            if ($lastRun !== null) {
                // Cleanup was run recently, skip it
                return;
            }

            // Set rate limit lock for 30 seconds
            FlatCache::default()->set($rateLimitKey, time(), 30);

            // Run the cleanup
            $this->db->statement("SELECT cleanup_inactive_sessions()");
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log("Failed to cleanup inactive sessions: " . $e->getMessage(), 'radiochatbox');
        // @codeCoverageIgnoreStart
        } catch (\Exception $e) {
        // @codeCoverageIgnoreEnd
            \Pramnos\Logs\Logger::log("Failed to check cleanup rate limit: " . $e->getMessage(), 'radiochatbox');
        }
        // @codeCoverageIgnoreEnd
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
            $cached = FlatCache::default()->get($cacheKey);
            
            if ($cached !== null) {
                $bannedIPs = $cached;
            } else {
                // Cache miss - fetch from database
                $result = $this->db->query(
                    'SELECT ip_address FROM banned_ips 
                     WHERE banned_until IS NULL OR banned_until > NOW()'
                );
                $rows = $result ? $result->fetchAll() : [];
                $bannedIPs = array_map(fn($r) => reset($r), $rows);
                
                // Cache for 5 minutes
                FlatCache::default()->set($cacheKey, $bannedIPs, 300);
            }
            
            return in_array($ipAddress, $bannedIPs, true);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log("Failed to check IP ban: " . $e->getMessage(), 'radiochatbox');
            return false;
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Check if nickname is banned
     * Uses Redis cache to avoid hitting PostgreSQL on every message
     */
    /**
     * Whether a user is barred from communicating at all — the single source of
     * truth enforced on every send path (public, private) and by the bot delivery
     * guard. Covers all three moderation actions: a kicked session, a banned IP,
     * and a banned nickname.
     *
     * @return string|null A human-readable error to show the sender, or null when
     *                     they are allowed to communicate.
     */
    public function communicationBlockReason(string $username, string $ipAddress, string $sessionId = ''): ?string
    {
        // Kicked: a short session ban set by the admin "kick" action.
        if ($this->isSessionKicked($sessionId)) {
            return 'You have been kicked and cannot send messages right now.';
        }
        // IP ban.
        if ($this->isIPBanned($ipAddress)) {
            return 'Your IP address has been banned from the chat.';
        }
        // Nickname ban.
        if ($this->isNicknameBanned($username)) {
            return 'This nickname is not allowed.';
        }
        // Timeout (temporary mute): the user stays connected but cannot post.
        $timeout = $this->getTimeoutRemaining($username);
        if ($timeout > 0) {
            return "You are timed out — wait {$timeout}s before sending messages.";
        }
        return null;
    }

    /** Redis key for a user's timeout (temporary mute), keyed by lowercased name. */
    private function timeoutKey(string $username): string
    {
        return 'timeout:' . mb_strtolower(trim($username));
    }

    /**
     * Temporarily mute a user for $seconds: they stay connected but every send
     * path is blocked (via communicationBlockReason) until it expires. Unlike a
     * kick, no disconnect. Capped at 24h.
     */
    public function timeoutUser(string $username, int $seconds): void
    {
        if (trim($username) === '') {
            return;
        }
        $seconds = max(1, min($seconds, 86400));
        FlatCache::default()->set($this->timeoutKey($username), time() + $seconds, $seconds);
    }

    /** Lift a user's timeout early. */
    public function clearUserTimeout(string $username): void
    {
        if (trim($username) === '') {
            return;
        }
        FlatCache::default()->delete($this->timeoutKey($username));
    }

    /** Seconds remaining on a user's timeout, or 0 when they are not timed out. */
    public function getTimeoutRemaining(string $username): int
    {
        if (trim($username) === '') {
            return 0;
        }
        $until = FlatCache::default()->get($this->timeoutKey($username));
        if ($until === null || $until === false) {
            return 0;
        }
        return max(0, (int) $until - time());
    }

    /**
     * Whether a session was kicked (the admin "kick" action stores an unprefixed
     * `banned_session:<id>` key in Redis with a TTL — see kick-user.php).
     */
    public function isSessionKicked(string $sessionId): bool
    {
        if ($sessionId === '') {
            return false;
        }
        try {
            return (new KickRegistry())->isKicked($sessionId);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            error_log('Failed to check kicked session: ' . $e->getMessage());
            return false;
        }
        // @codeCoverageIgnoreEnd
    }

    private function isNicknameBanned(string $nickname): bool
    {
        try {
            // Try cache first
            $cacheKey = 'banned_nicknames';
            $cached = FlatCache::default()->get($cacheKey);
            
            if ($cached !== null) {
                $bannedNicknames = $cached;
            } else {
                // Cache miss - fetch from database
                $result = $this->db->query('SELECT LOWER(nickname) FROM banned_nicknames');
                $rows = $result ? $result->fetchAll() : [];
                $bannedNicknames = array_map(fn($r) => reset($r), $rows);
                
                // Cache for 5 minutes
                FlatCache::default()->set($cacheKey, $bannedNicknames, 300);
            }
            
            return in_array(strtolower($nickname), $bannedNicknames, true);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log("Failed to check nickname ban: " . $e->getMessage(), 'radiochatbox');
            return false;
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Get all messages from database with pagination
     */
    /**
     * Get all messages for admin panel (optionally including private messages for root admins)
     */
    public function getAllMessages(int $limit = 100, int $offset = 0, bool $includePrivate = false, string $type = 'all'): array
    {
        try {
            // Determine which messages to fetch
            if (!$includePrivate || $type === 'public') {
                // Only public messages (non-deleted)
                $sql =
                    'SELECT message_id, username, message, ip_address, created_at, is_deleted, \'public\' as message_type, NULL as from_username, NULL as to_username, NULL::varchar as attachment_id
                     FROM chat_messages
                     WHERE is_deleted = FALSE
                     ORDER BY created_at DESC
                     LIMIT :limit OFFSET :offset';
                $params = ['limit' => $limit, 'offset' => $offset];
            } elseif ($type === 'private') {
                // Only private messages
                $sql =
                    'SELECT
                        id::text as message_id,
                        from_username as username,
                        message,
                        \'\' as ip_address,
                        created_at,
                        FALSE as is_deleted,
                        \'private\' as message_type,
                        from_username,
                        to_username,
                        attachment_id
                     FROM private_messages
                     ORDER BY created_at DESC
                     LIMIT :limit OFFSET :offset';
                $params = ['limit' => $limit, 'offset' => $offset];
            } else {
                // Both public and private messages
                $sql =
                    '(SELECT
                        message_id,
                        username,
                        message,
                        ip_address,
                        created_at,
                        is_deleted,
                        \'public\' as message_type,
                        NULL as from_username,
                        NULL as to_username,
                        NULL::varchar as attachment_id
                     FROM chat_messages
                     WHERE is_deleted = FALSE)
                    UNION ALL
                    (SELECT
                        id::text as message_id,
                        from_username as username,
                        message,
                        \'\' as ip_address,
                        created_at,
                        FALSE as is_deleted,
                        \'private\' as message_type,
                        from_username,
                        to_username,
                        attachment_id
                     FROM private_messages)
                    ORDER BY created_at DESC
                    LIMIT :limit OFFSET :offset';
                $params = ['limit' => $limit, 'offset' => $offset];
            }

            $result = $this->db->preparedQuery($sql, $params);
            $messages = $result ? $result->fetchAll() : [];

            return $this->attachPhotoData($messages);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log("Failed to get all messages: " . $e->getMessage(), 'radiochatbox');
            return [];
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Attach photo data to messages that carry an attachment_id, so the admin
     * message-history view can render DM photos (bug: they were invisible there).
     * Batches one query for all referenced attachments; each message gets an
     * `attachment` object (or null) and its `attachment_id` is dropped.
     *
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, array<string, mixed>>
     */
    private function attachPhotoData(array $messages): array
    {
        $ids = [];
        foreach ($messages as $m) {
            $aid = $m['attachment_id'] ?? null;
            if (is_string($aid) && $aid !== '') {
                $ids[] = $aid;
            }
        }
        $ids = array_values(array_unique($ids));

        $byId = [];
        if ($ids !== []) {
            try {
                $place = implode(',', array_fill(0, count($ids), '?'));
                $rows = $this->db->preparedQuery(
                    "SELECT attachment_id, filename, file_path, file_size, mime_type, width, height
                     FROM attachments
                     WHERE attachment_id IN ($place) AND is_deleted = FALSE",
                    $ids
                );
                foreach (($rows ? $rows->fetchAll() : []) as $row) {
                    $byId[$row['attachment_id']] = [
                        'attachment_id' => $row['attachment_id'],
                        'filename'      => $row['filename'],
                        'file_path'     => $row['file_path'],
                        'file_size'     => $row['file_size'],
                        'mime_type'     => $row['mime_type'],
                        'width'         => $row['width'],
                        'height'        => $row['height'],
                    ];
                }
            } catch (\Throwable $e) {
                \Pramnos\Logs\Logger::log('attachPhotoData failed: ' . $e->getMessage(), 'radiochatbox');
            }
        }

        foreach ($messages as &$m) {
            $aid = $m['attachment_id'] ?? null;
            $m['attachment'] = (is_string($aid) && isset($byId[$aid])) ? $byId[$aid] : null;
            unset($m['attachment_id']);
        }
        unset($m);

        return $messages;
    }

    /**
     * Get total count of messages (optionally including private messages for root admins)
     */
    public function getTotalMessagesCount(bool $includePrivate = false, string $type = 'all'): int
    {
        try {
            if (!$includePrivate || $type === 'public') {
                $stmt = $this->db->query('SELECT COUNT(*) FROM chat_messages WHERE is_deleted = FALSE');
            } elseif ($type === 'private') {
                $stmt = $this->db->query('SELECT COUNT(*) FROM private_messages');
            } else {
                $stmt = $this->db->query(
                    'SELECT (SELECT COUNT(*) FROM chat_messages WHERE is_deleted = FALSE) + (SELECT COUNT(*) FROM private_messages) AS total'
                );
            }
            return (int)$stmt->fetchColumn();
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log("Failed to get messages count: " . $e->getMessage(), 'radiochatbox');
            return 0;
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Get total count of active users (for admin pagination)
     */
    public function getTotalActiveUsersCount(): int
    {
        try {
            $stmt = $this->db->query('SELECT COUNT(*) FROM presence_sessions');
            return (int)$stmt->fetchColumn();
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log("Failed to get active users count: " . $e->getMessage(), 'radiochatbox');
            return 0;
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Ban an IP address
     */
    public function banIP(string $ipAddress, string $reason = '', string $bannedBy = 'admin', ?int $durationDays = null): bool
    {
        try {
            $bannedUntil = $durationDays ? date('Y-m-d H:i:s', strtotime("+{$durationDays} days")) : null;
            
            $result = $this->db->preparedQuery(
                'INSERT INTO banned_ips (ip_address, reason, banned_by, banned_until)
                 VALUES (:ip, :reason, :banned_by, :banned_until)
                 ON CONFLICT (ip_address) DO UPDATE SET
                     reason = :reason,
                     banned_until = :banned_until,
                     banned_by = :banned_by',
                [
                    'ip' => $ipAddress,
                    'reason' => $reason,
                    'banned_by' => $bannedBy,
                    'banned_until' => $bannedUntil,
                ]
            );

            // Invalidate Redis cache
            if ($result) {
                FlatCache::default()->delete('banned_ips');
            }

            return $result !== false;
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log("Failed to ban IP: " . $e->getMessage(), 'radiochatbox');
            return false;
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Unban an IP address
     */
    public function unbanIP(string $ipAddress): bool
    {
        try {
            $result = $this->db->preparedQuery('DELETE FROM banned_ips WHERE ip_address = :ip', ['ip' => $ipAddress]);

            // Invalidate Redis cache
            if ($result) {
                FlatCache::default()->delete('banned_ips');
            }

            return $result !== false;
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log("Failed to unban IP: " . $e->getMessage(), 'radiochatbox');
            return false;
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Ban a nickname
     */
    public function banNickname(string $nickname, string $reason = '', string $bannedBy = 'admin'): bool
    {
        try {
            $result = $this->db->preparedQuery(
                'INSERT INTO banned_nicknames (nickname, reason, banned_by)
                 VALUES (:nickname, :reason, :banned_by)
                 ON CONFLICT (nickname) DO UPDATE SET
                     reason = :reason,
                     banned_by = :banned_by',
                [
                    'nickname' => $nickname,
                    'reason' => $reason,
                    'banned_by' => $bannedBy,
                ]
            );

            // Remove from active sessions if currently online
            $this->db->preparedQuery('DELETE FROM presence_sessions WHERE LOWER(username) = LOWER(:nickname)', ['nickname' => $nickname]);

            // Invalidate Redis cache
            if ($result) {
                FlatCache::default()->delete('banned_nicknames');
            }

            return $result !== false;
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log("Failed to ban nickname: " . $e->getMessage(), 'radiochatbox');
            return false;
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Unban a nickname
     */
    public function unbanNickname(string $nickname): bool
    {
        try {
            $result = $this->db->preparedQuery('DELETE FROM banned_nicknames WHERE LOWER(nickname) = LOWER(:nickname)', ['nickname' => $nickname]);

            // Invalidate Redis cache
            if ($result) {
                FlatCache::default()->delete('banned_nicknames');
            }

            return $result !== false;
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log("Failed to unban nickname: " . $e->getMessage(), 'radiochatbox');
            return false;
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Get all banned IPs
     */
    public function getBannedIPs(): array
    {
        try {
            $stmt = $this->db->query(
                'SELECT ip_address, reason, banned_at, banned_until, banned_by
                 FROM banned_ips 
                 ORDER BY banned_at DESC'
            );
            return $stmt->fetchAll();
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log("Failed to get banned IPs: " . $e->getMessage(), 'radiochatbox');
            return [];
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Get all banned nicknames
     */
    public function getBannedNicknames(): array
    {
        try {
            $stmt = $this->db->query(
                'SELECT nickname, reason, banned_at, banned_by
                 FROM banned_nicknames 
                 ORDER BY banned_at DESC'
            );
            return $stmt->fetchAll();
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log("Failed to get banned nicknames: " . $e->getMessage(), 'radiochatbox');
            return [];
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Publish active user update to SSE subscribers
     */
    private function publishUserUpdate(): void
    {
        try {
            $this->cleanupInactiveSessions();

            // PERFORMANCE OPTIMIZATION: Invalidate cache before fetching fresh data
            $this->invalidateUserListCache();

            // Use getAllUsers() to include fake users
            $users = $this->getAllUsers();
            $count = count($users);

            BroadcastingManager::instance()->broadcast(self::USER_UPDATE_CHANNEL, 'user_list', [
                'count' => $count,
                'users' => $users,
            ]);
        // @codeCoverageIgnoreStart
        } catch (\Exception $e) {
            \Pramnos\Logs\Logger::log("Failed to publish user update: " . $e->getMessage(), 'radiochatbox');
        }
        // @codeCoverageIgnoreEnd
    }
    
    /**
     * Get a setting value from database
     */
    public function getSetting(string $key, $default = null)
    {
        try {
            $queryResult = $this->db->preparedQuery("SELECT value FROM settings WHERE setting = ?", [$key]);
            $result = ($queryResult && $queryResult->numRows > 0) ? $queryResult->fields : null;

            return $result ? $result['value'] : $default;
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log("Failed to get setting: " . $e->getMessage(), 'radiochatbox');
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
                $result = $this->db->query("SELECT setting, value FROM settings");
            } else {
                $placeholders = str_repeat('?,', count($keys) - 1) . '?';
                $result = $this->db->preparedQuery("SELECT setting, value FROM settings WHERE setting IN ($placeholders)", $keys);
            }

            $settings = [];
            foreach (($result ? $result->fetchAll() : []) as $row) {
                $settings[$row['setting']] = $row['value'];
            }

            return $settings;
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log("Failed to get settings: " . $e->getMessage(), 'radiochatbox');
            return [];
        }
        // @codeCoverageIgnoreEnd
    }
}
