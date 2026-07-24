<?php

require_once __DIR__ . '/../../../vendor/autoload.php';

use RadioChatBox\AdminAuth;
use RadioChatBox\CorsHandler;
use RadioChatBox\TrackStatsService;

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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $service = new TrackStatsService();
    $mode = $_GET['mode'] ?? 'summary';

    if ($mode === 'log') {
        // Play log for a specific date (default: today).
        $date = $_GET['date'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new InvalidArgumentException('date must be YYYY-MM-DD');
        }
        echo json_encode([
            'success' => true,
            'mode' => 'log',
            'date' => $date,
            'plays' => $service->getLog($date),
        ]);

    } elseif ($mode === 'top') {
        // Most-played tracks in a window (default: last 7 days).
        $from = $_GET['from'] ?? (new DateTimeImmutable('-7 days'))->format('Y-m-d 00:00:00');
        $to = $_GET['to'] ?? (new DateTimeImmutable('+1 day'))->format('Y-m-d 00:00:00');
        $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 200) : 50;
        echo json_encode([
            'success' => true,
            'mode' => 'top',
            'from' => $from,
            'to' => $to,
            'tracks' => $service->getTopTracks($from, $to, $limit),
        ]);

    } else {
        // Summary over the last N days (default 7).
        $days = isset($_GET['days']) ? (int)$_GET['days'] : 7;
        echo json_encode([
            'success' => true,
            'mode' => 'summary',
            'summary' => $service->getSummary($days),
        ]);
    }

} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
    error_log($e->getMessage());
}
