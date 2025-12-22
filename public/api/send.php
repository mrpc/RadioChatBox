<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use RadioChatBox\CorsHandler;
use RadioChatBox\ChatService;
use RadioChatBox\MessageFilter;

// Handle CORS
CorsHandler::handle();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    // Get POST data
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new InvalidArgumentException('Invalid JSON');
    }

    $username = $input['username'] ?? '';
    $message = $input['message'] ?? '';
    $sessionId = $input['sessionId'] ?? '';
    $replyTo = $input['replyTo'] ?? null;
    
    // Check if public chat is allowed
    $chatService = new ChatService();
    $chatMode = $chatService->getSetting('chat_mode') ?? 'both';
    
    if ($chatMode === 'private') {
        throw new RuntimeException('Public chat is disabled. Please use private messages.');
    }
    
    // Filter message for public chat
    $filterResult = MessageFilter::filterPublicMessage($message);
    
    // Use the filtered message (with replacements)
    $message = $filterResult['filtered'];
    
    // Get client IP
    $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    $result = $chatService->postMessage($username, $message, $ipAddress, $sessionId, $replyTo);
    
    echo json_encode([
        'success' => true,
        'message' => $result
    ]);

} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (RuntimeException $e) {
    http_response_code(429);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
    error_log($e->getMessage());
}
