<?php

require_once __DIR__ . '/../../../vendor/autoload.php';

use RadioChatBox\AdminAuth;
use RadioChatBox\CorsHandler;
use RadioChatBox\ArtworkService;
use RadioChatBox\Database;

header('Content-Type: application/json');

CorsHandler::handle();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if (!AdminAuth::verify()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $type = $_POST['type'] ?? '';          // 'track_cover' | 'artist_image'
    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0 || !in_array($type, ['track_cover', 'artist_image'], true)) {
        throw new InvalidArgumentException('Valid type and id are required');
    }
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('No file uploaded');
    }
    if ($_FILES['file']['size'] > 8 * 1024 * 1024) {
        throw new InvalidArgumentException('Image too large (max 8MB)');
    }

    $bytes = file_get_contents($_FILES['file']['tmp_name']);
    if ($bytes === false) {
        throw new RuntimeException('Could not read upload');
    }

    $saved = (new ArtworkService())->saveUploadedImage($bytes, 'manual');
    if (empty($saved['full'])) {
        throw new RuntimeException('Invalid image or storage failed');
    }

    $pdo = Database::getPDO();
    if ($type === 'track_cover') {
        $pdo->prepare('UPDATE tracks SET cover_file = :c WHERE id = :id')
            ->execute(['c' => $saved['full'], 'id' => $id]);
    } else {
        $pdo->prepare('UPDATE artists SET image_file = :c, updated_at = NOW() WHERE id = :id')
            ->execute(['c' => $saved['full'], 'id' => $id]);
    }

    echo json_encode(['success' => true, 'url' => $saved['full'], 'thumb' => $saved['thumb']]);

} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
    error_log($e->getMessage());
}
