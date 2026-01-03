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

// Require admin authentication
if (!AdminAuth::verify()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Only root and administrator can view notifications (same permission as viewing private messages)
$currentUser = AdminAuth::getCurrentUser();
if (!$currentUser || !in_array($currentUser['role'], ['root', 'administrator', 'owner'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden: Only root/administrator can view notifications']);
    exit;
}

try {
    $pdo = Database::getPDO();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Get notifications with per-admin read states
        $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 100) : 50;
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
        $unreadOnly = isset($_GET['unread_only']) && $_GET['unread_only'] === 'true';
        $type = $_GET['type'] ?? null;
        $adminUsername = $currentUser['username'];

        // Build query - get notifications with read state for current admin
        $whereClauses = [];
        $params = [$adminUsername];

        if ($unreadOnly) {
            $whereClauses[] = 'r.notification_id IS NULL';
        }

        if ($type) {
            $whereClauses[] = 'n.notification_type = ?';
            $params[] = $type;
        }

        $whereClause = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

        // Get notifications with per-admin read state
        $stmt = $pdo->prepare("
            SELECT
                n.id,
                n.notification_type,
                n.title,
                n.message,
                n.metadata,
                n.created_at,
                CASE WHEN r.notification_id IS NOT NULL THEN TRUE ELSE FALSE END as is_read,
                r.read_at
            FROM admin_notifications n
            LEFT JOIN admin_notification_reads r
                ON n.id = r.notification_id
                AND r.admin_username = ?
            $whereClause
            ORDER BY n.created_at DESC
            LIMIT ? OFFSET ?
        ");

        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Decode JSON metadata
        foreach ($notifications as &$notification) {
            if ($notification['metadata']) {
                $notification['metadata'] = json_decode($notification['metadata'], true);
            }
            // Convert is_read to boolean
            $notification['is_read'] = (bool)$notification['is_read'];
        }

        // Get unread count for this admin using the function
        $stmt = $pdo->prepare("SELECT get_unread_notification_count(?)");
        $stmt->execute([$adminUsername]);
        $unreadCount = $stmt->fetchColumn();

        echo json_encode([
            'success' => true,
            'notifications' => $notifications,
            'unread_count' => (int)$unreadCount,
            'total' => count($notifications),
            'admin' => $adminUsername
        ]);

    } elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
        // Mark notification(s) as read or clear read notifications
        $input = json_decode(file_get_contents('php://input'), true);

        if (!$input) {
            throw new InvalidArgumentException('Invalid JSON');
        }

        $notificationId = $input['notification_id'] ?? null;
        $markAllRead = $input['mark_all_read'] ?? false;
        $clearReadNotifications = $input['clear_read'] ?? false;

        if ($clearReadNotifications) {
            // Clear all read notifications by deleting them
            $stmt = $pdo->prepare("
                DELETE FROM admin_notifications
                WHERE id IN (
                    SELECT n.id FROM admin_notifications n
                    INNER JOIN admin_notification_reads r
                        ON n.id = r.notification_id
                        AND r.admin_username = ?
                )
            ");
            $stmt->execute([$currentUser['username']]);
            $count = $stmt->rowCount();

            echo json_encode([
                'success' => true,
                'message' => "Cleared $count read notification(s)"
            ]);
        } elseif ($markAllRead) {
            // Mark all as read
            $stmt = $pdo->prepare("SELECT mark_all_notifications_read(?)");
            $stmt->execute([$currentUser['username']]);
            $count = $stmt->fetchColumn();

            echo json_encode([
                'success' => true,
                'message' => "Marked $count notification(s) as read"
            ]);
        } elseif ($notificationId) {
            // Mark single notification as read
            $stmt = $pdo->prepare("SELECT mark_notification_read(?, ?)");
            $stmt->execute([$notificationId, $currentUser['username']]);
            $success = $stmt->fetchColumn();

            if ($success) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Notification marked as read'
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Notification not found or already read'
                ]);
            }
        } else {
            throw new InvalidArgumentException('notification_id, mark_all_read, or clear_read is required');
        }

    } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        // Cleanup old notifications
        // Only root can run cleanup
        if ($currentUser['role'] !== 'root') {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden: Only root can cleanup notifications']);
            exit;
        }

        $stmt = $pdo->query("SELECT cleanup_old_notifications()");
        $count = $stmt->fetchColumn();

        echo json_encode([
            'success' => true,
            'message' => "Deleted $count old notification(s)"
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
    error_log("Admin notifications error: " . $e->getMessage());
}
