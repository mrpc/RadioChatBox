<?php

require_once __DIR__ . '/../../../vendor/autoload.php';

use RadioChatBox\CorsHandler;
use RadioChatBox\AdminAuth;
use RadioChatBox\FakeUserService;

// Handle CORS
CorsHandler::handle();

header('Content-Type: application/json');

// Check authentication
if (!AdminAuth::verify()) {
    AdminAuth::unauthorized();
}

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
        'fake_users' => $fakeUsers
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
    error_log($e->getMessage());
}
