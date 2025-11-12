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

// GET - Get current user info
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $currentUser = AdminAuth::getCurrentUser();
    
    if (!$currentUser) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    
    // Get full user details from database
    $userService = new UserService();
    $userDetails = $userService->getUserByUsername($currentUser['username']);
    
    if (!$userDetails) {
        // Fallback for legacy auth
        $userDetails = [
            'username' => $currentUser['username'],
            'role' => $currentUser['role']
        ];
    }
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'user' => $userDetails
    ]);
    exit;
}

// Unsupported method
http_response_code(405);
header('Content-Type: application/json');
echo json_encode(['error' => 'Method not allowed']);
