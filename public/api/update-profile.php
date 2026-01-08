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
$displayName = $input['displayName'] ?? null;

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
        FROM sessions 
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
    
    // Update display name in users table if key is present (even if value is null)
    if (array_key_exists('displayName', $input)) {
        // Check if user is authenticated (has user_id in session)
        $stmt = $db->prepare("
            SELECT user_id 
            FROM sessions 
            WHERE session_id = :session_id AND username = :username AND user_id IS NOT NULL
        ");
        $stmt->execute([
            'session_id' => $sessionId,
            'username' => $username
        ]);
        $session = $stmt->fetch();
        
        if ($session && $session['user_id']) {
            // Handle null and empty string cases for PHP 8+ compatibility
            $trimmedDisplayName = $displayName !== null ? trim($displayName) : '';
            $finalDisplayName = empty($trimmedDisplayName) ? null : $trimmedDisplayName;
            
            // If setting a display name (not clearing it), check for uniqueness
            if ($finalDisplayName !== null) {
                // Check if display name conflicts with any username
                $stmt = $db->prepare("
                    SELECT id FROM users WHERE username = :display_name
                ");
                $stmt->execute(['display_name' => $finalDisplayName]);
                if ($stmt->fetch()) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'This display name is already taken as a username']);
                    exit;
                }
                
                // Check if display name conflicts with another user's display name
                $stmt = $db->prepare("
                    SELECT id FROM users 
                    WHERE display_name = :display_name 
                    AND id != :user_id
                ");
                $stmt->execute([
                    'display_name' => $finalDisplayName,
                    'user_id' => $session['user_id']
                ]);
                if ($stmt->fetch()) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'This display name is already taken']);
                    exit;
                }
                
                // Check if display name conflicts with fake user nicknames
                $stmt = $db->prepare("
                    SELECT id FROM fake_users WHERE nickname = :display_name
                ");
                $stmt->execute(['display_name' => $finalDisplayName]);
                if ($stmt->fetch()) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'This display name conflicts with a system user']);
                    exit;
                }
                
                // Check if display name conflicts with active guest nicknames
                $stmt = $db->prepare("
                    SELECT session_id FROM sessions WHERE username = :display_name
                ");
                $stmt->execute(['display_name' => $finalDisplayName]);
                if ($stmt->fetch()) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'This display name is currently in use as a nickname']);
                    exit;
                }
            }
            
            // Update display_name in users table
            $stmt = $db->prepare("
                UPDATE users 
                SET display_name = :display_name 
                WHERE id = :user_id
            ");
            
            $stmt->execute([
                'display_name' => $finalDisplayName,
                'user_id' => $session['user_id']
            ]);
            
            // Clear display name cache
            $redis = Database::getRedis();
            $prefix = Database::getRedisPrefix();
            $redis->del($prefix . 'display_name:' . $username);
            
            // Clear message history cache to force reload with new display name
            $redis->del($prefix . 'chat:messages');
            
            // Small delay to ensure database commit and cache clear complete
            usleep(100000); // 100ms
            
            // Publish a history refresh event to all connected clients
            $redis->publish($prefix . 'chat:updates', json_encode([
                'type' => 'refresh_history',
                'reason' => 'display_name_changed'
            ]));
        }
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
