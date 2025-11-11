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
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 200) : 100;
    $offset = ($page - 1) * $limit;
    
    // Get total count
    $countStmt = $db->query("
        SELECT COUNT(DISTINCT u.username) 
        FROM users u
        WHERE u.username NOT IN (SELECT username FROM active_users)
    ");
    $total = (int)$countStmt->fetchColumn();
    $totalPages = ceil($total / $limit);
    
    // Get users from the users table who are NOT in active_users
    // This shows all users who have ever connected but are not currently active
    $stmt = $db->prepare("
        SELECT DISTINCT 
            u.username,
            u.ip_address,
            p.age,
            p.location,
            p.sex,
            (SELECT MAX(created_at) FROM messages m WHERE m.username = u.username) as last_message_at,
            (SELECT COUNT(*) FROM messages m WHERE m.username = u.username) as message_count
        FROM users u
        LEFT JOIN user_profiles p ON u.username = p.username
        WHERE u.username NOT IN (SELECT username FROM active_users)
        ORDER BY last_message_at DESC NULLS LAST
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    $inactiveUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'users' => $inactiveUsers,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => $totalPages
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
