<?php

namespace RadioChatBox\Controllers;

use InvalidArgumentException;
use Pramnos\Http\Request;
use Pramnos\Http\Response;
use Pramnos\Routing\Attributes\Route;
use RadioChatBox\AdminAuth;
use RadioChatBox\Services\BlockService;
use Pramnos\Cache\FlatCache;
use RadioChatBox\Services\BotService;
use Pramnos\Broadcasting\BroadcastingManager;
use RadioChatBox\Services\ChatService;
use RadioChatBox\Http\Validate;
use Pramnos\Database\Database;
use RadioChatBox\Services\MessageFilter;
use RadioChatBox\MessageHistory;
use RadioChatBox\Services\PhotoService;
use RadioChatBox\Services\ReactionService;
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
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log($e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * POST /api/private/react — toggle a reaction on a DIRECT message. Same shape
     * as /api/react but the message_id is the numeric private_messages id; the
     * update is broadcast to both participants' private feed.
     */
    #[Route('/api/private/react', methods: 'POST', name: 'message-action.private.react')]
    public function reactPrivate(): Response
    {
        try {
            $input = $_POST;
            if (empty($input)) {
                throw new InvalidArgumentException('Invalid JSON');
            }
            $data = [
                'message_id' => trim((string) ($input['message_id'] ?? '')),
                'username'   => trim((string) ($input['username'] ?? '')),
                'session_id' => trim((string) ($input['session_id'] ?? '')),
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

            // Verify the caller owns this session (prevents reacting as others).
            if ((new ChatService())->getSessionInfo($data['username'], $data['session_id']) === null) {
                return Response::json(['error' => 'Invalid session'], 403);
            }

            $result = (new ReactionService())->toggleDmReaction(
                (int) $data['message_id'],
                $data['username'],
                $data['session_id'],
                (string) ($input['emoji'] ?? '')
            );

            return Response::json(['success' => true] + $result);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        } catch (RuntimeException $e) {
            return Response::json(['error' => $e->getMessage()], 404);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log($e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
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
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log($e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
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

            $db = Database::getInstance();

            // Fetch the original message and verify ownership + timing in one query
            // (timezone math + dollar-quoted literals — kept verbatim).
            $result = $db->preparedQuery(
                'SELECT m.message_id, m.username, m.created_at, m.is_deleted, s.user_id,
                        EXTRACT(EPOCH FROM (
                            NOW() - ((m.created_at AT TIME ZONE current_setting($$TIMEZONE$$)) AT TIME ZONE $$UTC$$)
                        )) AS age_seconds
                 FROM chat_messages m
                 INNER JOIN presence_sessions s ON s.username = m.username
                 WHERE m.message_id = :message_id
                     AND m.username   = :username
                     AND s.session_id = :session_id
                     AND m.is_deleted = false
                 LIMIT 1',
                [
                    'message_id' => $messageId,
                    'username'   => $username,
                    'session_id' => $sessionId,
                ]
            );
            $row = ($result && $result->numRows > 0) ? $result->fields : false;

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

            // Persist to PostgreSQL (edited_at = NOW() — kept verbatim).
            $db->preparedQuery(
                'UPDATE chat_messages
                 SET message = :message, edited_at = NOW()
                 WHERE message_id = :message_id',
                [
                    'message'    => $filtered,
                    'message_id' => $messageId,
                ]
            );

            // Invalidate the recent-history cache so getHistory() refetches from DB,
            // and update the reply-lookup hash with the edited text.
            $history = new MessageHistory();
            $history->clear();
            $history->updateReplyMessage($messageId, $filtered);

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
            BroadcastingManager::instance()->broadcast('chat:updates', 'message_edited', $editEvent);

            return Response::json([
                'success'   => true,
                'message'   => $filtered,
                'edited_at' => $editedAtIso,
                'timestamp' => $timestamp,
            ]);
        // @codeCoverageIgnoreStart
        } catch (\PDOException $e) {
            \Pramnos\Logs\Logger::log('Edit message DB error: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Database error'], 500);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
        // @codeCoverageIgnoreEnd
            \Pramnos\Logs\Logger::log('Edit message error: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Server error'], 500);
        }
        // @codeCoverageIgnoreEnd
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
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log($e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
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
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log($e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
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
            $db = Database::getInstance();

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
            $replyToId     = (isset($input['reply_to_id']) && $input['reply_to_id'] !== '')
                ? (int) $input['reply_to_id'] : null;

            // Message is optional if there's an attachment (cross-field rule).
            if (empty($message) && empty($attachmentId)) {
                throw new InvalidArgumentException('Either message or attachment is required');
            }

            // Get IP address for violation tracking
            $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';

            // A banned (IP/nickname) or kicked user cannot communicate with anyone —
            // enforce it here too, not only on the public chat send path.
            $banReason = (new ChatService())->communicationBlockReason(trim($fromUsername), $ipAddress, $fromSessionId);
            if ($banReason !== null) {
                return Response::json(['error' => $banReason], 403);
            }

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
            $result    = $db->preparedQuery(
                "SELECT session_id FROM presence_sessions WHERE username = ? ORDER BY last_heartbeat DESC LIMIT 1",
                [$toUsername]
            );
            $recipient = ($result && $result->numRows > 0) ? $result->fields : false;

            $isFakeUser = false;
            $fakeUserBotEnabled = false;
            if ($recipient) {
                $toSessionId = $recipient['session_id'];
            } else {
                // No live session. Check if it's an active fake user.
                $result   = $db->preparedQuery(
                    "SELECT nickname, bot_enabled FROM fake_users WHERE nickname = ? AND is_active = TRUE",
                    [$toUsername]
                );
                $fakeUser = ($result && $result->numRows > 0) ? $result->fields : false;

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
                    $result = $db->preparedQuery("
                        SELECT session_id FROM (
                            SELECT to_session_id AS session_id, created_at
                            FROM private_messages WHERE to_username = ?
                            UNION ALL
                            SELECT from_session_id AS session_id, created_at
                            FROM private_messages WHERE from_username = ?
                        ) recent
                        ORDER BY created_at DESC
                        LIMIT 1
                    ", [$toUsername, $toUsername]);
                    $recentSessionId = $result ? $result->fetchColumn() : false;

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

            $namesResult = $db->preparedQuery(
                "SELECT u.username, u.display_name FROM users u WHERE u.username IN (?, ?)",
                [$fromUsername, $toUsername]
            );
            if ($namesResult) {
                while ($row = $namesResult->fetch()) {
                    if ($row['username'] === $fromUsername) {
                        $fromDisplayName = $row['display_name'];
                    }
                    if ($row['username'] === $toUsername) {
                        $toDisplayName = $row['display_name'];
                    }
                }
            }

            // Resolve the replied-to message (snapshot for display). A bad/foreign
            // reference is dropped rather than failing the send.
            $replyData = null;
            if ($replyToId !== null) {
                $r  = $db->preparedQuery(
                    "SELECT id, from_username, from_display_name, message, attachment_id
                     FROM private_messages WHERE id = ?",
                    [$replyToId]
                );
                $rd = ($r && $r->numRows > 0) ? $r->fields : null;
                if ($rd !== null) {
                    $replyData = [
                        'id'                => (int) $rd['id'],
                        'from_username'     => $rd['from_username'],
                        'from_display_name' => $rd['from_display_name'],
                        'message'           => $rd['message'],
                        'has_attachment'    => !empty($rd['attachment_id']),
                    ];
                } else {
                    $replyToId = null;
                }
            }

            // Store message with session IDs and display_name snapshots
            $insert = $db->preparedQuery("
                INSERT INTO private_messages (from_username, from_session_id, from_display_name, to_username, to_session_id, to_display_name, message, attachment_id, reply_to_id, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                RETURNING id, created_at
            ", [$fromUsername, $fromSessionId, $fromDisplayName, $toUsername, $toSessionId, $toDisplayName, $message, $attachmentId, $replyToId]);
            $result = ($insert && $insert->numRows > 0) ? $insert->fields : null;

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
                'reply_to_id' => $replyToId,
                'reply_data' => $replyData,
                'timestamp' => strtotime($result['created_at']),
                'type' => 'private'
            ];

            BroadcastingManager::instance()->broadcast('chat:private_messages', 'private', $messageData);

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
                        // Stored function — kept verbatim via preparedQuery.
                        $notifResult = $db->preparedQuery(
                            "SELECT create_fake_user_dm_notification(?, ?, ?, ?)",
                            [
                                $fromUsername,
                                $toUsername,
                                $message ?: '[Photo attachment]',
                                $result['id'],
                            ]
                        );
                        $notificationId = $notifResult ? $notifResult->fetchColumn() : null;

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
                        BroadcastingManager::instance()->broadcast('chat:admin_notifications', 'fake_user_dm', $notificationData);
                    // @codeCoverageIgnoreStart
                    } catch (\Exception $e) {
                        // Log error but don't fail the message send
                        \Pramnos\Logs\Logger::log("Failed to create admin notification: " . $e->getMessage(), 'radiochatbox');
                    }
                    // @codeCoverageIgnoreEnd
                }

                // Schedule an automatic bot reply. This only queues a job (the LLM
                // call happens in bot:start), and it is a no-op unless the
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
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log($e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
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
            $db = Database::getInstance();

            $request   = Request::getInstance();
            $username  = $request->get('username', '', 'get');
            $sessionId = $request->get('session_id', '', 'get');
            $withUser  = $request->get('with_user', null, 'get');
            $adminMode = $request->get('admin', null, 'get') === 'true';

            // Admin mode returns ALL messages between two users, bypassing the
            // session isolation that scopes the normal read — so it must be gated
            // behind an authenticated admin who may already read private messages.
            // Without this, anyone could read any private conversation.
            if ($adminMode) {
                if (!AdminAuth::verify()) {
                    return Response::json(['error' => 'Unauthorized'], 401);
                }
                $adminUser = AdminAuth::getCurrentUser();
                if (!$adminUser || !in_array($adminUser['role'] ?? '', ['root', 'owner', 'administrator'], true)) {
                    return Response::json(['error' => 'Forbidden: private conversations require an administrator'], 403);
                }
            }

            if (empty($username) || empty($sessionId)) {
                throw new InvalidArgumentException('Username and session ID are required');
            }

            if ($withUser) {
                // Get conversation with specific user
                if ($adminMode) {
                    // Admin mode: Get ALL messages between these two users, ignoring session_id
                    $result = $db->preparedQuery("
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
                        ORDER BY pm.created_at DESC
                        LIMIT 500
                    ", [$username, $withUser, $withUser, $username]);
                } else {
                    // Check if talking to a fake user
                    $isFakeUser = $db->queryBuilder()
                        ->from('fake_users')
                        ->where('nickname', '=', $withUser)
                        ->exists();

                    if ($isFakeUser) {
                        // Fake users are persistent: show all messages ever sent to this fake user
                        // This allows users to see admin replies even if their session changed
                        $result = $db->preparedQuery("
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
                            ORDER BY pm.created_at DESC
                            LIMIT 500
                        ", [$username, $withUser, $withUser, $username]);
                    } else {
                        // Real users: only show messages from current session
                        // This prevents guests with the same username from seeing each other's messages
                        $result = $db->preparedQuery("
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
                            ORDER BY pm.created_at DESC
                            LIMIT 500
                        ", [$username, $sessionId, $withUser, $withUser, $username, $sessionId]);
                    }
                }
            } else {
                // Get all recent private messages for current session only
                $result = $db->preparedQuery("
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
                ", [$username, $sessionId, $username, $sessionId]);
            }

            $messages = $result ? $result->fetchAll() : [];

            // The conversation queries fetch the most RECENT 500 (ORDER BY created_at
            // DESC LIMIT 500) so long threads never hide new messages; the client
            // expects oldest-first, so flip them back to chronological order. (The
            // "recent messages" branch has no with_user and keeps DESC.)
            if ($withUser) {
                $messages = array_reverse($messages);
            }

            // Batch-load reply snapshots for any reply_to_id on this page (one query),
            // so replied-to messages render without a query per row.
            $replyMap = [];
            $replyIds = array_values(array_unique(array_filter(array_map(
                static fn ($m) => isset($m['reply_to_id']) ? (int) $m['reply_to_id'] : 0,
                $messages
            ))));
            if ($replyIds !== []) {
                $place = implode(',', array_fill(0, count($replyIds), '?'));
                $rr = $db->preparedQuery(
                    "SELECT id, from_username, from_display_name, message, attachment_id
                     FROM private_messages WHERE id IN ($place)",
                    $replyIds
                );
                foreach (($rr ? $rr->fetchAll() : []) as $rrow) {
                    $replyMap[(int) $rrow['id']] = [
                        'id'                => (int) $rrow['id'],
                        'from_username'     => $rrow['from_username'],
                        'from_display_name' => $rrow['from_display_name'],
                        'message'           => $rrow['message'],
                        'has_attachment'    => !empty($rrow['attachment_id']),
                    ];
                }
            }

            // Format attachment data for each message
            foreach ($messages as &$message) {
                $message['reply_data'] = (!empty($message['reply_to_id']))
                    ? ($replyMap[(int) $message['reply_to_id']] ?? null)
                    : null;
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

            // Attach DM reactions (their own table, keyed by the private_messages
            // id) so the conversation renders reaction pills like public chat does.
            $messages = (new ReactionService())->attachToMessages(
                $messages,
                $username,
                'private_message_reactions'
            );

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
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log($e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }
}
