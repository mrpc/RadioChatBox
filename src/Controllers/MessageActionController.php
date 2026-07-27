<?php

namespace RadioChatBox\Controllers;

use InvalidArgumentException;
use PDO;
use Pramnos\Http\Request;
use Pramnos\Http\Response;
use Pramnos\Routing\Attributes\Route;
use RadioChatBox\BlockService;
use RadioChatBox\BotService;
use RadioChatBox\ChatService;
use RadioChatBox\Http\Validate;
use RadioChatBox\Database;
use RadioChatBox\MessageFilter;
use RadioChatBox\PhotoService;
use RadioChatBox\ReactionService;
use RuntimeException;

/**
 * MessageAction resource controller (public, isAdmin=false).
 *
 * Groups the message-interaction endpoints migrated 1:1 from the legacy
 * file-per-endpoint API. The framework Request has already decoded the JSON
 * body into $_POST for POST routes, so bodies are read from $_POST (re-reading
 * php://input would come back empty); GET query params are read via
 * Request::getInstance()->get(...,'get'). Legacy 403 "exit" branches become
 * Response::json(..., 403) returns, and every status code / JSON key / error
 * string is reproduced exactly.
 *
 * Replaces:
 *   public/api/react.php            -> POST/GET /api/react
 *   public/api/edit-message.php     -> POST     /api/edit-message
 *   public/api/block.php            -> POST/GET /api/block
 *   public/api/private-message.php  -> POST/GET /api/private-message
 */
final class MessageActionController
{
    /**
     * POST /api/react — toggle an emoji reaction on a message.
     *
     * Migrated from public/api/react.php (POST branch). Requires
     * message_id/username/session_id (+ emoji); verifies the caller owns the
     * session before toggling. Success: {success:true} merged with the
     * ReactionService::toggleReaction() result. Errors: invalid/empty body or
     * missing fields -> 400, invalid session -> 403 {"error":"Invalid session"},
     * RuntimeException -> 404, anything else -> 500.
     */
    #[Route('/api/react', methods: 'POST', name: 'message-action.react.toggle')]
    public function react(): Response
    {
        try {
            $input = $_POST;
            if (empty($input)) {
                throw new InvalidArgumentException('Invalid JSON');
            }

            // Pre-trim so the required rules reject whitespace-only values exactly
            // as the original === '' checks did.
            $data = [
                'message_id' => trim($input['message_id'] ?? ''),
                'username'   => trim($input['username'] ?? ''),
                'session_id' => trim($input['session_id'] ?? ''),
            ];
            $error = Validate::check($data, [
                'message_id' => 'required',
                'username'   => 'required',
                'session_id' => 'required',
            ], [
                'message_id.required' => 'message_id, username and session_id are required',
                'username.required'   => 'message_id, username and session_id are required',
                'session_id.required' => 'message_id, username and session_id are required',
            ]);
            if ($error) {
                return $error;
            }

            $messageId = $data['message_id'];
            $username  = $data['username'];
            $sessionId = $data['session_id'];
            $emoji     = $input['emoji'] ?? '';

            // Verify the caller owns this session (prevents spoofing reactions as others).
            $chatService = new ChatService();
            if ($chatService->getSessionInfo($username, $sessionId) === null) {
                return Response::json(['error' => 'Invalid session'], 403);
            }

            $result = (new ReactionService())->toggleReaction($messageId, $username, $sessionId, $emoji);

            return Response::json(['success' => true] + $result);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        } catch (RuntimeException $e) {
            return Response::json(['error' => $e->getMessage()], 404);
        } catch (\Throwable $e) {
            \RadioChatBox\Log::write($e->getMessage());
            return Response::json(['error' => 'Internal server error'], 500);
        }
    }

    /**
     * GET /api/react — the allowed emoji set for the reaction picker.
     *
     * Migrated from public/api/react.php (GET branch). Success:
     * {success:true, allowed:[...]}.
     */
    #[Route('/api/react', methods: 'GET', name: 'message-action.react.allowed')]
    public function reactAllowed(): Response
    {
        try {
            return Response::json([
                'success' => true,
                'allowed' => ReactionService::getAllowedEmojis(),
            ]);
        } catch (\Throwable $e) {
            \RadioChatBox\Log::write($e->getMessage());
            return Response::json(['error' => 'Internal server error'], 500);
        }
    }

