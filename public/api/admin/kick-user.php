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

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data || !isset($data['username'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Username required']);
            exit;
        }
        
        $username = $data['username'];
        
        // Remove from active users
        $stmt = $db->prepare('DELETE FROM active_users WHERE username = ?');
        $result = $stmt->execute([$username]);
        
        if ($result) {
            echo json_encode(['success' => true, 'message' => 'User kicked successfully']);
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
