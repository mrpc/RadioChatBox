<?php

require_once __DIR__ . '/../../../vendor/autoload.php';

use RadioChatBox\AdminAuth;
use RadioChatBox\CorsHandler;
use RadioChatBox\BlockService;

header('Content-Type: application/json');

CorsHandler::handle();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Require admin authentication
if (!AdminAuth::verify()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Only root/owner can impersonate (and therefore block on a fake user's behalf)
$currentUser = AdminAuth::getCurrentUser();
if (!$currentUser || !in_array($currentUser['role'], ['root', 'owner'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

try {
    $blockService = new BlockService();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Block state between a fake user (blocker) and a real user (blocked).
        $impersonateAs = trim($_GET['impersonate_as'] ?? '');
        $toUsername = trim($_GET['to_username'] ?? '');
        if ($impersonateAs === '' || $toUsername === '') {
            throw new InvalidArgumentException('impersonate_as and to_username are required');
        }
        echo json_encode([
            'success' => true,
            'i_blocked' => $blockService->hasBlocked($impersonateAs, $toUsername),
            'is_blocked_between' => $blockService->isBlockedBetween($impersonateAs, $toUsername),
        ]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $action = $input['action'] ?? '';
        $impersonateAs = trim($input['impersonate_as'] ?? '');
        $toUsername = trim($input['to_username'] ?? '');

        if ($impersonateAs === '' || $toUsername === '') {
            throw new InvalidArgumentException('impersonate_as and to_username are required');
        }

        if ($action === 'block') {
            // Force permanent: a fake user's block should not auto-expire.
            $blockService->blockUser($impersonateAs, $toUsername, true);
            echo json_encode(['success' => true, 'action' => 'block', 'blocked' => true]);
        } elseif ($action === 'unblock') {
            $blockService->unblockUser($impersonateAs, $toUsername);
            echo json_encode(['success' => true, 'action' => 'unblock', 'blocked' => false]);
        } else {
            throw new InvalidArgumentException('action must be "block" or "unblock"');
        }
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);

} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
    error_log($e->getMessage());
}
