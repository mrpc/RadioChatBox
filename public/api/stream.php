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

// Prevent timeout
set_time_limit(0);
ini_set('max_execution_time', '0');

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
    $redis = Database::getRedis();
    $prefix = Database::getRedisPrefix();
    $channels = [$prefix . 'chat:user_updates'];
    
    if ($chatMode === 'public' || $chatMode === 'both') {
        $channels[] = $prefix . 'chat:updates';
    }
    
    if ($chatMode === 'private' || $chatMode === 'both') {
        $channels[] = $prefix . 'chat:private_messages';
    }
    
    // Set Redis read timeout to prevent connection issues
    $redis->setOption(\Redis::OPT_READ_TIMEOUT, -1);
    
    $redis->subscribe($channels, function($redis, $channel, $message) use (&$lastPing, $username, $prefix) {
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
    echo "event: error\n";
    echo "data: " . json_encode(['error' => $e->getMessage()]) . "\n\n";
    flush();
    
    // Try to reconnect after error
    sleep(1);
}
