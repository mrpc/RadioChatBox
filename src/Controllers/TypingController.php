<?php

namespace RadioChatBox\Controllers;

use Pramnos\Broadcasting\BroadcastingManager;
use Pramnos\Http\Response;
use Pramnos\Routing\Attributes\Route;
use RadioChatBox\Services\ChatService;
use RadioChatBox\Services\SettingsService;

/**
 * "User is typing…" indicator for public chat. The client pings this endpoint
 * (throttled) while composing; the server verifies the session and broadcasts a
 * lightweight, ephemeral 'typing' cue to the other clients. Nothing is stored.
 * Gated by typing_indicators_enabled.
 */
class TypingController
{
    /**
     * POST /api/typing — {username, session_id, is_typing?}. Broadcasts a typing
     * cue. 200 {success}; feature off -> 404; bad input -> 400; invalid session
     * -> 403.
     */
    #[Route('/api/typing', methods: 'POST', name: 'typing.ping')]
    public function ping(): Response
    {
        try {
            if (!$this->isEnabled()) {
                return Response::json(['success' => false, 'error' => 'Disabled'], 404);
            }

            $input = $_POST;
            $username = trim((string) ($input['username'] ?? ''));
            $sessionId = trim((string) ($input['session_id'] ?? ''));
            $isTyping = !array_key_exists('is_typing', $input)
                || in_array(strtolower((string) $input['is_typing']), ['1', 'true', 'on', 'yes'], true);

            if ($username === '' || $sessionId === '') {
                return Response::json(['error' => 'username and session_id are required'], 400);
            }

            // Verify the caller owns this session (no typing as someone else).
            if ((new ChatService())->getSessionInfo($username, $sessionId) === null) {
                return Response::json(['error' => 'Invalid session'], 403);
            }

            try {
                BroadcastingManager::instance()->broadcast('chat:updates', 'typing', [
                    'type'      => 'typing',
                    'username'  => $username,
                    'is_typing' => $isTyping,
                ]);
            // @codeCoverageIgnoreStart
            } catch (\Throwable $e) {
                \Pramnos\Logs\Logger::log('typing broadcast failed: ' . $e->getMessage(), 'radiochatbox');
            }
            // @codeCoverageIgnoreEnd

            return Response::json(['success' => true]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('TypingController::ping failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    private function isEnabled(): bool
    {
        $value = (new SettingsService())->get('typing_indicators_enabled');
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes'], true);
    }
}
