<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use RadioChatBox\CorsHandler;
use RadioChatBox\RadioStatusService;

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

CorsHandler::handle();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    $svc = new RadioStatusService();
    $now = $svc->getNowPlaying();

    echo json_encode([
        'success' => true,
        'nowPlaying' => $now,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage()
    ]);
}
