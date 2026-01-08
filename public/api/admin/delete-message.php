<?php

require_once __DIR__ . '/../../../vendor/autoload.php';

use RadioChatBox\AdminAuth;
use RadioChatBox\CorsHandler;
use RadioChatBox\Database;

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

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $messageId = $input['message_id'] ?? null;

    if (!$messageId) {
        http_response_code(400);
        echo json_encode(['error' => 'Message ID is required']);
        exit;
    }

    $pdo = Database::getPDO();
    
    // Mark the message as deleted (soft delete) instead of actually deleting it
    $stmt = $pdo->prepare("UPDATE messages SET is_deleted = true WHERE message_id = ?");
    $stmt->execute([$messageId]);
    
    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Message not found']);
        exit;
    }

    // Publish deletion to Redis for real-time update
    $redis = Database::getRedis();
    $prefix = Database::getRedisPrefix();
    
    // Clear entire message cache instead of trying to find exact JSON match
    // This prevents stale data from JSON encoding mismatches (whitespace, property order)
    // The cache will be repopulated from database on next getHistory() call
    $redis->del($prefix . 'chat:messages');
    
    $deleteEvent = [
        'type' => 'message_deleted',
        'message_id' => $messageId,
        'timestamp' => time()
    ];
    
    $redis->publish($prefix . 'chat:updates', json_encode($deleteEvent));

    echo json_encode([
        'success' => true,
        'message' => 'Message deleted successfully'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to delete message',
        'debug' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    error_log('Delete message error: ' . $e->getMessage());
}
