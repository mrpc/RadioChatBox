<?php

namespace RadioChatBox\Controllers;

use InvalidArgumentException;
use Pramnos\Http\Request;
use Pramnos\Http\Response;
use Pramnos\Routing\Attributes\Route;
use Pramnos\Broadcasting\BroadcastingManager;
use Pramnos\Cache\FlatCache;
use RadioChatBox\Services\ChatService;
use Pramnos\Database\Database;
use RadioChatBox\KickRegistry;
use RadioChatBox\MessageHistory;
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
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log($e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
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
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log($e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
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
        $db = Database::getInstance();

        try {
            $data = $_POST;

            if (!$data || !isset($data['username'])) {
                return Response::json(['error' => 'Username required'], 400);
            }

            $username = $data['username'];

            // Get user's IP and session before removing them.
            $userRow = $db->queryBuilder()
                ->from('sessions')
                ->select(['ip_address', 'session_id'])
                ->where('username', '=', $username)
                ->first();
            $user = ($userRow && $userRow->numRows > 0) ? $userRow->fields : null;

            if (!$user) {
                return Response::json(['error' => 'User not found'], 404);
            }

            // Ban the user's session ID temporarily (1 hour) to prevent immediate rejoin.
            (new KickRegistry())->kick((string) $user['session_id'], $username);

            // Remove from database.
            $result = $db->queryBuilder()
                ->from('sessions')
                ->where('username', '=', $username)
                ->delete();

            if ($result) {
                // Notify clients that user was kicked. (Historically published to an
                // UNPREFIXED channel — now normalized to the prefixed channel the SSE
                // edge subscribes to, via the BroadcastingManager's ConnectionManager prefix.)
                BroadcastingManager::instance()->broadcast('chat:user_updates', 'user_kicked', [
                    'type'      => 'user_kicked',
                    'username'  => $username,
                    'timestamp' => time(),
                ]);

                return Response::json([
                    'success' => true,
                    'message' => 'User kicked successfully and temporarily banned for 1 hour',
                ]);
            }

            return Response::json(['error' => 'Failed to kick user'], 500);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            return Response::json(['error' => 'Server error: ' . $e->getMessage()], 500);
        }
        // @codeCoverageIgnoreEnd
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
        return Response::json(['kicked_sessions' => (new KickRegistry())->list()]);
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
        $db = Database::getInstance();

        try {
            // Soft delete all messages by setting is_deleted = true.
            $result = $db->queryBuilder()
                ->from('messages')
                ->where('is_deleted', '=', false)
                ->update(['is_deleted' => true]);
            $deletedCount = $result ? $result->getAffectedRows() : 0;

            // Clear the recent-history cache to remove all messages immediately.
            (new MessageHistory())->clear();

            // Publish clear event to all connected clients via Redis.
            $clearEvent = [
                'type'      => 'clear',
                'timestamp' => time(),
            ];

            BroadcastingManager::instance()->broadcast('chat:updates', 'clear', $clearEvent);

            return Response::json([
                'success'       => true,
                'message'       => 'Public chat cleared',
                'deleted_count' => $deletedCount,
            ]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            return Response::json(['error' => 'Server error: ' . $e->getMessage()], 500);
        }
        // @codeCoverageIgnoreEnd
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

            $db = Database::getInstance();

            // Mark the message as deleted (soft delete) instead of actually deleting it.
            $result = $db->queryBuilder()
                ->from('messages')
                ->where('message_id', '=', $messageId)
                ->update(['is_deleted' => true]);

            if (($result ? $result->getAffectedRows() : 0) === 0) {
                return Response::json(['error' => 'Message not found'], 404);
            }

            // Tombstone the message so getHistory() can filter it without the DB,
            // and clear the recent-history cache to force a refresh.
            $history = new MessageHistory();
            $history->markDeleted($messageId);
            $history->clear();

            $deleteEvent = [
                'type'       => 'message_deleted',
                'message_id' => $messageId,
                'timestamp'  => time(),
            ];

            BroadcastingManager::instance()->broadcast('chat:updates', 'message_deleted', $deleteEvent);

            return Response::json([
                'success' => true,
                'message' => 'Message deleted successfully',
            ]);
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Delete message error: ' . $e->getMessage(), 'radiochatbox');

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
            $db = Database::getInstance();

            if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                $result = $db->query("
            SELECT id, pattern, description, added_by, added_at
            FROM url_blacklist
            ORDER BY added_at DESC
        ");
                $patterns = $result ? $result->fetchAll() : [];

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

                $db->queryBuilder()->from('url_blacklist')->insert([
                    'pattern'     => $pattern,
                    'description' => $description,
                    'added_by'    => 'admin',
                ]);

                // Invalidate the cached blacklist patterns.
                FlatCache::default()->delete('url_blacklist_patterns');

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

            $db->queryBuilder()->from('url_blacklist')->where('id', '=', $id)->delete();

            // Invalidate the cached blacklist patterns.
            FlatCache::default()->delete('url_blacklist_patterns');

            return Response::json([
                'success' => true,
                'message' => 'Pattern deleted successfully',
            ]);
        } catch (\Throwable $e) {
            // The framework DB layer throws a generic Exception (not PDOException)
            // whose message carries the driver text; a duplicate pattern still maps
            // to the legacy 400, DB errors to 'Database error', the rest to 500.
            $msg       = $e->getMessage();
            $isDbError = $e instanceof \PDOException || stripos($msg, 'SQL QUERY') !== false;
            if ($isDbError && (stripos($msg, 'duplicate key') !== false || stripos($msg, 'unique constraint') !== false)) {
                return Response::json(['error' => 'Pattern already exists'], 400);
            }

            \Pramnos\Logs\Logger::log("URL Blacklist error: " . $msg, 'radiochatbox');
            return Response::json(['error' => $isDbError ? 'Database error' : 'Internal server error'], 500);
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
     * ('url_whitelist_patterns', which Cache prefixes) — preserved verbatim.
     */
    #[Route('/api/admin/url-whitelist', methods: ['GET', 'POST', 'DELETE'], name: 'admin.url-whitelist', middleware: [AdminAuthMiddleware::class])]
    public function urlWhitelist(): Response
    {
        try {
            $db = Database::getInstance();

            if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                $result = $db->query("
            SELECT id, pattern, description, added_by, added_at
            FROM url_whitelist
            ORDER BY added_at DESC
        ");
                $patterns = $result ? $result->fetchAll() : [];

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

                $db->queryBuilder()->from('url_whitelist')->insert([
                    'pattern'     => $pattern,
                    'description' => $description,
                    'added_by'    => 'admin',
                ]);

                // Invalidate the cached whitelist patterns.
                FlatCache::default()->delete('url_whitelist_patterns');

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

            $db->queryBuilder()->from('url_whitelist')->where('id', '=', $id)->delete();

            // Invalidate the cached whitelist patterns.
            FlatCache::default()->delete('url_whitelist_patterns');

            return Response::json([
                'success' => true,
                'message' => 'Pattern deleted successfully',
            ]);
        } catch (\Throwable $e) {
            // Same duplicate/DB-error mapping as the blacklist: the framework DB
            // layer throws a generic Exception, so classify by message.
            $msg       = $e->getMessage();
            $isDbError = $e instanceof \PDOException || stripos($msg, 'SQL QUERY') !== false;
            if ($isDbError && (stripos($msg, 'duplicate key') !== false || stripos($msg, 'unique constraint') !== false)) {
                return Response::json(['error' => 'Pattern already exists'], 400);
            }

            \Pramnos\Logs\Logger::log("URL Whitelist error: " . $msg, 'radiochatbox');
            return Response::json(['error' => $isDbError ? 'Database error' : 'Internal server error'], 500);
        }
    }
}
