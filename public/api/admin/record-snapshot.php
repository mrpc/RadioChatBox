<?php

/**
 * Record Statistics Snapshot Endpoint
 * 
 * Records a real-time snapshot of current activity (concurrent users, radio listeners).
 * Should be called periodically by cron (every 5-15 minutes).
 * 
 * Admin-only endpoint.
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use RadioChatBox\AdminAuth;
use RadioChatBox\CorsHandler;
use RadioChatBox\StatsService;

header('Content-Type: application/json');
CorsHandler::handle();

// Admin authentication required
if (!AdminAuth::verify()) {
    AdminAuth::unauthorized();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $statsService = new StatsService();
    $snapshot = $statsService->recordSnapshot();
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'snapshot' => $snapshot
    ]);
} catch (Exception $e) {
    error_log("Stats snapshot error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to record snapshot']);
}
