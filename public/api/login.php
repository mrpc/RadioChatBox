<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use RadioChatBox\CorsHandler;
use RadioChatBox\UserService;
use RadioChatBox\Database;

// Handle CORS
CorsHandler::handle();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new InvalidArgumentException('Invalid JSON');
    }

    $username = $input['username'] ?? '';
    $password = $input['password'] ?? '';
    $sessionId = $input['sessionId'] ?? '';
    
    if (empty($username) || empty($password) || empty($sessionId)) {
        throw new InvalidArgumentException('Username, password, and session ID are required');
    }
    
    $userService = new UserService();
    
    // Authenticate user
    $user = $userService->authenticate($username, $password);
    
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid username or password']);
        exit;
    }
    
    // Link the session to this authenticated user
    $pdo = Database::getPDO();
    $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    // Create or update session with user_id
    $stmt = $pdo->prepare(
        'INSERT INTO sessions (username, session_id, ip_address, user_id, last_heartbeat, joined_at)
         VALUES (:username, :session_id, :ip_address, :user_id, NOW(), NOW())
         ON CONFLICT (username, session_id) DO UPDATE SET
             ip_address = :ip_address,
             user_id = :user_id,
             last_heartbeat = NOW()'
    );
    
    $stmt->execute([
        'username' => $user['username'],
        'session_id' => $sessionId,
        'ip_address' => $ipAddress,
        'user_id' => $user['id']
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Login successful',
        'user' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'role' => $user['role']
        ]
    ]);

} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
    error_log($e->getMessage());
}
