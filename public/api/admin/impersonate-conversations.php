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

// Only root/owner can view impersonation messages
$currentUser = AdminAuth::getCurrentUser();
if (!$currentUser || !in_array($currentUser['role'], ['root', 'owner'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden: Only root/owner can access impersonation']);
    exit;
}

try {
    $pdo = Database::getPDO();
    
    // Get all active fake users
    $stmt = $pdo->prepare("SELECT id, nickname FROM fake_users WHERE is_active = TRUE ORDER BY nickname");
    $stmt->execute();
    $fakeUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // For each fake user, get recent messages TO them
    $conversations = [];
    
    foreach ($fakeUsers as $fakeUser) {
        $stmt = $pdo->prepare("
            SELECT 
                pm.*,
                COUNT(*) OVER() as total_messages,
                MAX(pm.created_at) OVER() as last_message_time
            FROM private_messages pm
            WHERE pm.to_username = ?
            ORDER BY pm.created_at DESC
            LIMIT 50
        ");
        $stmt->execute([$fakeUser['nickname']]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($messages)) {
            // Get unique senders
            $senders = [];
            foreach ($messages as $msg) {
                if (!isset($senders[$msg['from_username']])) {
                    $senders[$msg['from_username']] = [
                        'username' => $msg['from_username'],
                        'last_message' => $msg['message'],
                        'last_message_time' => $msg['created_at'],
                        'unread_count' => 0
                    ];
                }
                $senders[$msg['from_username']]['unread_count']++;
            }
            
            $conversations[$fakeUser['nickname']] = [
                'fake_user' => $fakeUser['nickname'],
                'total_messages' => count($messages),
                'senders' => array_values($senders),
                'recent_messages' => array_slice($messages, 0, 10)
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'fake_users' => array_map(fn($u) => $u['nickname'], $fakeUsers),
        'conversations' => $conversations
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch conversations']);
    error_log('Impersonate conversations error: ' . $e->getMessage());
}
