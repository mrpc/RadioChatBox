<?php

/**
 * Aggregate Statistics Endpoint
 * 
 * Triggers aggregation of statistics at various granularities.
 * Should be called by cron jobs.
 * 
 * Query parameters:
 * - granularity: hourly|daily|weekly|monthly|yearly|all
 * - date: optional date/timestamp for specific aggregation
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
    $granularity = $_GET['granularity'] ?? 'all';
    $date = $_GET['date'] ?? null;
    
    $results = [];
    
    switch ($granularity) {
        case 'hourly':
            $results['hourly'] = $statsService->aggregateHourlyStats($date);
            break;
        case 'daily':
            $results['daily'] = $statsService->aggregateDailyStats($date);
            break;
        case 'weekly':
            $results['weekly'] = $statsService->aggregateWeeklyStats($date);
            break;
        case 'monthly':
            $results['monthly'] = $statsService->aggregateMonthlyStats($date);
            break;
        case 'yearly':
            $year = $date ? (int)date('Y', strtotime($date)) : null;
            $results['yearly'] = $statsService->aggregateYearlyStats($year);
            break;
        case 'all':
            $results = $statsService->runMaintenanceAggregations();
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid granularity']);
            exit;
    }
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'results' => $results
    ]);
} catch (Exception $e) {
    error_log("Stats aggregation error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to aggregate stats']);
}
