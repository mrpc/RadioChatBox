<?php

require_once __DIR__ . '/../../../vendor/autoload.php';

use RadioChatBox\CorsHandler;
use RadioChatBox\AdminAuth;
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

// Set script timeout
ini_set('max_execution_time', '120');

// Check authentication via Authorization header or secure session token
// EventSource doesn't support custom headers, so also check session_token parameter
$authenticated = false;
$currentUser = null;

// Try normal header authentication first (for direct API calls)
if (AdminAuth::verify()) {
    $authenticated = true;
    $currentUser = AdminAuth::getCurrentUser();
} else if (isset($_GET['session_token']) && !empty($_GET['session_token'])) {
    // Validate secure session token from Redis
    $sessionToken = $_GET['session_token'];
    $redis = Database::getRedis();
    $cacheKey = 'admin_session:' . $sessionToken;
    $tokenData = $redis->get($cacheKey);
    
    if ($tokenData) {
        $data = json_decode($tokenData, true);
        if ($data && isset($data['expires_at']) && $data['expires_at'] > time()) {
            // Token is valid and not expired
            $authenticated = true;
            // Reconstruct current user from token data
            $currentUser = [
                'username' => $data['username'],
                'role' => 'administrator' // Tokens are only created for authenticated admins
            ];
        }
    }
}

if (!$authenticated || !$currentUser) {
    echo "event: error\n";
    echo "data: " . json_encode(['error' => 'Unauthorized']) . "\n\n";
    flush();
    exit;
}

// Verify admin has proper role
if (!$currentUser || !isset($currentUser['role']) || !in_array($currentUser['role'], ['root', 'administrator', 'owner'])) {
    echo "event: error\n";
    echo "data: " . json_encode(['error' => 'Forbidden - Admin access required']) . "\n\n";
    flush();
    exit;
}

// Send initial comment to establish connection
echo ": Admin SSE connection established\n\n";
flush();

try {
    $pdo = Database::getPDO();
    $prefix = Database::getRedisPrefix();

    // Send initial unread count for this specific admin
    $stmt = $pdo->prepare("SELECT get_unread_notification_count(?)");
    $stmt->execute([$currentUser['username']]);
    $unreadCount = $stmt->fetchColumn();

    echo "event: notification_count\n";
    echo "data: " . json_encode(['unread_count' => (int)$unreadCount]) . "\n\n";
    flush();

    // Subscribe to admin notifications channel
    $redis = Database::getRedisForSubscribe();
    $redis->setOption(\Redis::OPT_READ_TIMEOUT, 20);

    $startTime = time();
    $maxRuntime = 95; // Cloudflare timeout limit
    $lastPing = time();

    // Main event loop
    while (time() - $startTime < $maxRuntime && !connection_aborted()) {
        try {
            $redis->subscribe([$prefix . 'chat:admin_notifications'], function($redis, $channel, $message) use (&$lastPing, &$startTime, $maxRuntime, $prefix) {
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

                $lastPing = time();

                // Only process messages from our admin notifications channel
                if ($channel === $prefix . 'chat:admin_notifications') {
                    $data = json_decode($message, true);
                    if ($data) {
                        echo "event: notification\n";
                        echo "data: " . $message . "\n\n";
                        flush();
                    }
                }
            });
        } catch (\RedisException $e) {
            // Timeout occurred (no messages for 20 seconds), send a ping
            if (time() - $lastPing >= 20 && !connection_aborted()) {
                echo "event: ping\n";
                echo "data: " . json_encode(['timestamp' => time()]) . "\n\n";
                flush();
                $lastPing = time();
            }

            // Check if max runtime exceeded
            if (time() - $startTime > $maxRuntime) {
                echo "event: reconnect\n";
                echo "data: " . json_encode(['reason' => 'timeout']) . "\n\n";
                flush();
                break;
            }
        }
    }

    // Send reconnect event before closing
    if (!connection_aborted()) {
        echo "event: reconnect\n";
        echo "data: " . json_encode(['reason' => 'normal']) . "\n\n";
        flush();
    }

} catch (Exception $e) {
    error_log("Admin stream error: " . $e->getMessage());
    if (!connection_aborted()) {
        echo "event: error\n";
        echo "data: " . json_encode(['error' => 'Stream error']) . "\n\n";
        flush();
    }
}
