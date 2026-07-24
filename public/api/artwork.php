<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use RadioChatBox\CorsHandler;
use RadioChatBox\ArtworkService;

header('Content-Type: application/json');
header('Cache-Control: public, max-age=3600');

CorsHandler::handle();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $service = new ArtworkService();
    $mode = $_GET['mode'] ?? 'track';
    $artist = trim($_GET['artist'] ?? '');
    $title = trim($_GET['title'] ?? '');

    if ($mode === 'artist') {
        if ($artist === '') {
            throw new InvalidArgumentException('artist is required');
        }
        echo json_encode(['success' => true] + $service->getArtistImage($artist));
    } else {
        if ($artist === '' && $title === '') {
            throw new InvalidArgumentException('artist or title is required');
        }
        echo json_encode(['success' => true] + $service->getArtwork($artist, $title));
    }

} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
    error_log($e->getMessage());
}
