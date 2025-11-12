<?php

require_once __DIR__ . '/../../../vendor/autoload.php';

use RadioChatBox\CorsHandler;
use RadioChatBox\AdminAuth;
use RadioChatBox\FakeUserService;

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

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $fakeUserService = new FakeUserService();
    $fakeUsers = $fakeUserService->getAllFakeUsers();
    
    echo json_encode([
        'success' => true,
        'users' => $fakeUsers  // Note: returns 'users' not 'fake_users' for dashboard compatibility
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
    error_log($e->getMessage());
}
