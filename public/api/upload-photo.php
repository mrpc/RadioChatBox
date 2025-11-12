<?php
/**
 * Photo Upload API
 * Handles photo uploads for private messages
 */

// Enable error display for debugging
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../../vendor/autoload.php';

use RadioChatBox\CorsHandler;
use RadioChatBox\PhotoService;

CorsHandler::handle();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    // Get form data
    $username = $_POST['username'] ?? '';
    $recipient = $_POST['recipient'] ?? '';
    $sessionId = $_POST['sessionId'] ?? '';

    if (empty($username) || empty($recipient)) {
        throw new \InvalidArgumentException('Username and recipient are required');
    }

    if (!isset($_FILES['photo'])) {
        throw new \InvalidArgumentException('No photo file provided');
    }

    // Get client IP
    $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    // Upload photo
    $photoService = new PhotoService();
    $result = $photoService->uploadPhoto($_FILES['photo'], $username, $recipient, $ipAddress);

    echo json_encode([
        'success' => true,
        'attachment' => $result,
        'message' => 'Photo uploaded successfully'
    ]);

} catch (\InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (\RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to upload photo',
        'debug' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    error_log("Photo upload error: " . $e->getMessage());
}
