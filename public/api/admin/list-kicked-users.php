<?php
require_once __DIR__ . '/../../../vendor/autoload.php';

use RadioChatBox\CorsHandler;
use RadioChatBox\AdminAuth;
use RadioChatBox\Database;

header('Content-Type: application/json');
CorsHandler::handle();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Authenticate admin
if (!AdminAuth::authenticate()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$redis = Database::getRedis();

// Scan for all kicked sessions (banned_session:*)



$pattern = 'banned_session:*';
$cursor = null;
$kicked = [];
do {
    $keys = $redis->scan($cursor, $pattern, 100);
    if ($keys === false) break;
    foreach ($keys as $key) {
        $ttl = $redis->ttl($key);
        $data = json_decode($redis->get($key), true);
        if ($data) {
            $kicked[] = [
                'session_id' => substr($key, strlen('banned_session:')),
                'username' => $data['username'] ?? null,
                'reason' => $data['reason'] ?? null,
                'kicked_at' => $data['kicked_at'] ?? null,
                'expires_in' => $ttl
            ];
        }
    }
} while ($cursor !== 0 && $cursor !== null);

echo json_encode(['kicked_sessions' => $kicked]);
