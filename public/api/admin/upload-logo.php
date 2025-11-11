<?php
require_once __DIR__ . '/../../../vendor/autoload.php';

use RadioChatBox\CorsHandler;
use RadioChatBox\AdminAuth;
use RadioChatBox\Database;

header('Content-Type: application/json');

CorsHandler::handle();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Authenticate admin
if (!AdminAuth::authenticate()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = Database::getPDO();
$redis = Database::getRedis();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_FILES['logo'])) {
            http_response_code(400);
            echo json_encode(['error' => 'No file uploaded']);
            exit;
        }

        $file = $_FILES['logo'];
        $logoType = $_POST['type'] ?? 'logo'; // 'logo' or 'favicon'

        // Validate file type
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml', 'image/x-icon'];
        if (!in_array($file['type'], $allowedTypes)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid file type. Only images are allowed.']);
            exit;
        }

        // Validate file size (max 2MB)
        if ($file['size'] > 2 * 1024 * 1024) {
            http_response_code(400);
            echo json_encode(['error' => 'File too large. Maximum size is 2MB.']);
            exit;
        }

        // Create uploads directory if it doesn't exist
        $uploadDir = __DIR__ . '/../../uploads/logos/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Generate unique filename
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = $logoType . '_' . time() . '_' . uniqid() . '.' . $extension;
        $filePath = $uploadDir . $filename;

        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to save file']);
            exit;
        }

        // Generate URL
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $fileUrl = $protocol . '://' . $host . '/uploads/logos/' . $filename;

        // Update database setting
        $settingKey = $logoType === 'favicon' ? 'favicon_url' : 'logo_url';
        $stmt = $db->prepare(
            'INSERT INTO settings (setting_key, setting_value, updated_at) 
             VALUES (:key, :value, NOW()) 
             ON CONFLICT (setting_key) 
             DO UPDATE SET setting_value = :value, updated_at = NOW()'
        );

        $stmt->execute([
            'key' => $settingKey,
            'value' => $fileUrl
        ]);

        // Invalidate settings cache
        $redis->del('settings:all');

        echo json_encode([
            'success' => true,
            'url' => $fileUrl,
            'message' => ucfirst($logoType) . ' uploaded successfully'
        ]);

    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
