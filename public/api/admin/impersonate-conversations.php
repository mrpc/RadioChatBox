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
    $redis = Database::getRedis();
    
    // Get all active fake users with their profile info
    $stmt = $pdo->prepare("SELECT id, nickname, age, sex, location FROM fake_users WHERE is_active = TRUE ORDER BY nickname");
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
            // Get unique senders with their last message timestamp and online status
            $senders = [];
            foreach ($messages as $msg) {
                if (!isset($senders[$msg['from_username']])) {
                    // Check if sender is online (has active session)
                    $sessionStmt = $pdo->prepare("SELECT id FROM sessions WHERE username = ? LIMIT 1");
                    $sessionStmt->execute([$msg['from_username']]);
                    $isOnline = $sessionStmt->rowCount() > 0;
                    
                    $senders[$msg['from_username']] = [
                        'username' => $msg['from_username'],
                        'last_message' => $msg['message'],
                        'last_message_time' => $msg['created_at'],
                        'unread_count' => 0,
                        'is_online' => $isOnline
                    ];
                }
                $senders[$msg['from_username']]['unread_count']++;
            }
            
            // Sort senders by: is_online DESC (online first), then by last_message_time DESC (newest first)
            $sendersArray = array_values($senders);
            usort($sendersArray, function($a, $b) {
                // Online status first
                if ($a['is_online'] !== $b['is_online']) {
                    return $b['is_online'] ? 1 : -1;
                }
                // Then by date (newest first)
                return strtotime($b['last_message_time']) - strtotime($a['last_message_time']);
            });
            
            // Get latest message timestamp for sorting fake users
            $lastMessageTime = reset($messages)['created_at'];
            
            $conversations[$fakeUser['nickname']] = [
                'fake_user' => $fakeUser['nickname'],
                'age' => $fakeUser['age'],
                'sex' => $fakeUser['sex'],
                'location' => $fakeUser['location'],
                'total_messages' => count($messages),
                'senders' => $sendersArray,
                'recent_messages' => array_slice($messages, 0, 10),
                'last_message_time' => $lastMessageTime
            ];
        }
    }
    
    // Sort fake users by: most recent message first
    $fakeUsersList = array_values($conversations);
    usort($fakeUsersList, function($a, $b) {
        return strtotime($b['last_message_time']) - strtotime($a['last_message_time']);
    });
    
    echo json_encode([
        'success' => true,
        'fake_users' => array_map(fn($u) => $u['fake_user'], $fakeUsersList),
        'conversations' => array_reduce($conversations, function($carry, $item) {
            $carry[$item['fake_user']] = $item;
            return $carry;
        }, [])
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch conversations']);
    error_log('Impersonate conversations error: ' . $e->getMessage());
}
