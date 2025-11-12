<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use RadioChatBox\CorsHandler;
use RadioChatBox\ChatService;
use RadioChatBox\Database;

// Handle CORS
CorsHandler::handle();

// Set headers for SSE
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // Disable nginx buffering

// Disable output buffering
if (ob_get_level()) ob_end_clean();

// Set script timeout to 5 minutes for SSE connections
// This prevents indefinite hangs while still allowing long-lived connections
set_time_limit(300);
ini_set('max_execution_time', '300');

// Send initial comment to establish connection
echo ": SSE connection established\n\n";
flush();

try {
    $chatService = new ChatService();
    $username = $_GET['username'] ?? null;
    $chatMode = $chatService->getSetting('chat_mode', 'public');
    
    // Send current history (if public mode enabled)
    if ($chatMode === 'public' || $chatMode === 'both') {
        $history = $chatService->getHistory(50);
        echo "event: history\n";
        echo "data: " . json_encode($history) . "\n\n";
        flush();
    }

    // Send current active users
    $activeUsers = $chatService->getActiveUsers();
    $userCount = $chatService->getActiveUserCount();
    echo "event: users\n";
    echo "data: " . json_encode(['count' => $userCount, 'users' => $activeUsers]) . "\n\n";
    flush();

    // Send chat mode
    echo "event: config\n";
    echo "data: " . json_encode(['chat_mode' => $chatMode]) . "\n\n";
    flush();

    // Keep connection alive with periodic pings
    $lastPing = time();
    
    // Subscribe to channels based on mode
    // IMPORTANT: Use a separate Redis connection for subscribe to avoid blocking other requests
    $redis = Database::getRedisForSubscribe();
    $prefix = Database::getRedisPrefix();
    $channels = [$prefix . 'chat:user_updates'];
    
    if ($chatMode === 'public' || $chatMode === 'both') {
        $channels[] = $prefix . 'chat:updates';
    }
    
    if ($chatMode === 'private' || $chatMode === 'both') {
        $channels[] = $prefix . 'chat:private_messages';
    }
    
    // Check if client connection is still alive before subscribing
    if (connection_aborted()) {
        exit;
    }
    
    // Set Redis read timeout with periodic ping check
    // We use a timeout so we can periodically check connection and send pings
    $redis->setOption(\Redis::OPT_READ_TIMEOUT, 30);
    
    $startTime = time();
    $maxRuntime = 270; // Run for 4.5 minutes, then disconnect to force clean reconnect
    
    $redis->subscribe($channels, function($redis, $channel, $message) use (&$lastPing, $username, $prefix, &$startTime, $maxRuntime) {
        // Check if we've exceeded max runtime - force reconnect for fresh connection
        if (time() - $startTime > $maxRuntime) {
            return false; // Unsubscribe
        }
        
        // Check if client is still connected
        if (connection_aborted()) {
            return false; // Unsubscribe
        }
        
        // Send periodic ping to keep connection alive (every 30 seconds)
        if (time() - $lastPing > 30) {
            echo ": ping\n\n";
            flush();
            $lastPing = time();
        }
        
        if ($channel === $prefix . 'chat:updates') {
            // Check if it's a clear event
            $msgData = json_decode($message, true);
            if (isset($msgData['type']) && $msgData['type'] === 'clear') {
                echo "event: clear\n";
                echo "data: " . $message . "\n\n";
                flush();
            } else {
                echo "event: message\n";
                echo "data: " . $message . "\n\n";
                flush();
            }
        } elseif ($channel === $prefix . 'chat:user_updates') {
            echo "event: users\n";
            echo "data: " . $message . "\n\n";
            flush();
        } elseif ($channel === $prefix . 'chat:private_messages') {
            // Only send private messages intended for this user
            $msgData = json_decode($message, true);
            if ($username && ($msgData['to_username'] === $username || $msgData['from_username'] === $username)) {
                echo "event: private\n";
                echo "data: " . $message . "\n\n";
                flush();
            }
        }
        $lastPing = time();
    });

} catch (Exception $e) {
    error_log("SSE Stream Error: " . $e->getMessage());
    
    // Check if it's a Redis timeout (expected behavior for periodic pings)
    if (strpos($e->getMessage(), 'read error on connection') !== false) {
        // This is expected when using read timeout for ping mechanism
        // Connection will be re-established by client
    } else {
        // Actual error - send to client
        echo "event: error\n";
        echo "data: " . json_encode(['error' => 'Connection error']) . "\n\n";
        flush();
    }
} finally {
    // Clean up Redis connection
    if (isset($redis)) {
        try {
            $redis->close();
        } catch (Exception $e) {
            // Ignore close errors
        }
    }
}
