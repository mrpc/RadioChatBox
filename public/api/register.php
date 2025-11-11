<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use RadioChatBox\CorsHandler;
use RadioChatBox\ChatService;

// Handle CORS
CorsHandler::handle();

header('Content-Type: application/json');

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

    $username = $input['username'] ?? '';
    $sessionId = $input['sessionId'] ?? '';
    $age = $input['age'] ?? null;
    $location = $input['location'] ?? null;
    $sex = $input['sex'] ?? null;
    $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    if (empty($username) || empty($sessionId)) {
        throw new InvalidArgumentException('Username and session ID are required');
    }
    
    $chatService = new ChatService();
    
    // Check if profile is required
    $requireProfile = $chatService->getSetting('require_profile', 'false') === 'true';
    
    if ($requireProfile) {
        // Validate that all profile fields are provided
        if ($age === null || $age === '' || $location === null || $location === '' || $sex === null || $sex === '') {
            throw new InvalidArgumentException('Age, location, and sex are required');
        }
        
        // Validate age range
        $ageInt = (int)$age;
        if ($ageInt < 18 || $ageInt > 120) {
            throw new InvalidArgumentException('Age must be between 18 and 120');
        }
    } elseif ($age !== null && $age !== '') {
        // Validate age if provided (even when not required)
        $ageInt = (int)$age;
        if ($ageInt < 18 || $ageInt > 120) {
            throw new InvalidArgumentException('Age must be between 18 and 120');
        }
    }
    
    $success = $chatService->registerUser($username, $sessionId, $ipAddress, $age, $location, $sex);
    
    if (!$success) {
        http_response_code(403);
        echo json_encode(['error' => 'Unable to register user. You may be banned.']);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'User registered successfully'
    ]);

} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
    error_log($e->getMessage());
}
