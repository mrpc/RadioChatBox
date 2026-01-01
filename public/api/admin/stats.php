<?php

/**
 * Get Statistics Endpoint
 * 
 * Retrieves statistics data for admin dashboard.
 * 
 * Query parameters:
 * - granularity: summary|hourly|daily|weekly|monthly|yearly (default: summary)
 * - start_date: optional start date filter
 * - end_date: optional end date filter
 * - year: optional year filter (for weekly/monthly stats)
 * - limit: optional limit (default varies by granularity)
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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $statsService = new StatsService();
    
    // Force record a snapshot if viewing summary (ensures real-time data exists)
    // This is especially important on first load or when cron isn't running
    if (($_GET['granularity'] ?? 'summary') === 'summary') {
        $statsService->recordSnapshot(true); // Ignore rate limit for admin dashboard
    }
    
    // If cron isn't running, trigger aggregation on-demand
    // This is detected automatically when viewing stats without recent data
    $statsService->triggerAggregationIfNeeded();
    
    $granularity = $_GET['granularity'] ?? 'summary';
    $startDate = $_GET['start_date'] ?? null;
    $endDate = $_GET['end_date'] ?? null;
    $year = isset($_GET['year']) ? (int)$_GET['year'] : null;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : null;
    
    $data = [];
    
    switch ($granularity) {
        case 'summary':
            $data = $statsService->getSummary();
            break;
            
        case 'hourly':
            $limit = $limit ?? 168; // Default: 7 days of hourly data
            $data = $statsService->getHourlyStats($startDate, $endDate, $limit);
            break;
            
        case 'daily':
            $limit = $limit ?? 90; // Default: 90 days
            $data = $statsService->getDailyStats($startDate, $endDate, $limit);
            break;
            
        case 'weekly':
            $limit = $limit ?? 52; // Default: 52 weeks
            $data = $statsService->getWeeklyStats($year, $limit);
            break;
            
        case 'monthly':
            $limit = $limit ?? 24; // Default: 24 months
            $data = $statsService->getMonthlyStats($year, $limit);
            break;
            
        case 'yearly':
            $limit = $limit ?? 10; // Default: 10 years
            $data = $statsService->getYearlyStats($limit);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid granularity']);
            exit;
    }
    
    // Get radio status URL setting to determine if radio stats should be shown
    $settingsService = new \RadioChatBox\SettingsService();
    $radioStatusUrl = $settingsService->get('radio_status_url', '');
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'granularity' => $granularity,
        'data' => $data,
        'radio_enabled' => !empty($radioStatusUrl)
    ]);
} catch (Exception $e) {
    error_log("Stats retrieval error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to retrieve stats']);
}
