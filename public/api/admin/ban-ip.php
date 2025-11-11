<?php

require_once __DIR__ . '/../../../vendor/autoload.php';

use RadioChatBox\CorsHandler;
use RadioChatBox\ChatService;
use RadioChatBox\AdminAuth;

// Handle CORS
CorsHandler::handle();

header('Content-Type: application/json');

// Check authentication
if (!AdminAuth::verify()) {
    AdminAuth::unauthorized();
}

try {
    $chatService = new ChatService();
    
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Get all banned IPs
        $bannedIPs = $chatService->getBannedIPs();
        echo json_encode([
            'success' => true,
            'banned_ips' => $bannedIPs
        ]);
        
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Ban an IP
        $input = json_decode(file_get_contents('php://input'), true);
        
        $ipAddress = $input['ip_address'] ?? '';
        $reason = $input['reason'] ?? '';
        $durationDays = isset($input['duration_days']) ? (int)$input['duration_days'] : null;
        
        if (empty($ipAddress)) {
            throw new InvalidArgumentException('IP address is required');
        }
        
        $success = $chatService->banIP($ipAddress, $reason, 'admin', $durationDays);
        
        echo json_encode([
            'success' => $success,
            'message' => $success ? 'IP banned successfully' : 'Failed to ban IP'
        ]);
        
    } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        // Unban an IP
        $input = json_decode(file_get_contents('php://input'), true);
        
        $ipAddress = $input['ip_address'] ?? '';
        
        if (empty($ipAddress)) {
            throw new InvalidArgumentException('IP address is required');
        }
        
        $success = $chatService->unbanIP($ipAddress);
        
        echo json_encode([
            'success' => $success,
            'message' => $success ? 'IP unbanned successfully' : 'Failed to unban IP'
        ]);
        
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }

} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
    error_log($e->getMessage());
}
