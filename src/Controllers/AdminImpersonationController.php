<?php

namespace RadioChatBox\Controllers;

use InvalidArgumentException;
use RadioChatBox\Http\Validate;
use Pramnos\Http\Request;
use Pramnos\Http\Response;
use Pramnos\Routing\Attributes\Route;
use RadioChatBox\AdminAuth;
use RadioChatBox\Services\BlockService;
use Pramnos\Broadcasting\BroadcastingManager;
use RadioChatBox\Services\BotService;
use Pramnos\Database\Database;
use RadioChatBox\Services\MessageFilter;
use RadioChatBox\Middleware\AdminAuthMiddleware;
use RadioChatBox\Services\PhotoService;

/**
 * Admin impersonation endpoints: an admin acts as a fake user inside a private
 * conversation. All four routes are restricted to authenticated admins
 * (AdminAuthMiddleware -> 401) AND, beyond mere authentication, to the
 * root/owner roles (403), exactly as the legacy files did.
 *
 * Migrated from:
 *   public/api/admin/impersonate-bot.php           -> bot()
 *   public/api/admin/impersonate-block.php         -> block()
 *   public/api/admin/impersonate-send.php          -> send()
 *   public/api/admin/impersonate-conversations.php -> conversations()
 */
final class AdminImpersonationController
{
    /**
     * Bot control for an impersonated conversation
     * (replaces public/api/admin/impersonate-bot.php).
     *
     * GET  ?fake_user=X&peer=Y       -> {success, state} | 404 {error} | 400 {error}
     * POST {fake_user, peer, action} -> {success, action, state} | 404/400 {error}
     *   action in take|release|reset|force (default "take").
     * Errors: bad input 400, unknown fake user 404, failure 500
     * {error:"Failed to update bot state"}.
     */
    #[Route('/api/admin/impersonate-bot', methods: ['GET', 'POST'], name: 'admin.impersonate.bot', middleware: [AdminAuthMiddleware::class])]
    public function bot(): Response
    {
        $currentUser = AdminAuth::getCurrentUser();
        if (!$currentUser || !in_array($currentUser['role'], ['root', 'owner'], true)) {
            return Response::json(['error' => 'Forbidden: Only root/owner can access impersonation'], 403);
        }

        try {
            $bot = new BotService();

            if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                $request  = Request::getInstance();
                $fakeUser = trim((string) $request->get('fake_user', '', 'get'));
                $peer     = trim((string) $request->get('peer', '', 'get'));

                if ($fakeUser === '' || $peer === '') {
                    return Response::json(['error' => 'fake_user and peer are required'], 400);
                }

                $state = $bot->getThreadState($fakeUser, $peer);

                if ($state === null) {
                    return Response::json(['error' => 'Fake user not found'], 404);
                }

                return Response::json(['success' => true, 'state' => $state]);
            }

            // POST
            $input    = $_POST;
            $fakeUser = trim((string) ($input['fake_user'] ?? ''));
            $peer     = trim((string) ($input['peer'] ?? ''));
            $action   = (string) ($input['action'] ?? 'take');

            if ($fakeUser === '' || $peer === '') {
                return Response::json(['error' => 'fake_user and peer are required'], 400);
            }

            $ok = match ($action) {
                'take'    => $bot->takeOverThread($fakeUser, $peer, (string) ($currentUser['username'] ?? '')),
                'release' => $bot->releaseThread($fakeUser, $peer, false),
                'reset'   => $bot->releaseThread($fakeUser, $peer, true),
                'force'   => $bot->forceReply($fakeUser, $peer),
                // Force-stop: silence the bot in this conversation only (reversible).
                'stop'    => $bot->stopThread($fakeUser, $peer),
                // Block: the fake user blocks the peer (mutual DM block). forcePermanent
                // because a fake user is not a registered account but its block must stick.
                // Stop the bot here too, so no reply is in flight.
                'block'   => (new BlockService())->blockUser($fakeUser, $peer, true) && $bot->stopThread($fakeUser, $peer),
                // Unblock: lift an abuse block (thread + DM block) without forcing a reply.
                'unblock' => $bot->unblockThread($fakeUser, $peer),
                default   => null,
            };

            if ($ok === null) {
                return Response::json(['error' => 'Unknown action (expected take, release, reset, force, stop, block or unblock)'], 400);
            }

            if (!$ok) {
                return Response::json(['error' => 'Fake user not found'], 404);
            }

            return Response::json([
                'success' => true,
                'action'  => $action,
                'state'   => $bot->getThreadState($fakeUser, $peer),
            ]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('impersonate-bot error: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Failed to update bot state'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Block state / block+unblock on a fake user's behalf
     * (replaces public/api/admin/impersonate-block.php).
     *
     * GET  ?impersonate_as=X&to_username=Y -> {success, i_blocked, is_blocked_between}
     * POST {action, impersonate_as, to_username} where action in block|unblock
     *   -> {success, action, blocked}
     * Errors: missing/invalid input 400 {error}, failure 500
     * {error:"Internal server error"}.
     */
    #[Route('/api/admin/impersonate-block', methods: ['GET', 'POST'], name: 'admin.impersonate.block', middleware: [AdminAuthMiddleware::class])]
    public function block(): Response
    {
        $currentUser = AdminAuth::getCurrentUser();
        if (!$currentUser || !in_array($currentUser['role'], ['root', 'owner'])) {
            return Response::json(['error' => 'Forbidden'], 403);
        }

        try {
            $blockService = new BlockService();

            if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                $request       = Request::getInstance();
                $impersonateAs = trim((string) $request->get('impersonate_as', '', 'get'));
                $toUsername    = trim((string) $request->get('to_username', '', 'get'));
                if ($impersonateAs === '' || $toUsername === '') {
                    throw new InvalidArgumentException('impersonate_as and to_username are required');
                }

                return Response::json([
                    'success'            => true,
                    'i_blocked'          => $blockService->hasBlocked($impersonateAs, $toUsername),
                    'is_blocked_between' => $blockService->isBlockedBetween($impersonateAs, $toUsername),
                ]);
            }

            // POST
            $input         = $_POST ?: [];
            $action        = $input['action'] ?? '';
            $impersonateAs = trim((string) ($input['impersonate_as'] ?? ''));
            $toUsername    = trim((string) ($input['to_username'] ?? ''));

            if ($impersonateAs === '' || $toUsername === '') {
                throw new InvalidArgumentException('impersonate_as and to_username are required');
            }

            if ($action === 'block') {
                // Force permanent: a fake user's block should not auto-expire.
                $blockService->blockUser($impersonateAs, $toUsername, true);
                return Response::json(['success' => true, 'action' => 'block', 'blocked' => true]);
            }

            if ($action === 'unblock') {
                $blockService->unblockUser($impersonateAs, $toUsername);
                return Response::json(['success' => true, 'action' => 'unblock', 'blocked' => false]);
            }

            throw new InvalidArgumentException('action must be "block" or "unblock"');
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
     * Send a private message as a fake user and silence the bot for that thread
     * (replaces public/api/admin/impersonate-send.php).
     *
     * POST {impersonate_as, to_username, message?, attachment_id?}. Message is
     * optional when an attachment is present. Inserts a private_messages row,
     * publishes to Redis for live delivery, and calls BotService::takeOverThread.
     * Success -> {success, message:"Message sent successfully", data}.
     * Errors: bad input / non-fake target / recipient offline 400 {error};
     * failure 500 {error:"Failed to send message", debug, trace}.
     */
    #[Route('/api/admin/impersonate-send', methods: 'POST', name: 'admin.impersonate.send', middleware: [AdminAuthMiddleware::class])]
    public function send(): Response
    {
        $currentUser = AdminAuth::getCurrentUser();
        if (!$currentUser || !in_array($currentUser['role'], ['root', 'owner'])) {
            return Response::json(['error' => 'Forbidden: Only root/owner can impersonate users'], 403);
        }

        try {
            $input = $_POST;

            $error = Validate::check($input, [
                'impersonate_as' => 'required',
                'to_username'    => 'required',
            ], [
                'impersonate_as.required' => 'Impersonate username and recipient are required',
                'to_username.required'    => 'Impersonate username and recipient are required',
            ]);
            if ($error) {
                return $error;
            }

            $impersonateAs = $input['impersonate_as'];
            $toUsername    = $input['to_username'];
            $message       = $input['message'] ?? '';
            $attachmentId  = $input['attachment_id'] ?? null;

            // Message is optional if there's an attachment (cross-field rule).
            if (empty($message) && empty($attachmentId)) {
                return Response::json(['error' => 'Either message or attachment is required'], 400);
            }

            $db = Database::getInstance();

            // Verify the impersonation target is a fake user
            $lookup   = $db->preparedQuery(
                "SELECT id, nickname FROM fake_users WHERE nickname = ? AND is_active = TRUE",
                [$impersonateAs]
            );
            $fakeUser = ($lookup && $lookup->numRows > 0) ? $lookup->fields : false;

            if (!$fakeUser) {
                return Response::json(['error' => 'Can only impersonate active fake users'], 400);
            }

            // Get recipient's session_id (most recent live session if multiple devices)
            $lookup    = $db->preparedQuery(
                "SELECT session_id FROM presence_sessions WHERE username = ? ORDER BY last_heartbeat DESC LIMIT 1",
                [$toUsername]
            );
            $recipient = ($lookup && $lookup->numRows > 0) ? $lookup->fields : false;

            if ($recipient) {
                $toSessionId = $recipient['session_id'];
            } else {
                // Grace period: recipient has no live session but may have just gone
                // offline (browser reload / unstable connection). Fall back to their most
                // recent known session id from previous messages so the DM reaches them
                // when they reconnect (same session id persists in localStorage). Mirrors
                // the logic in public/api/private-message.php.
                $lookup = $db->preparedQuery("
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
                $toSessionId = $lookup ? $lookup->fetchColumn() : false;

                if (!$toSessionId) {
                    return Response::json(['error' => 'Recipient is not online'], 400);
                }
            }

            // Filter message
            $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'admin';
            if (!empty($message)) {
                // Store raw text; renderers escape at output (avoids double-escaping).
                $filterResult = MessageFilter::filterPrivateMessage($message, $ipAddress);
                $message = trim($filterResult['filtered']);
            }

            // Create a fake session ID for the fake user (consistent per fake user)
            $fakeSessionId = 'fake_' . md5($impersonateAs);

            // Store message in database
            $insert = $db->preparedQuery("
                INSERT INTO private_messages (from_username, from_session_id, to_username, to_session_id, message, attachment_id, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
                RETURNING id, created_at
            ", [$impersonateAs, $fakeSessionId, $toUsername, $toSessionId, $message, $attachmentId]);
            $result = ($insert && $insert->numRows > 0) ? $insert->fields : null;

            // Get attachment info if present
            $attachmentData = null;
            if ($attachmentId) {
                $photoService = new PhotoService();
                $attachmentData = $photoService->getAttachment($attachmentId);
            }

            // Publish to Redis for real-time delivery
            $messageData = [
                'id'            => $result['id'],
                'from_username' => $impersonateAs,
                'to_username'   => $toUsername,
                'message'       => $message,
                'attachment'    => $attachmentData,
                'timestamp'     => strtotime($result['created_at']),
                'type'          => 'private',
            ];

            BroadcastingManager::instance()->broadcast('chat:private_messages', 'private', $messageData);

            // The admin is now speaking as this fake user: silence the bot for this
            // conversation, including any reply that is already queued or generated.
            try {
                (new BotService())->takeOverThread(
                    $impersonateAs,
                    $toUsername,
                    $currentUser['username'] ?? ''
                );
            // @codeCoverageIgnoreStart
            } catch (\Exception $e) {
                // Never fail the admin's message because of bot bookkeeping.
                \Pramnos\Logs\Logger::log('Failed to stop bot on impersonation: ' . $e->getMessage(), 'radiochatbox');
            }
            // @codeCoverageIgnoreEnd

            // Log impersonation for audit
            \Pramnos\Logs\Logger::log("IMPERSONATION: Admin {$currentUser['username']} sent message as {$impersonateAs} to {$toUsername}", 'radiochatbox');

            return Response::json([
                'success' => true,
                'message' => 'Message sent successfully',
                'data'    => $messageData,
            ]);
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Impersonate send error: ' . $e->getMessage(), 'radiochatbox');
            return Response::json([
                'error' => 'Failed to send message',
                'debug' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ], 500);
        }
    }

    /**
     * POST /api/admin/impersonate-typing — {impersonate_as, to_username,
     * is_typing?}. Broadcasts a DM "typing…" cue AS the fake user to the peer,
     * so the human sees the impersonated user composing (like a real DM).
     * Root/owner only. 200 {success}; bad input -> 400.
     */
    #[Route('/api/admin/impersonate-typing', methods: 'POST', name: 'admin.impersonate.typing', middleware: [AdminAuthMiddleware::class])]
    public function typing(): Response
    {
        $currentUser = AdminAuth::getCurrentUser();
        if (!$currentUser || !in_array($currentUser['role'], ['root', 'owner'])) {
            return Response::json(['error' => 'Forbidden'], 403);
        }

        $input = $_POST;
        $from = trim((string) ($input['impersonate_as'] ?? ''));
        $to = trim((string) ($input['to_username'] ?? ''));
        if ($from === '' || $to === '') {
            return Response::json(['error' => 'impersonate_as and to_username are required'], 400);
        }

        // Respect the DM typing toggle so it can't leak when the feature is off.
        if ((new \RadioChatBox\Services\SettingsService())->get('dm_typing_indicators_enabled', 'false') !== 'true') {
            return Response::json(['success' => true, 'skipped' => 'disabled']);
        }

        $isTyping = !array_key_exists('is_typing', $input)
            || in_array(strtolower((string) $input['is_typing']), ['1', 'true', 'on', 'yes'], true);

        try {
            BroadcastingManager::instance()->broadcast('chat:private_messages', 'private', [
                'type'          => 'typing',
                'from_username' => $from,
                'to_username'   => $to,
                'is_typing'     => $isTyping,
                'ttl_ms'        => 6000,
            ]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('impersonate typing broadcast failed: ' . $e->getMessage(), 'radiochatbox');
        }
        // @codeCoverageIgnoreEnd

        return Response::json(['success' => true]);
    }

    /**
     * POST /api/admin/bot-ask — {fake_user, peer, question}. Asks the LLM a
     * question ABOUT the conversation (not as the character) and returns the
     * answer to the admin. Nothing is stored or sent to the peer. Root/owner only.
     * 200 {success, answer} or {success:false, error}; bad input -> 400.
     */
    #[Route('/api/admin/bot-ask', methods: 'POST', name: 'admin.bot-ask', middleware: [AdminAuthMiddleware::class])]
    public function botAsk(): Response
    {
        $currentUser = AdminAuth::getCurrentUser();
        if (!$currentUser || !in_array($currentUser['role'], ['root', 'owner'])) {
            return Response::json(['error' => 'Forbidden'], 403);
        }

        try {
            $input = $_POST;
            $fake = trim((string) ($input['fake_user'] ?? ''));
            $peer = trim((string) ($input['peer'] ?? ''));
            $question = trim((string) ($input['question'] ?? ''));
            if ($fake === '' || $peer === '' || $question === '') {
                return Response::json(['error' => 'fake_user, peer and question are required'], 400);
            }

            $result = (new BotService())->answerAdminQuestion($fake, $peer, $question);
            if (($result['answer'] ?? '') === '') {
                return Response::json(['success' => false, 'error' => $result['error'] ?? 'No answer'], 200);
            }
            return Response::json(['success' => true, 'answer' => $result['answer']]);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('bot-ask error: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * POST /api/admin/bot-steer — {fake_user, peer, directive}. Attaches a
     * steering directive to the thread (empty clears it). A non-empty directive
     * also forces the bot to reply now so it takes effect immediately. Root/owner
     * only. 200 {success, directive, replied}; bad input -> 400.
     */
    #[Route('/api/admin/bot-steer', methods: 'POST', name: 'admin.bot-steer', middleware: [AdminAuthMiddleware::class])]
    public function botSteer(): Response
    {
        $currentUser = AdminAuth::getCurrentUser();
        if (!$currentUser || !in_array($currentUser['role'], ['root', 'owner'])) {
            return Response::json(['error' => 'Forbidden'], 403);
        }

        try {
            $input = $_POST;
            $fake = trim((string) ($input['fake_user'] ?? ''));
            $peer = trim((string) ($input['peer'] ?? ''));
            $directive = (string) ($input['directive'] ?? '');
            if ($fake === '' || $peer === '') {
                return Response::json(['error' => 'fake_user and peer are required'], 400);
            }

            $bot = new BotService();
            if (!$bot->setThreadDirective($fake, $peer, $directive)) {
                return Response::json(['error' => 'Unknown fake user'], 400);
            }

            // A new directive takes effect on the next reply — force one now so the
            // admin sees the steer immediately (clearing does not force a reply).
            $replied = false;
            if (trim($directive) !== '') {
                $replied = $bot->forceReply($fake, $peer);
            }

            return Response::json([
                'success'   => true,
                'directive' => $bot->getThreadDirective($fake, $peer),
                'replied'   => $replied,
                // Warn if the directive is saved but no reply will actually appear
                // (bots off / no LLM key), so the admin isn't left wondering.
                'warning'   => $replied ? $bot->replyBlockReason($fake) : null,
            ]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('bot-steer error: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * List every active fake user's inbound private conversations
     * (replaces public/api/admin/impersonate-conversations.php).
     *
     * GET -> {success, fake_users:[nickname...], conversations:{nickname:{fake_user,
     * age, sex, location, total_messages, senders[], recent_messages[],
     * last_message_time}}}, ordered by most recent message.
     * Failure -> 500 {error:"Failed to fetch conversations"}.
     */
    #[Route('/api/admin/impersonate-conversations', methods: 'GET', name: 'admin.impersonate.conversations', middleware: [AdminAuthMiddleware::class])]
    public function conversations(): Response
    {
        $currentUser = AdminAuth::getCurrentUser();
        if (!$currentUser || !in_array($currentUser['role'], ['root', 'owner'])) {
            return Response::json(['error' => 'Forbidden: Only root/owner can access impersonation'], 403);
        }

        try {
            $db = Database::getInstance();

            // Get all active fake users with their profile info
            $result = $db->preparedQuery("SELECT id, nickname, age, sex, location FROM fake_users WHERE is_active = TRUE ORDER BY nickname");
            $fakeUsers = $result ? $result->fetchAll() : [];

            // For each fake user, get recent messages TO them
            $conversations = [];

            foreach ($fakeUsers as $fakeUser) {
                // Window functions (COUNT/MAX OVER) — kept verbatim via preparedQuery.
                $result = $db->preparedQuery("
                    SELECT
                        pm.*,
                        COUNT(*) OVER() as total_messages,
                        MAX(pm.created_at) OVER() as last_message_time
                    FROM private_messages pm
                    WHERE pm.to_username = ?
                    ORDER BY pm.created_at DESC
                    LIMIT 50
                ", [$fakeUser['nickname']]);
                $messages = $result ? $result->fetchAll() : [];

                if (!empty($messages)) {
                    // Get unique senders with their last message timestamp and online status
                    $senders = [];
                    foreach ($messages as $msg) {
                        if (!isset($senders[$msg['from_username']])) {
                            // Check if sender is online (has active session)
                            $isOnline = $db->queryBuilder()
                                ->from('presence_sessions')
                                ->where('username', '=', $msg['from_username'])
                                ->exists();

                            $senders[$msg['from_username']] = [
                                'username'          => $msg['from_username'],
                                'last_message'      => $msg['message'],
                                'last_message_time' => $msg['created_at'],
                                'unread_count'      => 0,
                                'is_online'         => $isOnline,
                            ];
                        }
                        $senders[$msg['from_username']]['unread_count']++;
                    }

                    // Sort senders by: is_online DESC (online first), then by last_message_time DESC (newest first)
                    $sendersArray = array_values($senders);
                    usort($sendersArray, function ($a, $b) {
                        // Online status first
                        if ($a['is_online'] !== $b['is_online']) {
                            return $b['is_online'] ? 1 : -1;
                        }
                        // Then by date (newest first)
                        return strtotime($b['last_message_time']) - strtotime($a['last_message_time']);
                    });

                    // Get latest message timestamp for sorting fake users
                    $lastMessageTime = reset($messages)['created_at'];

                    $conversations[$fakeUser['nickname']] = [
                        'fake_user'         => $fakeUser['nickname'],
                        'age'               => $fakeUser['age'],
                        'sex'               => $fakeUser['sex'],
                        'location'          => $fakeUser['location'],
                        'total_messages'    => count($messages),
                        'senders'           => $sendersArray,
                        'recent_messages'   => array_slice($messages, 0, 10),
                        'last_message_time' => $lastMessageTime,
                    ];
                }
            }

            // Sort fake users by: most recent message first
            $fakeUsersList = array_values($conversations);
            usort($fakeUsersList, function ($a, $b) {
                return strtotime($b['last_message_time']) - strtotime($a['last_message_time']);
            });

            return Response::json([
                'success'    => true,
                'fake_users' => array_map(fn($u) => $u['fake_user'], $fakeUsersList),
                'conversations' => array_reduce($conversations, function ($carry, $item) {
                    $carry[$item['fake_user']] = $item;
                    return $carry;
                }, []),
            ]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Impersonate conversations error: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Failed to fetch conversations'], 500);
        }
        // @codeCoverageIgnoreEnd
    }
}
