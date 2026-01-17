<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use RadioChatBox\CorsHandler;
use RadioChatBox\ChatService;

// Handle CORS
CorsHandler::handle();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 100) : 50;
    $offset = isset($_GET['offset']) ? max((int)$_GET['offset'], 0) : 0;
    
    $chatService = new ChatService();
    
    // If offset is provided, fetch older messages
    if ($offset > 0) {
        $history = $chatService->getHistoryWithOffset($limit, $offset);
    } else {
        // Initial load - get most recent messages
        $history = $chatService->getHistory($limit);
    }
    
    echo json_encode([
        'success' => true,
        'messages' => $history
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
    error_log($e->getMessage());
}
