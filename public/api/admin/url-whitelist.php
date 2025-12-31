<?php
/**
 * URL Whitelist Management API
 * Admin endpoint for managing URL patterns that are allowed in public messages
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use RadioChatBox\Database;
use RadioChatBox\AdminAuth;
use RadioChatBox\CorsHandler;

header('Content-Type: application/json');
CorsHandler::handle();

// Verify admin authentication
if (!AdminAuth::authenticate()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $db = Database::getPDO();
    
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Get all whitelisted patterns
        $stmt = $db->query("
            SELECT id, pattern, description, added_by, added_at
            FROM url_whitelist
            ORDER BY added_at DESC
        ");
        $patterns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'patterns' => $patterns
        ]);
        
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Add new pattern
        $input = json_decode(file_get_contents('php://input'), true);
        $pattern = trim($input['pattern'] ?? '');
        $description = trim($input['description'] ?? '');
        
        if (empty($pattern)) {
            http_response_code(400);
            echo json_encode(['error' => 'Pattern is required']);
            exit;
        }
        
        $stmt = $db->prepare("
            INSERT INTO url_whitelist (pattern, description, added_by)
            VALUES (:pattern, :description, 'admin')
        ");
        
        $stmt->execute([
            'pattern' => $pattern,
            'description' => $description
        ]);
        
        // Invalidate Redis cache
        $redis = Database::getRedis();
        $prefix = Database::getRedisPrefix();
        $redis->del($prefix . 'url_whitelist_patterns');
        
        echo json_encode([
            'success' => true,
            'message' => 'Pattern added successfully'
        ]);
        
    } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        // Delete pattern
        $id = $_GET['id'] ?? 0;
        
        if (empty($id)) {
            http_response_code(400);
            echo json_encode(['error' => 'ID is required']);
            exit;
        }
        
        $stmt = $db->prepare("DELETE FROM url_whitelist WHERE id = ?");
        $stmt->execute([$id]);
        
        // Invalidate Redis cache
        $redis = Database::getRedis();
        $prefix = Database::getRedisPrefix();
        $redis->del($prefix . 'url_whitelist_patterns');
        
        echo json_encode([
            'success' => true,
            'message' => 'Pattern deleted successfully'
        ]);
        
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }
    
} catch (\PDOException $e) {
    if ($e->getCode() === '23505') { // Unique violation
        http_response_code(400);
        echo json_encode(['error' => 'Pattern already exists']);
    } else {
        error_log("URL Whitelist error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Database error']);
    }
} catch (\Exception $e) {
    error_log("URL Whitelist error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
