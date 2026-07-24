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

    // Record the track for play statistics (de-duplicated: only inserts on
    // an actual track change, guarded against concurrent pollers).
    $newTrackId = null;
    try {
        $newTrackId = (new \RadioChatBox\TrackStatsService())->recordPlay($now);
    } catch (Exception $e) {
        error_log('Track play recording failed: ' . $e->getMessage());
    }

    // Attach stored metadata for the current track (for the hover card).
    if (!empty($now['active']) && !empty($now['display'])) {
        try {
            $now['meta'] = (new \RadioChatBox\TrackStatsService())->getCurrentTrackMeta($now['display']);
        } catch (Exception $e) {
            $now['meta'] = null;
        }
    }

    echo json_encode([
        'success' => true,
        'nowPlaying' => $now,
    ]);

    // When the track just changed, enrich it (album/genre/release/art) right
    // away — AFTER flushing the response so the client poll isn't blocked.
    if ($newTrackId) {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            @ob_end_flush();
            @flush();
        }
        try {
            (new \RadioChatBox\TrackStatsService())->enrichTrack($newTrackId);
        } catch (Exception $e) {
            error_log('Inline track enrichment failed: ' . $e->getMessage());
        }
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage()
    ]);
}
