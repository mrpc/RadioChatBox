<?php
/**
 * Edit Message API
 * Allows a user to edit their own public message within 10 minutes of sending.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use RadioChatBox\CorsHandler;
use RadioChatBox\Database;
use RadioChatBox\MessageFilter;

CorsHandler::handle();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);

    $messageId = trim($input['message_id'] ?? '');
    $newText   = trim($input['message']    ?? '');
    $username  = trim($input['username']   ?? '');
    $sessionId = trim($input['sessionId']  ?? '');

    if (empty($messageId) || empty($newText) || empty($username) || empty($sessionId)) {
        http_response_code(400);
        echo json_encode(['error' => 'message_id, message, username and sessionId are required']);
        exit;
    }

    // Length validation (same as send.php)
    if (mb_strlen($newText) > 500) {
        http_response_code(400);
        echo json_encode(['error' => 'Message too long (max 500 characters)']);
        exit;
    }

    $pdo = Database::getPDO();

    // Fetch the original message and verify ownership + timing in one query
    $stmt = $pdo->prepare(
        'SELECT m.message_id, m.username, m.created_at, m.is_deleted, s.user_id,
                EXTRACT(EPOCH FROM (NOW() - m.created_at)) AS age_seconds
         FROM messages m
         INNER JOIN sessions s ON s.username = m.username
         WHERE m.message_id = :message_id
           AND m.username   = :username
           AND s.session_id = :session_id
           AND m.is_deleted = false
         LIMIT 1'
    );
    $stmt->execute([
        'message_id' => $messageId,
        'username'   => $username,
        'session_id' => $sessionId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(403);
        echo json_encode(['error' => 'Message not found or you do not own it']);
        exit;
    }

    // Enforce 10-minute edit window (comparison done in PostgreSQL to avoid timezone issues)
    if ((float)$row['age_seconds'] > 600) {
        http_response_code(403);
        echo json_encode([
            'error'      => 'Edit window has expired (10 minutes)',
            'age_seconds' => (float)$row['age_seconds'],
            'created_at' => $row['created_at'],
            'db_now'     => (function() use ($pdo) {
                return $pdo->query("SELECT NOW() AS now")->fetchColumn();
            })(),
            'php_now'    => date('c'),
            'php_tz'     => date_default_timezone_get(),
        ]);
        exit;
    }

    // Filter the new text (same pipeline as public messages)
    $filterResult = MessageFilter::filterPublicMessage($newText);
    $filtered = $filterResult['filtered'];

    // Persist to PostgreSQL
    $updateStmt = $pdo->prepare(
        'UPDATE messages
         SET message = :message, edited_at = NOW()
         WHERE message_id = :message_id'
    );
    $updateStmt->execute([
        'message'    => $filtered,
        'message_id' => $messageId,
    ]);

    // Invalidate the Redis message list cache so getHistory() refetches from DB
    $redis  = Database::getRedis();
    $prefix = Database::getRedisPrefix();
    $redis->del($prefix . 'chat:messages');

    // Also update the HASH used for reply lookups
    $hashKey = $prefix . 'chat:messages:hash';
    $existing = $redis->hGet($hashKey, $messageId);
    if ($existing !== false) {
        $hashData = json_decode($existing, true) ?: [];
        $hashData['message'] = $filtered;
        $redis->hSet($hashKey, $messageId, json_encode($hashData));
    }

    // Publish real-time edit event to all SSE clients
    $editEvent = [
        'type'       => 'message_edited',
        'message_id' => $messageId,
        'message'    => $filtered,
        'edited_at'  => $now->format('c'),
    ];
    $redis->publish($prefix . 'chat:updates', json_encode($editEvent));

    echo json_encode([
        'success'   => true,
        'message'   => $filtered,
        'edited_at' => $now->format('c'),
    ]);

} catch (PDOException $e) {
    error_log('Edit message DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
} catch (Exception $e) {
    error_log('Edit message error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
