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

// Only POST allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Get current user info
$currentUser = AdminAuth::getCurrentUser();
if (!$currentUser) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Parse JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Validate required fields
if (empty($input['username']) || empty($input['password']) || empty($input['role'])) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Missing required fields: username, password, role']);
    exit;
}

$userService = new UserService();

// Check if current user can create users with this role
if ($input['role'] === 'root' && !AdminAuth::hasPermission('create_root_users')) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Only root users can create other root users']);
    exit;
}

// Get current user ID for created_by field
$currentUserData = $userService->getUserByUsername($currentUser['username']);
$createdBy = $currentUserData ? $currentUserData['id'] : null;

// Create user
$result = $userService->createUser(
    $input['username'],
    $input['password'],
    $input['role'],
    $input['email'] ?? null,
    $createdBy
);

header('Content-Type: application/json');
if ($result['success']) {
    http_response_code(201);
    echo json_encode($result);
} else {
    http_response_code(400);
    echo json_encode($result);
}
