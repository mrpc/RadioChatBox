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
    $username = $_GET['username'] ?? null;
    
    if (!$username) {
        http_response_code(400);
        echo json_encode(['error' => 'Username parameter required']);
        exit;
    }
    
    // Pagination parameters
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 500) : 50;
    $offset = ($page - 1) * $limit;
    
    // Search parameter
    $search = $_GET['search'] ?? '';
    
    // Get user profile
    $stmt = $db->prepare("SELECT * FROM user_profiles WHERE username = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$username]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get total message count for this user (with search filter if provided)
    if (!empty($search)) {
        $stmt = $db->prepare("
            SELECT COUNT(*) 
            FROM messages 
            WHERE username = ? AND message ILIKE ?
        ");
        $stmt->execute([$username, '%' . $search . '%']);
    } else {
        $stmt = $db->prepare("
            SELECT COUNT(*) 
            FROM messages 
            WHERE username = ?
        ");
        $stmt->execute([$username]);
    }
    $totalMessages = (int)$stmt->fetchColumn();
    $totalPages = ceil($totalMessages / $limit);
    
    // Get user's messages with pagination and search
    if (!empty($search)) {
        $stmt = $db->prepare("
            SELECT m.*, u.ip_address 
            FROM messages m
            LEFT JOIN user_activity u ON m.username = u.username
            WHERE m.username = ? AND m.message ILIKE ?
            ORDER BY m.created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute([$username, '%' . $search . '%']);
    } else {
        $stmt = $db->prepare("
            SELECT m.*, u.ip_address 
            FROM messages m
            LEFT JOIN user_activity u ON m.username = u.username
            WHERE m.username = ?
            ORDER BY m.created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute([$username]);
    }
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get user's IP addresses (from user_activity table)
    $stmt = $db->prepare("
        SELECT DISTINCT ip_address, first_seen
        FROM user_activity 
        WHERE username = ?
        ORDER BY first_seen DESC
    ");
    $stmt->execute([$username]);
    $ipAddresses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get active session info
    $stmt = $db->prepare("SELECT * FROM sessions WHERE username = ?");
    $stmt->execute([$username]);
    $activeSession = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get private messages count and paginated results (only for root and administrator)
    $privateMessages = [];
    $totalPrivateMessages = 0;
    $privateMessagesPages = 0;
    if (AdminAuth::hasPermission('view_private_messages')) {
        // Get total private messages count
        $stmt = $db->prepare("
            SELECT COUNT(*) 
            FROM private_messages 
            WHERE from_username = ? OR to_username = ?
        ");
        $stmt->execute([$username, $username]);
        $totalPrivateMessages = (int)$stmt->fetchColumn();
        $privateMessagesPages = ceil($totalPrivateMessages / $limit);
        
        // Get paginated private messages
        $stmt = $db->prepare("
            SELECT * FROM private_messages 
            WHERE from_username = ? OR to_username = ?
            ORDER BY created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute([$username, $username]);
        $privateMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    echo json_encode([
        'success' => true,
        'user' => [
            'username' => $username,
            'profile' => $profile ?: null,
            'messages' => $messages,
            'ip_addresses' => $ipAddresses,
            'active_session' => $activeSession ?: null,
            'private_messages' => $privateMessages,
            'total_messages' => $totalMessages,
            'total_private_messages' => $totalPrivateMessages
        ],
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total_messages' => $totalMessages,
            'total_pages' => $totalPages,
            'total_private_messages' => $totalPrivateMessages,
            'private_messages_pages' => $privateMessagesPages
        ],
        'search' => $search
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
