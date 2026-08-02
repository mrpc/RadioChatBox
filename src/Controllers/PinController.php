<?php

namespace RadioChatBox\Controllers;

use InvalidArgumentException;
use Pramnos\Broadcasting\BroadcastingManager;
use Pramnos\Http\Response;
use Pramnos\Routing\Attributes\Route;
use RadioChatBox\AdminAuth;
use RadioChatBox\Middleware\AdminAuthMiddleware;
use RadioChatBox\Services\PinService;

/**
 * Pinned messages: a public endpoint returning the active pins (for the chat's
 * pinned bar) and admin endpoints to pin / unpin a message. A change broadcasts
 * a lightweight 'pins_changed' cue on the chat channel so live clients refresh.
 */
class PinController
{
    /**
     * GET /api/pinned-messages — the currently-active pins, newest first.
     * Public (pins are shown to everyone). 200 {success, pins:[…]}.
     */
    #[Route('/api/pinned-messages', methods: 'GET', name: 'pins.list')]
    public function list(): Response
    {
        try {
            $pins = (new PinService())->active(10);
            return Response::json(['success' => true, 'pins' => $pins])
                ->withHeader('Cache-Control', 'no-cache');
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('PinController::list failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * POST /api/admin/pin-message — {message_id, content, username?,
     * expires_minutes?}. Pins a message. 200 {success, id}; bad input -> 400.
     */
    #[Route('/api/admin/pin-message', methods: 'POST', name: 'admin.pin-message', middleware: [AdminAuthMiddleware::class])]
    public function pin(): Response
    {
        try {
            $input = $_POST;
            $messageId = (string) ($input['message_id'] ?? '');
            $content = (string) ($input['content'] ?? '');
            $username = isset($input['username']) ? (string) $input['username'] : null;
            $expires = isset($input['expires_minutes']) && $input['expires_minutes'] !== ''
                ? (int) $input['expires_minutes'] : null;

            $admin = AdminAuth::getCurrentUser();
            $adminName = (string) ($admin['username'] ?? 'admin');

            $id = (new PinService())->pin($messageId, $content, $username, $adminName, $expires);
            $this->broadcastChange();

            return Response::json(['success' => true, 'id' => $id]);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('PinController::pin failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * POST /api/admin/unpin-message — {id} or {message_id}. Removes a pin.
     * 200 {success}; bad input -> 400.
     */
    #[Route('/api/admin/unpin-message', methods: 'POST', name: 'admin.unpin-message', middleware: [AdminAuthMiddleware::class])]
    public function unpin(): Response
    {
        try {
            $input = $_POST;
            $service = new PinService();

            if (isset($input['id']) && (int) $input['id'] > 0) {
                $service->unpin((int) $input['id']);
            } elseif (isset($input['message_id']) && trim((string) $input['message_id']) !== '') {
                $service->unpinByMessage((string) $input['message_id']);
            } else {
                return Response::json(['error' => 'id or message_id is required'], 400);
            }

            $this->broadcastChange();
            return Response::json(['success' => true]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('PinController::unpin failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /** Tell live clients to refresh their pinned bar (no polling). */
    private function broadcastChange(): void
    {
        try {
            BroadcastingManager::instance()->broadcast('chat:updates', 'pins_changed', ['type' => 'pins_changed']);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('pins_changed broadcast failed: ' . $e->getMessage(), 'radiochatbox');
        }
        // @codeCoverageIgnoreEnd
    }
}
