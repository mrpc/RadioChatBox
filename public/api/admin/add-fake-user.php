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

    $nickname = $input['nickname'] ?? '';
    $age = isset($input['age']) && $input['age'] !== '' ? (int)$input['age'] : null;
    $sex = $input['sex'] ?? null;
    $location = $input['location'] ?? null;
    
    if (empty($nickname)) {
        throw new InvalidArgumentException('Nickname is required');
    }
    
    // Validate nickname length
    if (strlen($nickname) < 3) {
        throw new InvalidArgumentException('Nickname must be at least 3 characters');
    }
    
    if (strlen($nickname) > 50) {
        throw new InvalidArgumentException('Nickname must be max 50 characters');
    }
    
    // Validate age if provided
    if ($age !== null && ($age < 18 || $age > 99)) {
        throw new InvalidArgumentException('Age must be between 18 and 99');
    }
    
    $fakeUserService = new FakeUserService();
    $fakeUser = $fakeUserService->addFakeUser($nickname, $age, $sex, $location);
    
    echo json_encode([
        'success' => true,
        'fake_user' => $fakeUser
    ]);

} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (PDOException $e) {
    // Handle unique constraint violation (duplicate nickname)
    if (strpos($e->getMessage(), 'unique') !== false) {
        http_response_code(400);
        echo json_encode(['error' => 'Nickname already exists']);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Database error']);
        error_log($e->getMessage());
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
    error_log($e->getMessage());
}
