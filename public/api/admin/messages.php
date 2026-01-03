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
    $currentUser = AdminAuth::getCurrentUser();
    
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 500) : 100;
    $offset = ($page - 1) * $limit;
    $type = isset($_GET['type']) ? $_GET['type'] : 'all'; // all, public, private
    
    // Root admins can see both public and private messages
    $includePrivate = $currentUser && $currentUser['role'] === 'root';
    
    // Get chat mode to determine if both message types are available
    $chatMode = $chatService->getSetting('chat_mode') ?? 'both';
    
    $messages = $chatService->getAllMessages($limit, $offset, $includePrivate, $type);
    $total = $chatService->getTotalMessagesCount($includePrivate, $type);
    $totalPages = ceil($total / $limit);
    
    echo json_encode([
        'success' => true,
        'messages' => $messages,
        'include_private' => $includePrivate,
        'chat_mode' => $chatMode,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => $totalPages
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
    error_log($e->getMessage());
}
