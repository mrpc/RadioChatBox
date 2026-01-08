<?php
/**
 * Get User Profile
 * Returns the profile information for a specific user
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use RadioChatBox\Database;
use RadioChatBox\CorsHandler;

CorsHandler::handle();

header('Content-Type: application/json');

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Get username from query parameter
$username = $_GET['username'] ?? '';

if (empty($username)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Username is required']);
    exit;
}

try {
    $db = Database::getPDO();
    
    // First check if this is a registered user (authenticated)
    $userStmt = $db->prepare("SELECT id, username, display_name FROM users WHERE username = :username");
    $userStmt->execute(['username' => $username]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    
    // Get user profile data if exists
    $stmt = $db->prepare("
        SELECT age, sex, location
        FROM user_profiles
        WHERE username = :username
        LIMIT 1
    ");
    $stmt->execute(['username' => $username]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // If no profile exists, create empty profile but include display_name from users table
    if (!$profile) {
        $profile = [
            'age' => null,
            'sex' => null,
            'location' => null
        ];
    }
    
    // Add display_name from users table if user is authenticated
    if ($user) {
        $profile['display_name'] = $user['display_name'];
    } else {
        $profile['display_name'] = null;
    }
    
    echo json_encode([
        'success' => true,
        'profile' => $profile
    ]);
    
} catch (Exception $e) {
    error_log("Error fetching user profile: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to fetch profile'
    ]);
}
