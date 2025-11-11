<?php
require_once __DIR__ . '/../../../vendor/autoload.php';

use RadioChatBox\CorsHandler;
use RadioChatBox\AdminAuth;
use RadioChatBox\Database;
use RadioChatBox\Config;

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

$db = Database::getPDO();
$redis = Database::getRedis();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Soft delete all messages by setting is_deleted = true
        $stmt = $db->prepare("UPDATE messages SET is_deleted = true WHERE is_deleted = false");
        $stmt->execute();
        $deletedCount = $stmt->rowCount();
        
        // Clear Redis cache to remove messages from history immediately
        $dbName = Config::get('database')['name'];
        $redis->del($dbName . ':chat:messages');
        
        // Publish clear event to all connected clients via Redis
        $clearEvent = [
            'type' => 'clear',
            'timestamp' => time()
        ];
        
        $redis->publish($dbName . ':chat:updates', json_encode($clearEvent));
        
        echo json_encode([
            'success' => true,
            'message' => 'Public chat cleared',
            'deleted_count' => $deletedCount
        ]);
        
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
