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
if (empty($input['user_id'])) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Missing required field: user_id']);
    exit;
}

$userId = (int)$input['user_id'];
$userService = new UserService();

// Get target user to check if current user can manage them
$targetUser = $userService->getUserById($userId);
if (!$targetUser) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'User not found']);
    exit;
}

// Check if current user can manage target user
if (!$userService->canManageUser($currentUser['role'], $targetUser['role'])) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'You cannot manage this user']);
    exit;
}

// If updating role to root, check permission
if (isset($input['role']) && $input['role'] === 'root') {
    if (!AdminAuth::hasPermission('create_root_users')) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Only root users can assign root role']);
        exit;
    }
}

// Build updates array
$updates = [];
if (isset($input['password'])) {
    $updates['password'] = $input['password'];
}
if (isset($input['email'])) {
    $updates['email'] = $input['email'];
}
if (isset($input['role'])) {
    $updates['role'] = $input['role'];
}
if (isset($input['is_active'])) {
    $updates['is_active'] = $input['is_active'];
}
if (isset($input['display_name'])) {
    $updates['display_name'] = $input['display_name'];
}

if (empty($updates)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'No fields to update']);
    exit;
}

// Update user
$result = $userService->updateUser($userId, $updates);

header('Content-Type: application/json');
if ($result['success']) {
    http_response_code(200);
    echo json_encode($result);
} else {
    http_response_code(400);
    echo json_encode($result);
}
