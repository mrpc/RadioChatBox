<?php

namespace RadioChatBox\Controllers;

use Pramnos\Http\Request;
use Pramnos\Http\Response;
use Pramnos\Routing\Attributes\Route;
use RadioChatBox\Services\ChatService;
use RadioChatBox\Services\NotificationService;

/**
 * Per-user notification inbox: list a user's notifications (+ unread count) and
 * mark them read. Session-verified so nobody can read another user's inbox.
 */
final class NotificationController
{
    /**
     * GET /api/notifications?username=&session_id=&unread_only= — the user's
     * notifications and unread count. 200 {success, notifications, unread_count};
     * bad input -> 400; bad session -> 403.
     */
    #[Route('/api/notifications', methods: 'GET', name: 'notifications.list')]
    public function list(): Response
    {
        try {
            $request = Request::getInstance();
            $username = trim((string) $request->get('username', '', 'get'));
            $sessionId = trim((string) $request->get('session_id', '', 'get'));
            $unreadOnly = in_array(strtolower((string) $request->get('unread_only', '', 'get')), ['1', 'true', 'yes'], true);

            if ($username === '' || $sessionId === '') {
                return Response::json(['error' => 'username and session_id are required'], 400);
            }
            if ((new ChatService())->getSessionInfo($username, $sessionId) === null) {
                return Response::json(['error' => 'Invalid session'], 403);
            }

            $service = new NotificationService();
            return Response::json([
                'success'       => true,
                'notifications' => $service->listFor($username, 30, $unreadOnly),
                'unread_count'  => $service->unreadCount($username),
            ]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('NotificationController::list failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * POST /api/notifications/read — {username, session_id, id?}. Marks one (id) or
     * all of the user's notifications read. 200 {success, marked}; bad input ->
     * 400; bad session -> 403.
     */
    #[Route('/api/notifications/read', methods: 'POST', name: 'notifications.read')]
    public function markRead(): Response
    {
        try {
            $input = $_POST;
            $username = trim((string) ($input['username'] ?? ''));
            $sessionId = trim((string) ($input['session_id'] ?? ''));
            $id = isset($input['id']) && $input['id'] !== '' ? (int) $input['id'] : null;

            if ($username === '' || $sessionId === '') {
                return Response::json(['error' => 'username and session_id are required'], 400);
            }
            if ((new ChatService())->getSessionInfo($username, $sessionId) === null) {
                return Response::json(['error' => 'Invalid session'], 403);
            }

            $marked = (new NotificationService())->markRead($username, $id);
            return Response::json(['success' => true, 'marked' => $marked]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('NotificationController::markRead failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }
}
