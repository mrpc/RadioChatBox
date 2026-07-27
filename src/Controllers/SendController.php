<?php

namespace RadioChatBox\Controllers;

use InvalidArgumentException;
use Pramnos\Http\Response;
use Pramnos\Routing\Attributes\Route;
use RadioChatBox\ChatService;
use RadioChatBox\MessageFilter;
use RuntimeException;

/**
 * POST /api/send — post a public chat message.
 *
 * Migrated from public/api/send.php. The framework Request has already decoded
 * the JSON body into $_POST, so we read it there (re-reading php://input would
 * come back empty). Behaviour is preserved exactly: empty/invalid body -> 400,
 * rate-limit / public-chat-disabled -> 429, otherwise 500; success returns
 * {success, message}.
 */
final class SendController
{
    #[Route('/api/send', methods: 'POST', name: 'send.store')]
    public function store(): Response
    {
        try {
            // The framework Request populates $_POST from the JSON body.
            $input = $_POST;

            if (empty($input)) {
                throw new InvalidArgumentException('Invalid JSON');
            }

            $username    = $input['username'] ?? '';
            $message     = $input['message'] ?? '';
            $sessionId   = $input['sessionId'] ?? '';
            $replyTo     = $input['replyTo'] ?? null;
            $pinnedTrack = $input['pinned_track'] ?? null;

            $chatService = new ChatService();
            $chatMode    = $chatService->getSetting('chat_mode') ?? 'both';

            if ($chatMode === 'private') {
                throw new RuntimeException('Public chat is disabled. Please use private messages.');
            }

            $message = MessageFilter::filterPublicMessage($message)['filtered'];

            $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';

            $result = $chatService->postMessage($username, $message, $ipAddress, $sessionId, $replyTo, $pinnedTrack);

            return Response::json(['success' => true, 'message' => $result]);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        } catch (RuntimeException $e) {
            return Response::json(['error' => $e->getMessage()], 429);
        } catch (\Throwable $e) {
            \RadioChatBox\Log::write($e->getMessage());
            return Response::json(['error' => 'Internal server error'], 500);
        }
    }
}