    /**
     * POST /api/edit-message — edit your own public message within 10 minutes.
     *
     * Migrated from public/api/edit-message.php. Requires
     * message_id/message/username/sessionId; enforces a 500-char limit, verifies
     * ownership + the 10-minute window in PostgreSQL, filters the new text, then
     * updates the row, invalidates the Redis message cache/hash and publishes a
     * message_edited SSE event. Success:
     * {success, message, edited_at, timestamp}. Errors: missing fields / too
     * long -> 400, not owner -> 403, window expired -> 403, DB error -> 500
     * {"error":"Database error"}, other -> 500 {"error":"Server error"}.
     */
    #[Route('/api/edit-message', methods: 'POST', name: 'message-action.edit-message')]
    public function editMessage(): Response
    {
        try {
            $input = $_POST;

            // Pre-trim so required rejects whitespace-only exactly as the legacy
            // empty() checks did; max:500 is a string-length limit (same as send).
            $data = [
                'message_id' => trim($input['message_id'] ?? ''),
                'message'    => trim($input['message']    ?? ''),
                'username'   => trim($input['username']   ?? ''),
                'sessionId'  => trim($input['sessionId']  ?? ''),
            ];
            $error = Validate::check($data, [
                'message_id' => 'required',
                'message'    => 'required|max:500',
                'username'   => 'required',
                'sessionId'  => 'required',
            ], [
                'message_id.required' => 'message_id, message, username and sessionId are required',
                'message.required'    => 'message_id, message, username and sessionId are required',
                'username.required'   => 'message_id, message, username and sessionId are required',
                'sessionId.required'  => 'message_id, message, username and sessionId are required',
                'message.max'         => 'Message too long (max 500 characters)',
            ]);
            if ($error) {
                return $error;
            }

            $messageId = $data['message_id'];
            $newText   = $data['message'];
            $username  = $data['username'];
            $sessionId = $data['sessionId'];

            $pdo = Database::getPDO();

            // Fetch the original message and verify ownership + timing in one query
            $stmt = $pdo->prepare(
                'SELECT m.message_id, m.username, m.created_at, m.is_deleted, s.user_id,
                        EXTRACT(EPOCH FROM (
                            NOW() - ((m.created_at AT TIME ZONE current_setting($$TIMEZONE$$)) AT TIME ZONE $$UTC$$)
                        )) AS age_seconds
                 FROM messages m
                 INNER JOIN sessions s ON s.username = m.username
                 WHERE m.message_id = :message_id
                     AND m.username   = :username
                     AND s.session_id = :session_id
                     AND m.is_deleted = false
                 LIMIT 1'
            );
            $stmt->execute([
                'message_id' => $messageId,
                'username'   => $username,
                'session_id' => $sessionId,
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                return Response::json(['error' => 'Message not found or you do not own it'], 403);
            }

            // Enforce 10-minute edit window (comparison done in PostgreSQL to avoid timezone issues)
            if ((float) $row['age_seconds'] > 600) {
                return Response::json(['error' => 'Edit window has expired (10 minutes)'], 403);
            }

            // Filter the new text (same pipeline as public messages)
            $filterResult = MessageFilter::filterPublicMessage($newText);
            $filtered = $filterResult['filtered'];

            // Persist to PostgreSQL
            $updateStmt = $pdo->prepare(
                'UPDATE messages
                 SET message = :message, edited_at = NOW()
                 WHERE message_id = :message_id'
            );
            $updateStmt->execute([
                'message'    => $filtered,
                'message_id' => $messageId,
            ]);

            // Invalidate the Redis message list cache so getHistory() refetches from DB
            $redis  = Database::getRedis();
            $prefix = Database::getRedisPrefix();
            $redis->del($prefix . 'chat:messages');

            // Also update the HASH used for reply lookups
            $hashKey = $prefix . 'chat:messages:hash';
            $existing = $redis->hGet($hashKey, $messageId);
            if ($existing !== false) {
                $hashData = json_decode($existing, true) ?: [];
                $hashData['message'] = $filtered;
                $redis->hSet($hashKey, $messageId, json_encode($hashData));
            }

            // Publish real-time edit event to all SSE clients
            $editedAtIso = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c');
            $timestamp = strtotime($row['created_at']);
            $editEvent = [
                'type'       => 'message_edited',
                'message_id' => $messageId,
                'message'    => $filtered,
                'edited_at'  => $editedAtIso,
                'timestamp'  => $timestamp,
            ];
            $redis->publish($prefix . 'chat:updates', json_encode($editEvent));

            return Response::json([
                'success'   => true,
                'message'   => $filtered,
                'edited_at' => $editedAtIso,
                'timestamp' => $timestamp,
            ]);
        } catch (\PDOException $e) {
            \RadioChatBox\Log::write('Edit message DB error: ' . $e->getMessage());
            return Response::json(['error' => 'Database error'], 500);
        } catch (\Throwable $e) {
            \RadioChatBox\Log::write('Edit message error: ' . $e->getMessage());
            return Response::json(['error' => 'Server error'], 500);
        }
    }

    /**
     * POST /api/block — block or unblock another user in DMs.
     *
     * Migrated from public/api/block.php (POST branch). Requires
     * username/session_id/target_username and an action of "block"|"unblock";
     * verifies session ownership first. Success:
     * {success, action, target_username, blocked}. Errors: invalid/empty body,
     * missing fields, or bad action -> 400, invalid session -> 403
     * {"error":"Invalid session"}, other -> 500.
     */
    #[Route('/api/block', methods: 'POST', name: 'message-action.block.action')]
    public function block(): Response
    {
        try {
            $blockService = new BlockService();
            $chatService  = new ChatService();

            $input = $_POST;
            if (empty($input)) {
                throw new InvalidArgumentException('Invalid JSON');
            }

            $action = $input['action'] ?? '';
            $data = [
                'username'        => trim($input['username'] ?? ''),
                'session_id'      => trim($input['session_id'] ?? ''),
                'target_username' => trim($input['target_username'] ?? ''),
            ];
            $error = Validate::check($data, [
                'username'        => 'required',
                'session_id'      => 'required',
                'target_username' => 'required',
            ], [
                'username.required'        => 'username, session_id and target_username are required',
                'session_id.required'      => 'username, session_id and target_username are required',
                'target_username.required' => 'username, session_id and target_username are required',
            ]);
            if ($error) {
                return $error;
            }

            $username       = $data['username'];
            $sessionId      = $data['session_id'];
            $targetUsername = $data['target_username'];

            // Verify the caller actually owns this session (prevents trivial spoofing).
            if ($chatService->getSessionInfo($username, $sessionId) === null) {
                return Response::json(['error' => 'Invalid session'], 403);
            }

            if ($action === 'block') {
                $blockService->blockUser($username, $targetUsername);
                return Response::json([
                    'success'         => true,
                    'action'          => 'block',
                    'target_username' => $targetUsername,
                    'blocked'         => true,
                ]);
            } elseif ($action === 'unblock') {
                $blockService->unblockUser($username, $targetUsername);
                return Response::json([
                    'success'         => true,
                    'action'          => 'unblock',
                    'target_username' => $targetUsername,
                    'blocked'         => false,
                ]);
            } else {
                throw new InvalidArgumentException('action must be "block" or "unblock"');
            }
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            \RadioChatBox\Log::write($e->getMessage());
            return Response::json(['error' => 'Internal server error'], 500);
        }
    }

    /**
     * GET /api/block — a user's blocked list, plus optional per-conversation state.
     *
     * Migrated from public/api/block.php (GET branch). Requires username; with
     * an optional with_user adds i_blocked/is_blocked_between for rendering the
     * Block/Unblock button. Success: {success, blocked_users[, with_user,
     * i_blocked, is_blocked_between]}. Errors: missing username -> 400,
     * other -> 500.
     */
    #[Route('/api/block', methods: 'GET', name: 'message-action.block.list')]
    public function blockList(): Response
    {
        try {
            $blockService = new BlockService();

            $request  = Request::getInstance();
            $username = trim((string) $request->get('username', '', 'get'));
            $withUser = trim((string) $request->get('with_user', '', 'get'));

            if ($username === '') {
                throw new InvalidArgumentException('username is required');
            }

            $response = [
                'success'       => true,
                'blocked_users' => $blockService->getBlockedUsers($username),
            ];

            // Optional per-conversation state for rendering the Block/Unblock button.
            if ($withUser !== '') {
                $response['with_user'] = $withUser;
                $response['i_blocked'] = $blockService->hasBlocked($username, $withUser);
                $response['is_blocked_between'] = $blockService->isBlockedBetween($username, $withUser);
            }

            return Response::json($response);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            \RadioChatBox\Log::write($e->getMessage());
            return Response::json(['error' => 'Internal server error'], 500);
        }
    }

    /**
     * POST /api/private-message — send a private (DM) message.
     *
     * Migrated from public/api/private-message.php (POST branch). Requires
     * from_username/to_username/from_session_id and either message or
     * attachment_id; filters the body, enforces mutual DM blocks, resolves the
     * recipient's live/fake/last-known session (grace period), stores the row,
     * publishes it over Redis, notifies admins for human-answered fake users and
     * schedules a bot auto-reply. Success:
     * {success, message:"Private message sent", data:{...}}. Errors: missing
     * fields / no message+attachment / recipient not online -> 400, mutual
     * block -> 403 {"error":"You cannot send messages to this user."},
     * other -> 500.
     */
    #[Route('/api/private-message', methods: 'POST', name: 'message-action.private-message.send')]
    public function privateMessage(): Response
    {
        try {
            $db    = Database::getPDO();
            $redis = Database::getRedis();

            $input = $_POST;
            if (empty($input)) {
                throw new InvalidArgumentException('Invalid JSON');
            }

            $error = Validate::check($input, [
                'from_username'   => 'required',
                'to_username'     => 'required',
                'from_session_id' => 'required',
            ], [
                'from_username.required'   => 'From username, to username, and session ID are required',
                'to_username.required'     => 'From username, to username, and session ID are required',
                'from_session_id.required' => 'From username, to username, and session ID are required',
            ]);
            if ($error) {
                return $error;
            }

            $fromUsername  = $input['from_username'];
            $fromSessionId = $input['from_session_id'];
            $toUsername    = $input['to_username'];
            $message       = $input['message'] ?? '';
            $attachmentId  = $input['attachment_id'] ?? null;

            // Message is optional if there's an attachment (cross-field rule).
            if (empty($message) && empty($attachmentId)) {
                throw new InvalidArgumentException('Either message or attachment is required');
            }

            // Get IP address for violation tracking
            $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';

            // Filter message for private chat (blocks dangerous content and blacklisted URLs).
            // NOTE: store the RAW (unescaped) text — like public messages — because
            // every renderer HTML-escapes at output time. Escaping here too caused
            // double-escaping (e.g. " -> &quot;).
            if (!empty($message)) {
                $filterResult = MessageFilter::filterPrivateMessage($message, $ipAddress);
                $message = trim($filterResult['filtered']);
            }

            // Sanitize usernames
            $fromUsername = MessageFilter::sanitizeForOutput(trim($fromUsername));
            $toUsername = MessageFilter::sanitizeForOutput(trim($toUsername));

            // Enforce DM blocks (mutual): if either user has blocked the other,
            // reject the message. The block is never stored/published, so the
            // recipient never receives it.
            $blockService = new BlockService();
            if ($blockService->isBlockedBetween($fromUsername, $toUsername)) {
                return Response::json(['error' => 'You cannot send messages to this user.'], 403);
            }

            // Check if recipient has a live session (most recent one if multiple devices)
            $stmt = $db->prepare("SELECT session_id FROM sessions WHERE username = ? ORDER BY last_heartbeat DESC LIMIT 1");
            $stmt->execute([$toUsername]);
            $recipient = $stmt->fetch();

            $isFakeUser = false;
            $fakeUserBotEnabled = false;
            if ($recipient) {
                $toSessionId = $recipient['session_id'];
            } else {
                // No live session. Check if it's an active fake user.
                $stmt = $db->prepare("SELECT nickname, bot_enabled FROM fake_users WHERE nickname = ? AND is_active = TRUE");
                $stmt->execute([$toUsername]);
                $fakeUser = $stmt->fetch();

                if ($fakeUser) {
                    // Create a fake session ID for the fake user
                    $toSessionId = 'fake_' . md5($toUsername);
                    $isFakeUser = true;
                    $fakeUserBotEnabled = (bool) $fakeUser['bot_enabled'];
                } else {
                    // Grace period: the recipient has no live session, but they may
                    // have just gone offline (browser reload / unstable connection).
                    // Fall back to their most recent known session id from previous
                    // messages so the DM is delivered when they reconnect (the client
                    // keeps the same session id in localStorage). This lets short
                    // disconnects continue a conversation instead of hard-failing.
                    $stmt = $db->prepare("
                        SELECT session_id FROM (
                            SELECT to_session_id AS session_id, created_at
                            FROM private_messages WHERE to_username = ?
                            UNION ALL
                            SELECT from_session_id AS session_id, created_at
                            FROM private_messages WHERE from_username = ?
                        ) recent
                        ORDER BY created_at DESC
                        LIMIT 1
                    ");
                    $stmt->execute([$toUsername, $toUsername]);
                    $recentSessionId = $stmt->fetchColumn();

                    if ($recentSessionId) {
                        $toSessionId = $recentSessionId;
                    } else {
                        // Never had a session and is not a fake user: unknown recipient.
                        throw new RuntimeException('Recipient is not online');
                    }
                }
            }

            // Get display names for sender and recipient BEFORE storing message (for snapshot)
            $fromDisplayName = null;
            $toDisplayName = null;

            $stmt = $db->prepare("SELECT u.username, u.display_name FROM users u WHERE u.username IN (?, ?)");
            $stmt->execute([$fromUsername, $toUsername]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if ($row['username'] === $fromUsername) {
                    $fromDisplayName = $row['display_name'];
                }
                if ($row['username'] === $toUsername) {
                    $toDisplayName = $row['display_name'];
                }
            }

            // Store message with session IDs and display_name snapshots
            $stmt = $db->prepare("
                INSERT INTO private_messages (from_username, from_session_id, from_display_name, to_username, to_session_id, to_display_name, message, attachment_id, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                RETURNING id, created_at
            ");
            $stmt->execute([$fromUsername, $fromSessionId, $fromDisplayName, $toUsername, $toSessionId, $toDisplayName, $message, $attachmentId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            // Get attachment info if present
            $attachmentData = null;
            if ($attachmentId) {
                $photoService = new PhotoService();
                $attachmentData = $photoService->getAttachment($attachmentId);
            }

            // Publish to Redis for real-time delivery
            $messageData = [
                'id' => $result['id'],
                'from_username' => $fromUsername,
                'from_display_name' => $fromDisplayName,
                'to_username' => $toUsername,
                'to_display_name' => $toDisplayName,
                'message' => $message,
                'attachment' => $attachmentData,
                'timestamp' => strtotime($result['created_at']),
                'type' => 'private'
            ];

            $prefix = Database::getRedisPrefix();
            $redis->publish($prefix . 'chat:private_messages', json_encode($messageData));

            // If message was sent to a fake user, create admin notification —
            // UNLESS an AI bot is going to answer it. A bot handles its own
            // conversations, so alerting the admin about every DM to it is just
            // spam. We still notify for fake users a human has to answer: bot
            // disabled on this one, or auto-replies switched off globally.
            if ($isFakeUser) {
                $botService = new BotService();
                $botWillHandle = $botService->willAutoReply(['bot_enabled' => $fakeUserBotEnabled]);

                if (!$botWillHandle) {
                    try {
                        $stmt = $db->prepare("SELECT create_fake_user_dm_notification(?, ?, ?, ?)");
                        $stmt->execute([
                            $fromUsername,
                            $toUsername,
                            $message ?: '[Photo attachment]',
                            $result['id']
                        ]);
                        $notificationId = $stmt->fetchColumn();

                        // Publish notification to Redis for real-time admin alerts
                        $notificationData = [
                            'id' => $notificationId,
                            'type' => 'fake_user_dm',
                            'from_username' => $fromUsername,
                            'to_username' => $toUsername,
                            'message_preview' => substr($message ?: '[Photo attachment]', 0, 100),
                            'message_id' => $result['id'],
                            'timestamp' => time()
                        ];
                        $redis->publish($prefix . 'chat:admin_notifications', json_encode($notificationData));
                    } catch (\Exception $e) {
                        // Log error but don't fail the message send
                        \RadioChatBox\Log::write("Failed to create admin notification: " . $e->getMessage());
                    }
                }

                // Schedule an automatic bot reply. This only queues a job (the LLM
                // call happens in worker.php), and it is a no-op unless the
                // feature is enabled for this fake user. BotService swallows its
                // own errors so the user's message is never affected.
                $botService->onIncomingMessage(
                    $toUsername,
                    $fromUsername,
                    $fromSessionId,
                    // Passed so abuse is judged on what was actually sent.
                    $message
                );
            }

            return Response::json([
                'success' => true,
                'message' => 'Private message sent',
                'data' => $messageData
            ]);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        } catch (RuntimeException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            \RadioChatBox\Log::write($e->getMessage());
            return Response::json(['error' => 'Internal server error'], 500);
        }
    }

    /**
     * GET /api/private-message — private message history.
     *
     * Migrated from public/api/private-message.php (GET branch). Requires
     * username + session_id; with an optional with_user returns that
     * conversation (session-scoped for real users, full history for fake users,
     * and admin=true returns ALL messages between the pair). Attachments are
     * folded into an `attachment` object per row. Success:
     * {success, messages, debug:{username, touser}}. Errors: missing
     * username/session -> 400, other -> 500.
     */
    #[Route('/api/private-message', methods: 'GET', name: 'message-action.private-message.list')]
    public function privateMessageList(): Response
    {
        try {
            $db = Database::getPDO();

            $request   = Request::getInstance();
            $username  = $request->get('username', '', 'get');
            $sessionId = $request->get('session_id', '', 'get');
            $withUser  = $request->get('with_user', null, 'get');
            $adminMode = $request->get('admin', null, 'get') === 'true';

            if (empty($username) || empty($sessionId)) {
                throw new InvalidArgumentException('Username and session ID are required');
            }

            if ($withUser) {
                // Get conversation with specific user
                if ($adminMode) {
                    // Admin mode: Get ALL messages between these two users, ignoring session_id
                    $stmt = $db->prepare("
                        SELECT pm.*,
                               a.attachment_id, a.filename, a.file_path, a.file_size,
                               a.mime_type, a.width, a.height,
                               u_from.display_name as from_display_name,
                               u_to.display_name as to_display_name
                        FROM private_messages pm
                        LEFT JOIN attachments a ON pm.attachment_id = a.attachment_id AND a.is_deleted = FALSE
                        LEFT JOIN users u_from ON pm.from_username = u_from.username
                        LEFT JOIN users u_to ON pm.to_username = u_to.username
                        WHERE ((pm.from_username = ? AND pm.to_username = ?)
                            OR (pm.from_username = ? AND pm.to_username = ?))
                        ORDER BY pm.created_at ASC
                        LIMIT 500
                    ");
                    $stmt->execute([$username, $withUser, $withUser, $username]);
                } else {
                    // Check if talking to a fake user
                    $stmt2 = $db->prepare("SELECT nickname FROM fake_users WHERE nickname = ?");
                    $stmt2->execute([$withUser]);
                    $isFakeUser = $stmt2->fetch() !== false;

                    if ($isFakeUser) {
                        // Fake users are persistent: show all messages ever sent to this fake user
                        // This allows users to see admin replies even if their session changed
                        $stmt = $db->prepare("
                            SELECT pm.*,
                                   a.attachment_id, a.filename, a.file_path, a.file_size,
                                   a.mime_type, a.width, a.height,
                                   u_from.display_name as from_display_name,
                                   u_to.display_name as to_display_name
                            FROM private_messages pm
                            LEFT JOIN attachments a ON pm.attachment_id = a.attachment_id AND a.is_deleted = FALSE
                            LEFT JOIN users u_from ON pm.from_username = u_from.username
                            LEFT JOIN users u_to ON pm.to_username = u_to.username
                            WHERE ((pm.from_username = ? AND pm.to_username = ?)
                                OR (pm.from_username = ? AND pm.to_username = ?))
                            ORDER BY pm.created_at ASC
                            LIMIT 500
                        ");
                        $stmt->execute([$username, $withUser, $withUser, $username]);
                    } else {
                        // Real users: only show messages from current session
                        // This prevents guests with the same username from seeing each other's messages
                        $stmt = $db->prepare("
                            SELECT pm.*,
                                   a.attachment_id, a.filename, a.file_path, a.file_size,
                                   a.mime_type, a.width, a.height,
                                   u_from.display_name as from_display_name,
                                   u_to.display_name as to_display_name
                            FROM private_messages pm
                            LEFT JOIN attachments a ON pm.attachment_id = a.attachment_id AND a.is_deleted = FALSE
                            LEFT JOIN users u_from ON pm.from_username = u_from.username
                            LEFT JOIN users u_to ON pm.to_username = u_to.username
                            WHERE ((pm.from_username = ? AND pm.from_session_id = ? AND pm.to_username = ?)
                                OR (pm.from_username = ? AND pm.to_username = ? AND pm.to_session_id = ?))
                            ORDER BY pm.created_at ASC
                            LIMIT 500
                        ");
                        $stmt->execute([$username, $sessionId, $withUser, $withUser, $username, $sessionId]);
                    }
                }
            } else {
                // Get all recent private messages for current session only
                $stmt = $db->prepare("
                    SELECT pm.*,
                           a.attachment_id, a.filename, a.file_path, a.file_size,
                           a.mime_type, a.width, a.height,
                           u_from.display_name as from_display_name,
                           u_to.display_name as to_display_name
                    FROM private_messages pm
                    LEFT JOIN attachments a ON pm.attachment_id = a.attachment_id AND a.is_deleted = FALSE
                    LEFT JOIN users u_from ON pm.from_username = u_from.username
                    LEFT JOIN users u_to ON pm.to_username = u_to.username
                    WHERE (pm.from_username = ? AND pm.from_session_id = ?)
                       OR (pm.to_username = ? AND pm.to_session_id = ?)
                    ORDER BY pm.created_at DESC
                    LIMIT 50
                ");
                $stmt->execute([$username, $sessionId, $username, $sessionId]);
            }

            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Format attachment data for each message
            foreach ($messages as &$message) {
                if ($message['attachment_id']) {
                    $message['attachment'] = [
                        'attachment_id' => $message['attachment_id'],
                        'filename' => $message['filename'],
                        'file_path' => $message['file_path'],
                        'file_size' => $message['file_size'],
                        'mime_type' => $message['mime_type'],
                        'width' => $message['width'],
                        'height' => $message['height']
                    ];
                } else {
                    $message['attachment'] = null;
                }

                // Remove duplicate fields
                unset($message['filename'], $message['file_size'],
                      $message['mime_type'], $message['width'], $message['height']);
            }
            unset($message);

            return Response::json([
                'success' => true,
                'messages' => $messages,
                'debug' => [
                    'username' => $username,
                    'touser' => $withUser
                ]
            ]);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        } catch (RuntimeException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            \RadioChatBox\Log::write($e->getMessage());
            return Response::json(['error' => 'Internal server error'], 500);
        }
    }
}
