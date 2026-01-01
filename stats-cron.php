#!/usr/bin/env php
<?php
/**
 * Stats Collection CLI Script
 * 
 * Run this script via cron to record statistics snapshots and perform aggregations.
 * No HTTP requests or credentials needed - runs directly with database access.
 * 
 * Usage:
 *   php stats-cron.php [action]
 * 
 * Actions:
 *   snapshot     - Record a new statistics snapshot (default)
 *   hourly       - Aggregate hourly statistics
 *   daily        - Aggregate daily statistics
 *   weekly       - Aggregate weekly statistics
 *   monthly      - Aggregate monthly statistics
 *   yearly       - Aggregate yearly statistics
 *   all          - Record snapshot AND run all aggregations
 * 
 * Example crontab entries:
 *   # Record snapshot every 5 minutes
 *   *\/5 * * * * cd /path/to/radiochatbox && php stats-cron.php snapshot >> logs/stats-cron.log 2>&1
 *   
 *   # Aggregate hourly at 5 minutes past each hour
 *   5 * * * * cd /path/to/radiochatbox && php stats-cron.php hourly >> logs/stats-cron.log 2>&1
 *   
 *   # Aggregate daily at 00:10
 *   10 0 * * * cd /path/to/radiochatbox && php stats-cron.php daily >> logs/stats-cron.log 2>&1
 *   
 *   # Aggregate weekly on Monday at 00:15
 *   15 0 * * 1 cd /path/to/radiochatbox && php stats-cron.php weekly >> logs/stats-cron.log 2>&1
 *   
 *   # Aggregate monthly on 1st at 00:20
 *   20 0 1 * * cd /path/to/radiochatbox && php stats-cron.php monthly >> logs/stats-cron.log 2>&1
 *   
 *   # Aggregate yearly on Jan 1st at 00:30
 *   30 0 1 1 * cd /path/to/radiochatbox && php stats-cron.php yearly >> logs/stats-cron.log 2>&1
 */

declare(strict_types=1);

// Ensure we're running from CLI
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script can only be run from command line');
}

// Load Composer autoloader
require_once __DIR__ . '/vendor/autoload.php';

use RadioChatBox\StatsService;

/**
 * Log message with timestamp
 */
function logMessage(string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
}

/**
 * Main execution
 */
try {
    $action = $argv[1] ?? 'snapshot';
    
    logMessage("Starting stats collection: action=$action");
    
    $statsService = new StatsService();
    
    switch ($action) {
        case 'snapshot':
            $statsService->recordSnapshot(true); // Force recording even if auto-record disabled
            logMessage('✓ Snapshot recorded successfully');
            break;
            
        case 'hourly':
            $statsService->aggregateHourlyStats();
            logMessage('✓ Hourly aggregation completed');
            break;
            
        case 'daily':
            $statsService->aggregateDailyStats();
            logMessage('✓ Daily aggregation completed');
            break;
            
        case 'weekly':
            $statsService->aggregateWeeklyStats();
            logMessage('✓ Weekly aggregation completed');
            break;
            
        case 'monthly':
            $statsService->aggregateMonthlyStats();
            logMessage('✓ Monthly aggregation completed');
            break;
            
        case 'yearly':
            $statsService->aggregateYearlyStats();
            logMessage('✓ Yearly aggregation completed');
            break;
            
        case 'all':
            // Record snapshot
            $statsService->recordSnapshot(true);
            logMessage('✓ Snapshot recorded');
            
            // Run all aggregations
            $statsService->aggregateHourlyStats();
            logMessage('✓ Hourly aggregation completed');
            
            $statsService->aggregateDailyStats();
            logMessage('✓ Daily aggregation completed');
            
            $statsService->aggregateWeeklyStats();
            logMessage('✓ Weekly aggregation completed');
            
            $statsService->aggregateMonthlyStats();
            logMessage('✓ Monthly aggregation completed');
            
            $statsService->aggregateYearlyStats();
            logMessage('✓ Yearly aggregation completed');
            break;
            
        default:
            logMessage("ERROR: Unknown action '$action'");
            logMessage('Valid actions: snapshot, hourly, daily, weekly, monthly, yearly, all');
            exit(1);
    }
    
    logMessage('Stats collection completed successfully');
    exit(0);
    
} catch (Exception $e) {
    logMessage('ERROR: ' . $e->getMessage());
    logMessage('Stack trace: ' . $e->getTraceAsString());
    exit(1);
}
