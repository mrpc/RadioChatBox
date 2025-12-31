<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use RadioChatBox\CorsHandler;
use RadioChatBox\ChatService;

// Handle CORS
CorsHandler::handle();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new InvalidArgumentException('Invalid JSON');
    }

    $username = $input['username'] ?? '';
    $sessionId = $input['sessionId'] ?? '';
    
    if (empty($username) || empty($sessionId)) {
        throw new InvalidArgumentException('Username and session ID are required');
    }
    
    $chatService = new ChatService();
    
    // Balance fake users first
    $chatService->balanceFakeUsers();
    
    // Then update heartbeat (this will publish user update with correct count)
    $chatService->updateHeartbeat($username, $sessionId);
    
    // Get session info to return user_id and role for validation
    $sessionInfo = $chatService->getSessionInfo($username, $sessionId);
    
    echo json_encode([
        'success' => true,
        'activeUsers' => count($chatService->getAllUsers()),
        'user_id' => $sessionInfo['user_id'] ?? null,
        'user_role' => $sessionInfo['user_role'] ?? null
    ]);

} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
    error_log($e->getMessage());
}
