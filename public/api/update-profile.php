<?php
/**
 * Update User Profile
 * Allows users to update their profile information
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use RadioChatBox\Database;
use RadioChatBox\CorsHandler;

CorsHandler::handle();

header('Content-Type: application/json');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

$username = $input['username'] ?? '';
$sessionId = $input['sessionId'] ?? '';
$age = $input['age'] ?? null;
$sex = $input['sex'] ?? '';
$location = $input['location'] ?? '';

// Validation
if (empty($username) || empty($sessionId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Username and session ID are required']);
    exit;
}

// Validate age
if ($age !== null && ($age < 18 || $age > 120)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Age must be between 18 and 120']);
    exit;
}

// Validate sex
if (!empty($sex) && !in_array($sex, ['male', 'female'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid sex value']);
    exit;
}

try {
    $db = Database::getPDO();
    
    // Verify session belongs to user
    $stmt = $db->prepare("
        SELECT username 
        FROM active_users 
        WHERE session_id = :session_id AND username = :username
    ");
    $stmt->execute([
        'session_id' => $sessionId,
        'username' => $username
    ]);
    
    if (!$stmt->fetch()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid session']);
        exit;
    }
    
    // Update profile
    $stmt = $db->prepare("
        INSERT INTO user_profiles (username, session_id, age, sex, location)
        VALUES (:username, :session_id, :age, :sex, :location)
        ON CONFLICT (username, session_id) 
        DO UPDATE SET 
            age = EXCLUDED.age,
            sex = EXCLUDED.sex,
            location = EXCLUDED.location
    ");
    
    $stmt->execute([
        'username' => $username,
        'session_id' => $sessionId,
        'age' => $age,
        'sex' => $sex,
        'location' => $location
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Profile updated successfully'
    ]);
    
} catch (Exception $e) {
    error_log("Error updating profile: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to update profile'
    ]);
}
