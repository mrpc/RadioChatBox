<?php

require_once __DIR__ . '/../../../vendor/autoload.php';

use RadioChatBox\AdminAuth;
use RadioChatBox\CorsHandler;
use RadioChatBox\Database;
use RadioChatBox\MessageFilter;

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

// Only root/owner can impersonate
$currentUser = AdminAuth::getCurrentUser();
if (!$currentUser || !in_array($currentUser['role'], ['root', 'owner'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden: Only root/owner can impersonate users']);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    
    $impersonateAs = $input['impersonate_as'] ?? '';
    $toUsername = $input['to_username'] ?? '';
    $message = $input['message'] ?? '';
    
    if (empty($impersonateAs) || empty($toUsername) || empty($message)) {
        http_response_code(400);
        echo json_encode(['error' => 'Impersonate username, recipient, and message are required']);
        exit;
    }

    $pdo = Database::getPDO();
    $redis = Database::getRedis();
    
    // Verify the impersonation target is a fake user
    $stmt = $pdo->prepare("SELECT id, username FROM fake_users WHERE username = ? AND is_active = TRUE");
    $stmt->execute([$impersonateAs]);
    $fakeUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$fakeUser) {
        http_response_code(400);
        echo json_encode(['error' => 'Can only impersonate active fake users']);
        exit;
    }
    
    // Get recipient's session_id
    $stmt = $pdo->prepare("SELECT session_id FROM active_users WHERE username = ?");
    $stmt->execute([$toUsername]);
    $recipient = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$recipient) {
        http_response_code(400);
        echo json_encode(['error' => 'Recipient is not online']);
        exit;
    }
    
    // Filter message
    $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'admin';
    $filterResult = MessageFilter::filterPrivateMessage($message, $ipAddress);
    $message = $filterResult['filtered'];
    $message = MessageFilter::sanitizeForOutput(trim($message));
    
    // Create a fake session ID for the fake user (consistent per fake user)
    $fakeSessionId = 'fake_' . md5($impersonateAs);
    
    // Store message in database
    $stmt = $pdo->prepare("
        INSERT INTO private_messages (from_username, from_session_id, to_username, to_session_id, message, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
        RETURNING id, created_at
    ");
    $stmt->execute([$impersonateAs, $fakeSessionId, $toUsername, $recipient['session_id'], $message]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Publish to Redis for real-time delivery
    $messageData = [
        'id' => $result['id'],
        'from_username' => $impersonateAs,
        'to_username' => $toUsername,
        'message' => $message,
        'attachment' => null,
        'timestamp' => strtotime($result['created_at']),
        'type' => 'private'
    ];
    
    $prefix = Database::getRedisPrefix();
    $redis->publish($prefix . 'chat:private_messages', json_encode($messageData));
    
    // Log impersonation for audit
    error_log("IMPERSONATION: Admin {$currentUser['username']} sent message as {$impersonateAs} to {$toUsername}");
    
    echo json_encode([
        'success' => true,
        'message' => 'Message sent successfully',
        'data' => $messageData
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to send message']);
    error_log('Impersonate send error: ' . $e->getMessage());
}
