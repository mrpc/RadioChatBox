<?php

require_once __DIR__ . '/../../../vendor/autoload.php';

use RadioChatBox\CorsHandler;
use RadioChatBox\SettingsService;
use RadioChatBox\AdminAuth;

// Handle CORS
CorsHandler::handle();

header('Content-Type: application/json');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    // Verify admin authentication
    if (!AdminAuth::verify()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }

    // Get request body
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['settings']) || !is_array($input['settings'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid request format']);
        exit;
    }

    $settingsService = new SettingsService();
    
    // Update settings
    $settingsService->setMultiple($input['settings']);
    
    echo json_encode([
        'success' => true,
        'message' => 'Settings updated successfully'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to update settings: ' . $e->getMessage()
    ]);
}
