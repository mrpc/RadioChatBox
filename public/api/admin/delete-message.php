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
    
    // Delete the message
    $stmt = $pdo->prepare("DELETE FROM messages WHERE id = ?");
    $stmt->execute([$messageId]);
    
    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Message not found']);
        exit;
    }

    // Publish deletion to Redis for real-time update
    $redis = Database::getRedis();
    $prefix = Database::getRedisPrefix();
    
    $deleteEvent = [
        'type' => 'message_deleted',
        'message_id' => $messageId,
        'timestamp' => time()
    ];
    
    $redis->publish($prefix . 'chat:updates', json_encode($deleteEvent));

    // Also remove from Redis cache if present
    $redis->lRem($prefix . 'chat:messages', $messageId, 0);

    echo json_encode([
        'success' => true,
        'message' => 'Message deleted successfully'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to delete message']);
    error_log('Delete message error: ' . $e->getMessage());
}
