<?php
/**
 * Clear only message-related Redis cache
 * Useful for debugging without affecting user sessions
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use RadioChatBox\CorsHandler;
use RadioChatBox\AdminAuth;
use RadioChatBox\Database;

// Handle CORS
CorsHandler::handle();

// Check authentication
if (!AdminAuth::verify()) {
    AdminAuth::unauthorized();
    exit;
}

try {
    $redis = Database::getRedis();
    $prefix = Database::getRedisPrefix();
    
    // Clear message-related cache keys
    $keysCleared = [];
    
    // Clear chat messages list
    $messagesKey = $prefix . 'chat:messages';
    if ($redis->exists($messagesKey)) {
        $redis->del($messagesKey);
        $keysCleared[] = 'chat:messages';
    }
    
    // Clear any message-related cached queries
    $pattern = $prefix . 'cache:messages:*';
    $keys = $redis->keys($pattern);
    if ($keys && count($keys) > 0) {
        foreach ($keys as $key) {
            $redis->del($key);
            $keysCleared[] = str_replace($prefix, '', $key);
        }
    }
    
    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Message cache cleared successfully',
        'keys_cleared' => $keysCleared,
        'timestamp' => date('Y-m-d H:i:s')
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to clear cache: ' . $e->getMessage()
    ]);
}
