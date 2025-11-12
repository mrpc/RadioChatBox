<?php

require_once __DIR__ . '/../../../vendor/autoload.php';

use RadioChatBox\AdminAuth;
use RadioChatBox\UserService;
use RadioChatBox\CorsHandler;

// Handle CORS
CorsHandler::handle();

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Verify admin authentication
if (!AdminAuth::verify()) {
    AdminAuth::unauthorized();
}

// Require user management permission
AdminAuth::requirePermission('manage_users');

$userService = new UserService();

// GET - List all users
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $includeInactive = isset($_GET['include_inactive']) && $_GET['include_inactive'] === 'true';
    
    $users = $userService->getAllUsers($includeInactive);
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'users' => $users,
        'count' => count($users)
    ]);
    exit;
}

// Unsupported method
http_response_code(405);
header('Content-Type: application/json');
echo json_encode(['error' => 'Method not allowed']);
