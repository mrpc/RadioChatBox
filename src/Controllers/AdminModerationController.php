<?php

namespace RadioChatBox\Controllers;

use InvalidArgumentException;
use PDO;
use Pramnos\Http\Request;
use Pramnos\Http\Response;
use Pramnos\Routing\Attributes\Route;
use RadioChatBox\ChatService;
use RadioChatBox\Database;
use RadioChatBox\Middleware\AdminAuthMiddleware;

/**
 * Admin moderation endpoints migrated from public/api/admin/*.php.
 *
 * Every route carries AdminAuthMiddleware, which returns 401
 * {"error":"Unauthorized"} for unauthenticated requests before the action runs.
 * This replaces the legacy AdminAuth::verify()/authenticate() + unauthorized()
 * guard at the top of each file (authenticate() is merely an alias for verify(),
 * and none of these files performed any extra hasPermission()/getCurrentUser()
 * checks). Each action otherwise preserves the legacy request inputs, service
 * calls, JSON response keys, status codes and error mapping exactly. Because the
 * router enforces the declared methods, the legacy hand-coded 405 branches are
 * dropped.
 */
final class AdminModerationController
{
    /**
     * Replaces public/api/admin/ban-ip.php.
     *
     * GET    -> 200 {success:true, banned_ips:[...]}
     * POST   -> ban {ip_address, reason?, duration_days?} => 200 {success:bool, message}
     * DELETE -> unban {ip_address} => 200 {success:bool, message}
     * Missing ip_address -> 400 {error:'IP address is required'}; any other
     * failure -> 500 {error:'Internal server error'} (also error_log'd).
     */
    #[Route('/api/admin/ban-ip', methods: ['GET', 'POST', 'DELETE'], name: 'admin.ban-ip', middleware: [AdminAuthMiddleware::class])]
    public function banIp(): Response
    {
        try {
            $chatService = new ChatService();

            if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                $bannedIPs = $chatService->getBannedIPs();

                return Response::json([
                    'success'    => true,
                    'banned_ips' => $bannedIPs,
                ]);
            }

            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $input        = $_POST;
                $ipAddress    = $input['ip_address'] ?? '';
                $reason       = $input['reason'] ?? '';
                $durationDays = isset($input['duration_days']) ? (int) $input['duration_days'] : null;

                if (empty($ipAddress)) {
                    throw new InvalidArgumentException('IP address is required');
                }

                $success = $chatService->banIP($ipAddress, $reason, 'admin', $durationDays);

                return Response::json([
                    'success' => $success,
                    'message' => $success ? 'IP banned successfully' : 'Failed to ban IP',
                ]);
            }

            // DELETE — unban an IP.
            $input     = $_POST;
            $ipAddress = $input['ip_address'] ?? '';

            if (empty($ipAddress)) {
                throw new InvalidArgumentException('IP address is required');
            }

            $success = $chatService->unbanIP($ipAddress);

