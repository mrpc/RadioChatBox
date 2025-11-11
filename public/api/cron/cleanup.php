<?php
/**
 * Cleanup Cron Endpoint
 * Should be called periodically (e.g., every hour) to clean up expired data
 * 
 * Usage: 
 * - Via cron: curl http://localhost/api/cron/cleanup.php
 * - Or setup a system cron job to call this script
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use RadioChatBox\CleanupService;

header('Content-Type: application/json');

// Simple token-based authentication for cron jobs
$cronToken = $_GET['token'] ?? '';
$expectedToken = getenv('CRON_TOKEN') ?: 'change-me-in-production';

if ($cronToken !== $expectedToken) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $cleanup = new CleanupService();
    $results = $cleanup->runAll();
    
    echo json_encode([
        'success' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'results' => $results,
        'message' => 'Cleanup completed successfully'
    ]);
    
} catch (Exception $e) {
    error_log("Cleanup cron error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Cleanup failed',
        'message' => $e->getMessage()
    ]);
}
