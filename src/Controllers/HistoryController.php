<?php

namespace RadioChatBox\Controllers;

use Pramnos\Http\Request;
use Pramnos\Http\Response;
use Pramnos\Routing\Attributes\Route;
use RadioChatBox\ChatService;
use RadioChatBox\ReactionService;

/**
 * GET /api/history — recent public chat messages (with emoji reactions attached),
 * paginated via ?limit/?offset and made viewer-aware via ?username.
 *
 * Migrated from public/api/history.php onto the framework HTTP path, preserving
 * the exact {success, messages} payload and the limit(<=100)/offset semantics.
 */
final class HistoryController
{
    #[Route('/api/history', methods: 'GET', name: 'history.index')]
    public function index(): Response
    {
        try {
            $request  = Request::getInstance();
            $limit    = min((int) $request->get('limit', 50, 'get'), 100);
            $offset   = max((int) $request->get('offset', 0, 'get'), 0);
            $username = $request->get('username', null, 'get');
            $username = $username !== null ? trim((string) $username) : null;

            $chat = new ChatService();
            $history = $offset > 0
                ? $chat->getHistoryWithOffset($limit, $offset)
                : $chat->getHistory($limit);

            // Attach emoji reactions (per-viewer "mine" flags when username is known).
            $history = (new ReactionService())->attachToMessages($history, $username);

            return Response::json([
                'success'  => true,
                'messages' => $history,
            ]);
        } catch (\Throwable $e) {
            \RadioChatBox\Log::write($e->getMessage());
            return Response::json(['error' => 'Internal server error'], 500);
        }
    }
}
