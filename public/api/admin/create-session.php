<?php
/**
 * Create a secure admin session token for SSE connections
 * 
 * This endpoint generates a secure session token that can be used for SSE connections
 * instead of exposing raw credentials in URLs.
 * 
 * Authentication: Authorization header with base64-encoded credentials
 * Returns: Session token as JSON
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once __DIR__ . '/../../../vendor/autoload.php';

use RadioChatBox\AdminAuth;
use RadioChatBox\Database;

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Verify admin credentials via Authorization header
if (!AdminAuth::verify()) {
    error_log('Admin session creation failed: AdminAuth::verify() returned false');
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    // Get Redis connection
    $redis = Database::getRedis();
    
    if (!$redis) {
        throw new Exception('Failed to get Redis connection');
    }
    
    // Generate a cryptographically secure random token
    $token = bin2hex(random_bytes(32));
    
    // Get the authenticated user from session (already verified by AdminAuth::verify())
    $currentUser = AdminAuth::getCurrentUser();
    $username = $currentUser['username'] ?? 'admin';
    $role = $currentUser['role'] ?? 'administrator';
    
    // Store token in Redis with 24-hour TTL
    // Value stores username, role and expiry timestamp for validation
    $expiryTime = time() + (24 * 60 * 60); // 24 hours from now
    $tokenData = json_encode([
        'username' => $username,
        'role' => $role,
        'expires_at' => $expiryTime,
        'created_at' => time()
    ]);
    
    $cacheKey = 'admin_session:' . $token;
    $redis->setex($cacheKey, 24 * 60 * 60, $tokenData);
    
    // Return the token
    echo json_encode([
        'success' => true,
        'session_token' => $token,
        'expires_in' => 24 * 60 * 60
    ]);
    
} catch (Exception $e) {
    error_log('Error creating session token: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to create session token']);
}
