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

$db = Database::getPDO();
$redis = Database::getRedis();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data || !isset($data['username'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Username required']);
            exit;
        }
        
        $username = $data['username'];
        
        // Get user's IP and session before removing them
        $stmt = $db->prepare('SELECT ip_address, session_id FROM active_users WHERE username = ?');
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            http_response_code(404);
            echo json_encode(['error' => 'User not found']);
            exit;
        }
        
        // Ban the user's session ID temporarily (1 hour) to prevent immediate rejoin
        $sessionBanKey = 'banned_session:' . $user['session_id'];
        $redis->setex($sessionBanKey, 3600, json_encode([
            'username' => $username,
            'reason' => 'Kicked by admin',
            'kicked_at' => time()
        ]));
        
        // Remove from database
        $stmt = $db->prepare('DELETE FROM active_users WHERE username = ?');
        $result = $stmt->execute([$username]);
        
        if ($result) {
            // Remove from Redis cache
            $redis->hDel('chat:active_users', $username);
            
            // Notify clients that user was kicked
            $notification = json_encode([
                'type' => 'user_kicked',
                'username' => $username,
                'timestamp' => time()
            ]);
            $redis->publish('chat:user_updates', $notification);
            
            echo json_encode([
                'success' => true, 
                'message' => 'User kicked successfully and temporarily banned for 1 hour'
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to kick user']);
        }
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
