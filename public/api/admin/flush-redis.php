<?php
require_once __DIR__ . '/../../../vendor/autoload.php';

use RadioChatBox\CorsHandler;
use RadioChatBox\AdminAuth;
use RadioChatBox\Database;

header('Content-Type: application/json');

CorsHandler::handle();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Authenticate admin
if (!AdminAuth::authenticate()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $redis = Database::getRedis();
        $prefix = Database::getRedisPrefix();
        
        // Get all keys with this database prefix
        $pattern = $prefix . '*';
        $keys = $redis->keys($pattern);
        
        $keysCleared = 0;
        if (!empty($keys)) {
            // Delete all keys matching the pattern
            $keysCleared = $redis->del($keys);
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Redis cache flushed successfully',
            'keys_cleared' => $keysCleared
        ]);
        
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
