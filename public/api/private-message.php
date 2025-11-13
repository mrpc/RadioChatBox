<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use RadioChatBox\CorsHandler;
use RadioChatBox\Database;
use RadioChatBox\MessageFilter;

header('Content-Type: application/json');

CorsHandler::handle();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

try {
    $db = Database::getPDO();
    $redis = Database::getRedis();
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Send private message
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            throw new InvalidArgumentException('Invalid JSON');
        }

        $fromUsername = $input['from_username'] ?? '';
        $fromSessionId = $input['from_session_id'] ?? '';
        $toUsername = $input['to_username'] ?? '';
        $message = $input['message'] ?? '';
        $attachmentId = $input['attachment_id'] ?? null;
        
        if (empty($fromUsername) || empty($toUsername) || empty($fromSessionId)) {
            throw new InvalidArgumentException('From username, to username, and session ID are required');
        }
        
        // Message is optional if there's an attachment
        if (empty($message) && empty($attachmentId)) {
            throw new InvalidArgumentException('Either message or attachment is required');
        }
        
        // Get IP address for violation tracking
        $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        // Filter message for private chat (blocks dangerous content and blacklisted URLs)
        if (!empty($message)) {
            $filterResult = MessageFilter::filterPrivateMessage($message, $ipAddress);
            $message = $filterResult['filtered'];
            $message = MessageFilter::sanitizeForOutput(trim($message));
        }
        
        // Sanitize usernames
        $fromUsername = MessageFilter::sanitizeForOutput(trim($fromUsername));
        $toUsername = MessageFilter::sanitizeForOutput(trim($toUsername));
        
        // Check if recipient exists in active users or is an active fake user
        $stmt = $db->prepare("SELECT session_id FROM active_users WHERE username = ?");
        $stmt->execute([$toUsername]);
        $recipient = $stmt->fetch();
        
        if (!$recipient) {
            // Check if it's an active fake user
            $stmt = $db->prepare("SELECT nickname FROM fake_users WHERE nickname = ? AND is_active = TRUE");
            $stmt->execute([$toUsername]);
            $fakeUser = $stmt->fetch();
            
            if ($fakeUser) {
                // Create a fake session ID for the fake user
                $toSessionId = 'fake_' . md5($toUsername);
            } else {
                throw new RuntimeException('Recipient is not online');
            }
        } else {
            $toSessionId = $recipient['session_id'];
        }
        
        // Store message with session IDs
        $stmt = $db->prepare("
            INSERT INTO private_messages (from_username, from_session_id, to_username, to_session_id, message, attachment_id, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
            RETURNING id, created_at
        ");
        $stmt->execute([$fromUsername, $fromSessionId, $toUsername, $toSessionId, $message, $attachmentId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get attachment info if present
        $attachmentData = null;
        if ($attachmentId) {
            $photoService = new \RadioChatBox\PhotoService();
            $attachmentData = $photoService->getAttachment($attachmentId);
        }
        
        // Publish to Redis for real-time delivery
        $messageData = [
            'id' => $result['id'],
            'from_username' => $fromUsername,
            'to_username' => $toUsername,
            'message' => $message,
            'attachment' => $attachmentData,
            'timestamp' => strtotime($result['created_at']),
            'type' => 'private'
        ];
        
        $prefix = Database::getRedisPrefix();
        $redis->publish($prefix . 'chat:private_messages', json_encode($messageData));
        
        echo json_encode([
            'success' => true,
            'message' => 'Private message sent',
            'data' => $messageData
        ]);
        
    } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Get private message history
        $username = $_GET['username'] ?? '';
        $username = urldecode($username);
        $sessionId = $_GET['session_id'] ?? '';
        $withUser = $_GET['with_user'] ?? null;
        $withUser = urldecode($withUser);
        
        if (empty($username) || empty($sessionId)) {
            throw new InvalidArgumentException('Username and session ID are required');
        }
        
        if ($withUser) {
            // Get conversation with specific user - only for current session
            $stmt = $db->prepare("
                SELECT pm.*, 
                       a.attachment_id, a.filename, a.file_path, a.file_size, 
                       a.mime_type, a.width, a.height
                FROM private_messages pm
                LEFT JOIN attachments a ON pm.attachment_id = a.attachment_id AND a.is_deleted = FALSE
                WHERE ((pm.from_username = ? AND pm.from_session_id = ? AND pm.to_username = ?) 
                    OR (pm.from_username = ? AND pm.to_username = ? AND pm.to_session_id = ?))
                ORDER BY pm.created_at ASC
                LIMIT 500
            ");
            $stmt->execute([$username, $sessionId, $withUser, $withUser, $username, $sessionId]);
        } else {
            // Get all recent private messages for current session only
            $stmt = $db->prepare("
                SELECT pm.*,
                       a.attachment_id, a.filename, a.file_path, a.file_size, 
                       a.mime_type, a.width, a.height
                FROM private_messages pm
                LEFT JOIN attachments a ON pm.attachment_id = a.attachment_id AND a.is_deleted = FALSE
                WHERE (pm.from_username = ? AND pm.from_session_id = ?) 
                   OR (pm.to_username = ? AND pm.to_session_id = ?)
                ORDER BY pm.created_at DESC
                LIMIT 50
            ");
            $stmt->execute([$username, $sessionId, $username, $sessionId]);
        }
        
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format attachment data for each message
        foreach ($messages as &$message) {
            if ($message['attachment_id']) {
                $message['attachment'] = [
                    'attachment_id' => $message['attachment_id'],
                    'filename' => $message['filename'],
                    'file_path' => $message['file_path'],
                    'file_size' => $message['file_size'],
                    'mime_type' => $message['mime_type'],
                    'width' => $message['width'],
                    'height' => $message['height']
                ];
            } else {
                $message['attachment'] = null;
            }
            
            // Remove duplicate fields
            unset($message['filename'], $message['file_size'], 
                  $message['mime_type'], $message['width'], $message['height']);
        }
        
        echo json_encode([
            'success' => true,
            'messages' => $messages,
            'debug' => [
                'username' => $username,
                'withUser' => $withUser
            ]
        ]);
        
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }
    
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
    error_log($e->getMessage());
}
