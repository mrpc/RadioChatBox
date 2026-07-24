<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use RadioChatBox\CorsHandler;
use RadioChatBox\BlockService;
use RadioChatBox\ChatService;

header('Content-Type: application/json');

CorsHandler::handle();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

try {
    $blockService = new BlockService();
    $chatService = new ChatService();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            throw new InvalidArgumentException('Invalid JSON');
        }

        $action = $input['action'] ?? '';
        $username = trim($input['username'] ?? '');
        $sessionId = trim($input['session_id'] ?? '');
        $targetUsername = trim($input['target_username'] ?? '');

        if ($username === '' || $sessionId === '' || $targetUsername === '') {
            throw new InvalidArgumentException('username, session_id and target_username are required');
        }

        // Verify the caller actually owns this session (prevents trivial spoofing).
        if ($chatService->getSessionInfo($username, $sessionId) === null) {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid session']);
            exit;
        }

        if ($action === 'block') {
            $blockService->blockUser($username, $targetUsername);
            echo json_encode([
                'success' => true,
                'action' => 'block',
                'target_username' => $targetUsername,
                'blocked' => true,
            ]);
        } elseif ($action === 'unblock') {
            $blockService->unblockUser($username, $targetUsername);
            echo json_encode([
                'success' => true,
                'action' => 'unblock',
                'target_username' => $targetUsername,
                'blocked' => false,
            ]);
        } else {
            throw new InvalidArgumentException('action must be "block" or "unblock"');
        }

    } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $username = trim($_GET['username'] ?? '');
        $withUser = trim($_GET['with_user'] ?? '');

        if ($username === '') {
            throw new InvalidArgumentException('username is required');
        }

        $response = [
            'success' => true,
            'blocked_users' => $blockService->getBlockedUsers($username),
        ];

        // Optional per-conversation state for rendering the Block/Unblock button.
        if ($withUser !== '') {
            $response['with_user'] = $withUser;
            $response['i_blocked'] = $blockService->hasBlocked($username, $withUser);
            $response['is_blocked_between'] = $blockService->isBlockedBetween($username, $withUser);
        }

        echo json_encode($response);

    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }

} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
    error_log($e->getMessage());
}
