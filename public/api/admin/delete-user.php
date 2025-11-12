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

// Only DELETE allowed
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
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

// Prevent self-deletion
$currentUserData = $userService->getUserByUsername($currentUser['username']);
if ($currentUserData && $currentUserData['id'] === $userId) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Cannot delete your own account']);
    exit;
}

// Check if current user can manage target user
if (!$userService->canManageUser($currentUser['role'], $targetUser['role'])) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'You cannot delete this user']);
    exit;
}

// For root users, require explicit permission
if ($targetUser['role'] === 'root' && !AdminAuth::hasPermission('delete_root_users')) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Only root users can delete other root users']);
    exit;
}

// Delete user
$result = $userService->deleteUser($userId);

header('Content-Type: application/json');
if ($result['success']) {
    http_response_code(200);
    echo json_encode($result);
} else {
    http_response_code(400);
    echo json_encode($result);
}
