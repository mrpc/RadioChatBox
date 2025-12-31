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

// Set script timeout for long-running SSE connections
    // If behind Cloudflare without bypass, reduce to 90 seconds
    set_time_limit(620);
    ini_set('max_execution_time', '620');

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

    // Send current active users (including fake users)
    $allUsers = $chatService->getAllUsers();
    $userCount = count($allUsers);
    echo "event: users\n";
    echo "data: " . json_encode(['count' => $userCount, 'users' => $allUsers]) . "\n\n";
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
    
    // Set Redis read timeout to periodically check connection and send pings
    // When no messages arrive for this duration, Redis throws a timeout exception
    // We catch it and use it as a trigger to send pings
    $redis->setOption(\Redis::OPT_READ_TIMEOUT, 20);
    
    $startTime = time();
    // SSE connection max runtime
    // Set to 600 seconds (10 minutes) when Cloudflare is bypassed via page rule
    // If you're behind Cloudflare without bypass, reduce this to 90 seconds
    $maxRuntime = 600;
    
    // Main loop - keeps reconnecting to Redis on timeout to send pings
    while (time() - $startTime < $maxRuntime && !connection_aborted()) {
        try {
            $redis->subscribe($channels, function($redis, $channel, $message) use (&$lastPing, $username, $prefix, &$startTime, $maxRuntime) {
                // Check if we've exceeded max runtime
                if (time() - $startTime > $maxRuntime) {
                    echo "event: reconnect\n";
                    echo "data: " . json_encode(['reason' => 'timeout']) . "\n\n";
                    flush();
                    return false; // Unsubscribe
                }
                
                if (connection_aborted()) {
                    return false; // Unsubscribe
                }
                
                // Send periodic ping
                if (time() - $lastPing > 20) {
                    echo ": ping " . time() . "\n\n";
                    flush();
                    $lastPing = time();
                }
                
                if ($channel === $prefix . 'chat:updates') {
                    $msgData = json_decode($message, true);
                    if (isset($msgData['type'])) {
                        if ($msgData['type'] === 'clear') {
                            echo "event: clear\n";
                            echo "data: " . $message . "\n\n";
                            flush();
                        } elseif ($msgData['type'] === 'message_deleted') {
                            echo "event: message_deleted\n";
                            echo "data: " . $message . "\n\n";
                            flush();
                        } else {
                            echo "event: message\n";
                            echo "data: " . $message . "\n\n";
                            flush();
                        }
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
                    $msgData = json_decode($message, true);
                    if ($username && ($msgData['to_username'] === $username || $msgData['from_username'] === $username)) {
                        echo "event: private\n";
                        echo "data: " . $message . "\n\n";
                        flush();
                    }
                }
                $lastPing = time();
            });
            
            // If we get here, subscription ended normally (callback returned false)
            break;
            
        } catch (RedisException $e) {
            // Redis timeout or connection error
            $errorMsg = $e->getMessage();
            
            // Check if it's a read timeout (expected for ping mechanism)
            if (strpos($errorMsg, 'read error on connection') !== false || 
                strpos($errorMsg, 'Connection lost') !== false) {
                // Expected timeout - send ping and reconnect
                echo ": ping timeout " . time() . "\n\n";
                flush();
                $lastPing = time();
                
                // Reconnect Redis for next iteration
                try {
                    $redis->close();
                    $redis = Database::getRedisForSubscribe();
                    $redis->setOption(\Redis::OPT_READ_TIMEOUT, 20);
                } catch (Exception $reconnectError) {
                    error_log("Failed to reconnect to Redis: " . $reconnectError->getMessage());
                    break;
                }
            } else {
                // Unexpected error
                error_log("Redis subscribe error: " . $errorMsg);
                echo "event: error\n";
                echo "data: " . json_encode(['error' => 'Connection error']) . "\n\n";
                flush();
                break;
            }
        }
    }
    
    // Max runtime reached, tell client to reconnect
    if (time() - $startTime >= $maxRuntime) {
        echo "event: reconnect\n";
        echo "data: " . json_encode(['reason' => 'max_runtime']) . "\n\n";
        flush();
    }

} catch (Exception $e) {
    error_log("SSE Stream Error: " . $e->getMessage());
    echo "event: error\n";
    echo "data: " . json_encode(['error' => 'Connection error']) . "\n\n";
    flush();
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
