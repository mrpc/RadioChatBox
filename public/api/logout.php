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

    $sessionId = $input['sessionId'] ?? '';
    
    if (empty($sessionId)) {
        throw new InvalidArgumentException('Session ID is required');
    }
    
    $chatService = new ChatService();
    $success = $chatService->logoutUser($sessionId);
    
    if ($success) {
        echo json_encode([
            'success' => true,
            'message' => 'User logged out successfully'
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to logout user']);
    }

} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
    error_log($e->getMessage());
}
