<?php

require_once __DIR__ . '/../../../vendor/autoload.php';

use RadioChatBox\CorsHandler;
use RadioChatBox\AdminAuth;
use RadioChatBox\FakeUserService;

// Handle CORS
CorsHandler::handle();

// Require admin authentication
AdminAuth::check();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new InvalidArgumentException('Invalid JSON');
    }

    $id = $input['id'] ?? 0;
    
    if (empty($id)) {
        throw new InvalidArgumentException('ID is required');
    }
    
    $fakeUserService = new FakeUserService();
    $success = $fakeUserService->deleteFakeUser($id);
    
    if (!$success) {
        http_response_code(404);
        echo json_encode(['error' => 'Fake user not found']);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Fake user deleted'
    ]);

} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
    error_log($e->getMessage());
}
