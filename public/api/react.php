<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use RadioChatBox\CorsHandler;
use RadioChatBox\ReactionService;
use RadioChatBox\ChatService;

header('Content-Type: application/json');

CorsHandler::handle();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

try {
    $reactionService = new ReactionService();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            throw new InvalidArgumentException('Invalid JSON');
        }

        $messageId = trim($input['message_id'] ?? '');
        $username = trim($input['username'] ?? '');
        $sessionId = trim($input['session_id'] ?? '');
        $emoji = $input['emoji'] ?? '';

        if ($messageId === '' || $username === '' || $sessionId === '') {
            throw new InvalidArgumentException('message_id, username and session_id are required');
        }

        // Verify the caller owns this session (prevents spoofing reactions as others).
        $chatService = new ChatService();
        if ($chatService->getSessionInfo($username, $sessionId) === null) {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid session']);
            exit;
        }

        $result = $reactionService->toggleReaction($messageId, $username, $sessionId, $emoji);
        echo json_encode(['success' => true] + $result);

    } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Return the allowed emoji set (for the client to render the picker).
        echo json_encode([
            'success' => true,
            'allowed' => ReactionService::getAllowedEmojis(),
        ]);

    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }

} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (RuntimeException $e) {
    http_response_code(404);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
    error_log($e->getMessage());
}
