<?php
/**
 * Admin Photo Management API
 * View and manage uploaded photos
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use RadioChatBox\CorsHandler;
use RadioChatBox\PhotoService;
use RadioChatBox\AdminAuth;

CorsHandler::handle();

header('Content-Type: application/json');

// Check authentication
if (!AdminAuth::authenticate()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $photoService = new PhotoService();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = $_GET['action'] ?? 'list';

        if ($action === 'list') {
            // Get all photos with pagination
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 200) : 50;
            $offset = ($page - 1) * $limit;
            
            $photos = $photoService->getAllAttachments($limit, $offset);
            $total = $photoService->getTotalAttachmentsCount();
            $totalPages = ceil($total / $limit);
            
            echo json_encode([
                'success' => true,
                'photos' => $photos,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'total_pages' => $totalPages
                ]
            ]);
            
        } elseif ($action === 'by_user') {
            // Get photos by specific user
            $username = $_GET['username'] ?? '';
            if (empty($username)) {
                throw new \InvalidArgumentException('Username is required');
            }
            
            $photos = $photoService->getAttachmentsByUser($username);
            
            echo json_encode([
                'success' => true,
                'username' => $username,
                'photos' => $photos,
                'count' => count($photos)
            ]);
            
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
        }

    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }

} catch (\InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
    error_log("Admin photos error: " . $e->getMessage());
}