            return Response::json([
                'success' => $success,
                'message' => $success ? 'IP unbanned successfully' : 'Failed to unban IP',
            ]);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            \RadioChatBox\Log::write($e->getMessage());
            return Response::json(['error' => 'Internal server error'], 500);
        }
    }

    /**
     * Replaces public/api/admin/ban-nickname.php.
     *
     * GET    -> 200 {success:true, banned_nicknames:[...]}
     * POST   -> ban {nickname, reason?} => 200 {success:bool, message}
     * DELETE -> unban {nickname} => 200 {success:bool, message}
     * Missing nickname -> 400 {error:'Nickname is required'}; any other failure
     * -> 500 {error:'Internal server error'} (also error_log'd).
     */
    #[Route('/api/admin/ban-nickname', methods: ['GET', 'POST', 'DELETE'], name: 'admin.ban-nickname', middleware: [AdminAuthMiddleware::class])]
    public function banNickname(): Response
    {
        try {
            $chatService = new ChatService();

            if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                $bannedNicknames = $chatService->getBannedNicknames();

                return Response::json([
                    'success'          => true,
                    'banned_nicknames' => $bannedNicknames,
                ]);
            }

            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $input    = $_POST;
                $nickname = $input['nickname'] ?? '';
                $reason   = $input['reason'] ?? '';

                if (empty($nickname)) {
                    throw new InvalidArgumentException('Nickname is required');
                }

                $success = $chatService->banNickname($nickname, $reason, 'admin');

                return Response::json([
                    'success' => $success,
                    'message' => $success ? 'Nickname banned successfully' : 'Failed to ban nickname',
                ]);
            }

            // DELETE — unban a nickname.
            $input    = $_POST;
            $nickname = $input['nickname'] ?? '';

            if (empty($nickname)) {
                throw new InvalidArgumentException('Nickname is required');
            }

            $success = $chatService->unbanNickname($nickname);

            return Response::json([
                'success' => $success,
                'message' => $success ? 'Nickname unbanned successfully' : 'Failed to unban nickname',
            ]);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            \RadioChatBox\Log::write($e->getMessage());
            return Response::json(['error' => 'Internal server error'], 500);
        }
    }

    /**
     * Replaces public/api/admin/kick-user.php.
     *
     * POST {username}: bans the user's session id in Redis for 1 hour, deletes
     * their sessions row, evicts them from the active-users hash and publishes a
     * user_kicked event. Success -> 200 {success:true, message}. Missing username
     * -> 400 {error:'Username required'}; unknown user -> 404
     * {error:'User not found'}; failed delete -> 500 {error:'Failed to kick
     * user'}; thrown error -> 500 {error:'Server error: <msg>'}.
     */
    #[Route('/api/admin/kick-user', methods: 'POST', name: 'admin.kick-user', middleware: [AdminAuthMiddleware::class])]
    public function kickUser(): Response
    {
        $db    = Database::getPDO();
        $redis = Database::getRedis();

        try {
            $data = $_POST;

            if (!$data || !isset($data['username'])) {
                return Response::json(['error' => 'Username required'], 400);
            }

            $username = $data['username'];

            // Get user's IP and session before removing them.
            $stmt = $db->prepare('SELECT ip_address, session_id FROM sessions WHERE username = ?');
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                return Response::json(['error' => 'User not found'], 404);
            }

            // Ban the user's session ID temporarily (1 hour) to prevent immediate rejoin.
            $sessionBanKey = 'banned_session:' . $user['session_id'];
            $redis->setex($sessionBanKey, 3600, json_encode([
                'username'  => $username,
                'reason'    => 'Kicked by admin',
                'kicked_at' => time(),
            ]));

            // Remove from database.
            $stmt   = $db->prepare('DELETE FROM sessions WHERE username = ?');
            $result = $stmt->execute([$username]);

            if ($result) {
                // Remove from Redis cache.
                $redis->hDel('chat:active_users', $username);

                // Notify clients that user was kicked.
                $notification = json_encode([
                    'type'      => 'user_kicked',
                    'username'  => $username,
                    'timestamp' => time(),
                ]);
                $redis->publish('chat:user_updates', $notification);

                return Response::json([
                    'success' => true,
                    'message' => 'User kicked successfully and temporarily banned for 1 hour',
                ]);
            }

            return Response::json(['error' => 'Failed to kick user'], 500);
        } catch (\Throwable $e) {
            return Response::json(['error' => 'Server error: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Replaces public/api/admin/list-kicked-users.php.
     *
     * GET: scans Redis for banned_session:* keys and returns them. 200
     * {kicked_sessions:[{session_id, username, reason, kicked_at, expires_in}]}.
     * The legacy file had no try/catch and no success key — preserved as-is.
     */
    #[Route('/api/admin/list-kicked-users', methods: 'GET', name: 'admin.list-kicked-users', middleware: [AdminAuthMiddleware::class])]
    public function listKicked(): Response
    {
        $redis = Database::getRedis();

        $pattern = 'banned_session:*';
        $cursor  = null;
        $kicked  = [];
        do {
            $keys = $redis->scan($cursor, $pattern, 100);
            if ($keys === false) {
                break;
            }
            foreach ($keys as $key) {
                $ttl  = $redis->ttl($key);
                $data = json_decode($redis->get($key), true);
                if ($data) {
                    $kicked[] = [
                        'session_id' => substr($key, strlen('banned_session:')),
                        'username'   => $data['username'] ?? null,
                        'reason'     => $data['reason'] ?? null,
                        'kicked_at'  => $data['kicked_at'] ?? null,
                        'expires_in' => $ttl,
                    ];
                }
            }
        } while ($cursor !== 0 && $cursor !== null);

        return Response::json(['kicked_sessions' => $kicked]);
    }

    /**
     * Replaces public/api/admin/clear-chat.php.
     *
     * POST: soft-deletes every visible message (is_deleted = true), flushes the
     * Redis message cache and publishes a clear event. 200 {success:true,
     * message:'Public chat cleared', deleted_count:int}; thrown error -> 500
     * {error:'Server error: <msg>'}.
     */
    #[Route('/api/admin/clear-chat', methods: 'POST', name: 'admin.clear-chat', middleware: [AdminAuthMiddleware::class])]
    public function clearChat(): Response
    {
        $db    = Database::getPDO();
        $redis = Database::getRedis();

        try {
            // Soft delete all messages by setting is_deleted = true.
            $stmt = $db->prepare("UPDATE messages SET is_deleted = true WHERE is_deleted = false");
            $stmt->execute();
            $deletedCount = $stmt->rowCount();

            // Clear Redis message cache to remove all messages immediately.
            $prefix = Database::getRedisPrefix();
            $redis->del($prefix . 'chat:messages');

            // Publish clear event to all connected clients via Redis.
            $clearEvent = [
                'type'      => 'clear',
                'timestamp' => time(),
            ];

            $redis->publish($prefix . 'chat:updates', json_encode($clearEvent));

            return Response::json([
                'success'       => true,
                'message'       => 'Public chat cleared',
                'deleted_count' => $deletedCount,
            ]);
        } catch (\Throwable $e) {
            return Response::json(['error' => 'Server error: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Replaces public/api/admin/delete-message.php.
     *
     * DELETE|POST {message_id}: soft-deletes a single message, marks it deleted
     * in the Redis hash, flushes the message cache and publishes a
     * message_deleted event. 200 {success:true, message:'Message deleted
     * successfully'}. Missing message_id -> 400 {error:'Message ID is required'};
     * unknown message -> 404 {error:'Message not found'}; thrown error -> 500
     * {error:'Failed to delete message', debug, file, line} (also error_log'd).
     */
    #[Route('/api/admin/delete-message', methods: ['DELETE', 'POST'], name: 'admin.delete-message', middleware: [AdminAuthMiddleware::class])]
    public function deleteMessage(): Response
    {
        try {
            $input     = $_POST;
            $messageId = $input['message_id'] ?? null;

            if (!$messageId) {
                return Response::json(['error' => 'Message ID is required'], 400);
            }

            $pdo = Database::getPDO();

            // Mark the message as deleted (soft delete) instead of actually deleting it.
            $stmt = $pdo->prepare("UPDATE messages SET is_deleted = true WHERE message_id = ?");
            $stmt->execute([$messageId]);

            if ($stmt->rowCount() === 0) {
                return Response::json(['error' => 'Message not found'], 404);
            }

            // Publish deletion to Redis for real-time update.
            $redis  = Database::getRedis();
            $prefix = Database::getRedisPrefix();

            // Mark as deleted in Redis HASH so getHistory() can filter without the DB.
            $redis->hSet($prefix . 'chat:deleted_messages', $messageId, '1');
            // Set expiry on the hash key to match message cache TTL (24 hours).
            $redis->expire($prefix . 'chat:deleted_messages', 86400);

            // Also clear the message cache to force refresh (keeps behavior consistent).
            $redis->del($prefix . 'chat:messages');

            $deleteEvent = [
                'type'       => 'message_deleted',
                'message_id' => $messageId,
                'timestamp'  => time(),
            ];

            $redis->publish($prefix . 'chat:updates', json_encode($deleteEvent));

            return Response::json([
                'success' => true,
                'message' => 'Message deleted successfully',
            ]);
        } catch (\Throwable $e) {
            \RadioChatBox\Log::write('Delete message error: ' . $e->getMessage());

            return Response::json([
                'error' => 'Failed to delete message',
                'debug' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ], 500);
        }
    }

    /**
     * Replaces public/api/admin/url-blacklist.php.
     *
     * GET    -> 200 {success:true, patterns:[{id, pattern, description, added_by, added_at}]}
     * POST   -> add {pattern, description?} => 200 {success:true, message:'Pattern added successfully'}
     * DELETE -> remove ?id= => 200 {success:true, message:'Pattern deleted successfully'}
     * Missing pattern -> 400 {error:'Pattern is required'}; missing id -> 400
     * {error:'ID is required'}; PDO unique violation (23505) -> 400
     * {error:'Pattern already exists'}; other PDO error -> 500 {error:'Database
     * error'}; other error -> 500 {error:'Internal server error'}. NOTE: the
     * legacy blacklist file invalidates the un-prefixed Redis key
     * 'url_blacklist_patterns' (no instance prefix) — preserved verbatim.
     */
    #[Route('/api/admin/url-blacklist', methods: ['GET', 'POST', 'DELETE'], name: 'admin.url-blacklist', middleware: [AdminAuthMiddleware::class])]
    public function urlBlacklist(): Response
    {
        try {
            $db = Database::getPDO();

            if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                $stmt = $db->query("
            SELECT id, pattern, description, added_by, added_at
            FROM url_blacklist
            ORDER BY added_at DESC
        ");
                $patterns = $stmt->fetchAll(PDO::FETCH_ASSOC);

                return Response::json([
                    'success'  => true,
                    'patterns' => $patterns,
                ]);
            }

            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $input       = $_POST;
                $pattern     = trim($input['pattern'] ?? '');
                $description = trim($input['description'] ?? '');

                if (empty($pattern)) {
                    return Response::json(['error' => 'Pattern is required'], 400);
                }

                $stmt = $db->prepare("
            INSERT INTO url_blacklist (pattern, description, added_by)
            VALUES (:pattern, :description, 'admin')
        ");

                $stmt->execute([
                    'pattern'     => $pattern,
                    'description' => $description,
                ]);

                // Invalidate Redis cache (legacy uses the un-prefixed key here).
                $redis = Database::getRedis();
                $redis->del('url_blacklist_patterns');

                return Response::json([
                    'success' => true,
                    'message' => 'Pattern added successfully',
                ]);
            }

            // DELETE — remove a pattern by id.
            $id = Request::getInstance()->get('id', 0, 'get');

            if (empty($id)) {
                return Response::json(['error' => 'ID is required'], 400);
            }

            $stmt = $db->prepare("DELETE FROM url_blacklist WHERE id = ?");
            $stmt->execute([$id]);

            // Invalidate Redis cache (legacy uses the un-prefixed key here).
            $redis = Database::getRedis();
            $redis->del('url_blacklist_patterns');

            return Response::json([
                'success' => true,
                'message' => 'Pattern deleted successfully',
            ]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23505') { // Unique violation
                return Response::json(['error' => 'Pattern already exists'], 400);
            }

            \RadioChatBox\Log::write("URL Blacklist error: " . $e->getMessage());
            return Response::json(['error' => 'Database error'], 500);
        } catch (\Throwable $e) {
            \RadioChatBox\Log::write("URL Blacklist error: " . $e->getMessage());
            return Response::json(['error' => 'Internal server error'], 500);
        }
    }

    /**
     * Replaces public/api/admin/url-whitelist.php.
     *
     * GET    -> 200 {success:true, patterns:[{id, pattern, description, added_by, added_at}]}
     * POST   -> add {pattern, description?} => 200 {success:true, message:'Pattern added successfully'}
     * DELETE -> remove ?id= => 200 {success:true, message:'Pattern deleted successfully'}
     * Missing pattern -> 400 {error:'Pattern is required'}; missing id -> 400
     * {error:'ID is required'}; PDO unique violation (23505) -> 400
     * {error:'Pattern already exists'}; other PDO error -> 500 {error:'Database
     * error'}; other error -> 500 {error:'Internal server error'}. NOTE: unlike
     * the blacklist, the legacy whitelist file invalidates the PREFIXED Redis key
     * (getRedisPrefix() . 'url_whitelist_patterns') — preserved verbatim.
     */
    #[Route('/api/admin/url-whitelist', methods: ['GET', 'POST', 'DELETE'], name: 'admin.url-whitelist', middleware: [AdminAuthMiddleware::class])]
    public function urlWhitelist(): Response
    {
        try {
            $db = Database::getPDO();

            if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                $stmt = $db->query("
            SELECT id, pattern, description, added_by, added_at
            FROM url_whitelist
            ORDER BY added_at DESC
        ");
                $patterns = $stmt->fetchAll(PDO::FETCH_ASSOC);

                return Response::json([
                    'success'  => true,
                    'patterns' => $patterns,
                ]);
            }

            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $input       = $_POST;
                $pattern     = trim($input['pattern'] ?? '');
                $description = trim($input['description'] ?? '');

                if (empty($pattern)) {
                    return Response::json(['error' => 'Pattern is required'], 400);
                }

                $stmt = $db->prepare("
            INSERT INTO url_whitelist (pattern, description, added_by)
            VALUES (:pattern, :description, 'admin')
        ");

                $stmt->execute([
                    'pattern'     => $pattern,
                    'description' => $description,
                ]);

                // Invalidate Redis cache (legacy uses the prefixed key here).
                $redis  = Database::getRedis();
                $prefix = Database::getRedisPrefix();
                $redis->del($prefix . 'url_whitelist_patterns');

                return Response::json([
                    'success' => true,
                    'message' => 'Pattern added successfully',
                ]);
            }

            // DELETE — remove a pattern by id.
            $id = Request::getInstance()->get('id', 0, 'get');

            if (empty($id)) {
                return Response::json(['error' => 'ID is required'], 400);
            }

            $stmt = $db->prepare("DELETE FROM url_whitelist WHERE id = ?");
            $stmt->execute([$id]);

            // Invalidate Redis cache (legacy uses the prefixed key here).
            $redis  = Database::getRedis();
            $prefix = Database::getRedisPrefix();
            $redis->del($prefix . 'url_whitelist_patterns');

            return Response::json([
                'success' => true,
                'message' => 'Pattern deleted successfully',
            ]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23505') { // Unique violation
                return Response::json(['error' => 'Pattern already exists'], 400);
            }

            \RadioChatBox\Log::write("URL Whitelist error: " . $e->getMessage());
            return Response::json(['error' => 'Database error'], 500);
        } catch (\Throwable $e) {
            \RadioChatBox\Log::write("URL Whitelist error: " . $e->getMessage());
            return Response::json(['error' => 'Internal server error'], 500);
        }
    }
}
