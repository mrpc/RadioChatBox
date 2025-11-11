<?php

require_once __DIR__ . '/../../../vendor/autoload.php';

use RadioChatBox\CorsHandler;
use RadioChatBox\ChatService;
use RadioChatBox\AdminAuth;

// Handle CORS
CorsHandler::handle();

header('Content-Type: application/json');

// Check authentication
if (!AdminAuth::verify()) {
    AdminAuth::unauthorized();
}

try {
    $chatService = new ChatService();
    
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Get all banned nicknames
        $bannedNicknames = $chatService->getBannedNicknames();
        echo json_encode([
            'success' => true,
            'banned_nicknames' => $bannedNicknames
        ]);
        
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Ban a nickname
        $input = json_decode(file_get_contents('php://input'), true);
        
        $nickname = $input['nickname'] ?? '';
        $reason = $input['reason'] ?? '';
        
        if (empty($nickname)) {
            throw new InvalidArgumentException('Nickname is required');
        }
        
        $success = $chatService->banNickname($nickname, $reason, 'admin');
        
        echo json_encode([
            'success' => $success,
            'message' => $success ? 'Nickname banned successfully' : 'Failed to ban nickname'
        ]);
        
    } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        // Unban a nickname
        $input = json_decode(file_get_contents('php://input'), true);
        
        $nickname = $input['nickname'] ?? '';
        
        if (empty($nickname)) {
            throw new InvalidArgumentException('Nickname is required');
        }
        
        $success = $chatService->unbanNickname($nickname);
        
        echo json_encode([
            'success' => $success,
            'message' => $success ? 'Nickname unbanned successfully' : 'Failed to unban nickname'
        ]);
        
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
