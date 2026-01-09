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

    // PERFORMANCE OPTIMIZATION: Mark as deleted in Redis HASH instead of clearing entire cache
    // This allows getHistory() to filter without querying the database
    $redis->hSet($prefix . 'chat:deleted_messages', $messageId, '1');
    // Set expiry on the hash key to match message cache TTL (24 hours)
    $redis->expire($prefix . 'chat:deleted_messages', 86400);

    // Also clear the message cache to force refresh (keeps behavior consistent)
    // Consider removing this in the future once Redis HASH filtering is fully tested
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
