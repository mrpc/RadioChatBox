<?php

namespace RadioChatBox\Controllers;

use InvalidArgumentException;
use PDO;
use Pramnos\Http\Request;
use Pramnos\Http\Response;
use Pramnos\Routing\Attributes\Route;
use RadioChatBox\AdminAuth;
use RadioChatBox\BotService;
use RadioChatBox\Database;
use RadioChatBox\Installation;
use RadioChatBox\LlmAccount;
use RadioChatBox\LlmPricing;
use RadioChatBox\LlmProviders;
use RadioChatBox\Middleware\AdminAuthMiddleware;
use RadioChatBox\SettingsService;

/**
 * Admin settings resource. Migrated from four legacy file-per-endpoint scripts
 * under public/api/admin/, preserving the exact request inputs, JSON keys,
 * status codes and error mapping:
 *
 *  - GET  /api/admin/settings         (public/api/admin/settings.php)
 *  - POST /api/admin/settings         (public/api/admin/settings.php)
 *  - POST /api/admin/update-settings  (public/api/admin/update-settings.php)
 *  - POST /api/admin/upload-logo      (public/api/admin/upload-logo.php)
 *  - GET/PUT/DELETE /api/admin/notifications (public/api/admin/notifications.php)
 *
 * Every route carries AdminAuthMiddleware, which returns 401
 * {"error":"Unauthorized"} for unauthenticated requests (replacing the legacy
 * AdminAuth::authenticate()/verify() gate). The extra RBAC checks the legacy
 * notifications endpoint performed via AdminAuth::getCurrentUser() are kept.
 */
final class AdminSettingsController
{
    /**
     * GET /api/admin/settings — the full settings map (minus the admin password
     * hash) plus derived helpers: PHP upload limit, embed code/url, bot farewell
     * defaults, LLM provider catalogue, available models, languages, context
     * prompt, default model and seed pricing. Success: {success:true, settings}.
     * Replaces the GET branch of public/api/admin/settings.php.
     */
    #[Route('/api/admin/settings', methods: 'GET', name: 'admin.settings.show', middleware: [AdminAuthMiddleware::class])]
    public function show(): Response
    {
        $db = Database::getPDO();

        try {
            $stmt = $db->query("SELECT setting_key, setting_value FROM settings ORDER BY setting_key");
            $settings = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                // SECURITY: Never send password hash to client
                if ($row['setting_key'] === 'admin_password_hash') {
                    continue;
                }
                $settings[$row['setting_key']] = $row['setting_value'];
            }

            // Get PHP's upload_max_filesize limit
            $phpMaxUpload = ini_get('upload_max_filesize');
            $phpMaxUploadMB = $phpMaxUpload;
            if (preg_match('/^(\d+)(K|M|G)$/i', $phpMaxUpload, $matches)) {
                $value = (int) $matches[1];
                $unit = strtoupper($matches[2]);
                $phpMaxUploadMB = $value * ($unit === 'G' ? 1024 : ($unit === 'M' ? 1 : 1 / 1024));
            }
            $settings['php_max_upload_mb'] = (int) $phpMaxUploadMB;

            // Generate embed code
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost:98';
            $embedUrl = $protocol . '://' . $host . '/';

            $embedCode = '<iframe src="' . htmlspecialchars($embedUrl) . '" width="100%" height="600" frameborder="0" style="border: 1px solid #ccc;"></iframe>';

            $settings['embed_code'] = $embedCode;
            $settings['embed_url'] = $embedUrl;

            // Built-in goodbye variants used when bot_farewell_messages is empty.
            $settings['bot_default_farewell_messages'] = BotService::DEFAULT_FAREWELLS;

            // Providers the bots can use, with their built-in model lists.
            $settingsService = new SettingsService();
            $providers = [];
            foreach (LlmProviders::PROVIDERS as $providerId => $providerConfig) {
                $providers[$providerId] = [
                    'label' => $providerConfig['label'],
                    'models' => $providerConfig['models'],
                    'default_model' => $providerConfig['default_model'],
                    'settings' => $providerConfig['settings'],
                    'api_key_url' => $providerConfig['api_key_url'],
                    'default_base_url' => $providerConfig['base_url'],
                    'supports_balance' => $providerConfig['balance_path'] !== null,
                    'supports_costs' => $providerConfig['costs_path'] !== null,
                ];
            }
            $settings['bot_providers'] = $providers;
            $settings['bot_default_provider'] = LlmProviders::DEFAULT_PROVIDER;

            // Model list for the settings dropdown, from the active provider's
            // /models endpoint where possible; the built-in list is the fallback.
            $account = new LlmAccount($settingsService);
            $models = $account->models();
            $settings['bot_available_models'] = $models['models'];
            $settings['bot_available_models_source'] = $models['source'];
            $settings['bot_active_provider'] = $account->getProvider();

            // Scripts a bot can be told to write in.
            $settings['bot_languages'] = BotService::LANGUAGES;
            $settings['bot_default_context_prompt'] = BotService::DEFAULT_CONTEXT_PROMPT;
            $settings['bot_default_model'] = BotService::defaultModel();

            // The built-in pricing table, shown when the setting is empty.
            $settings['bot_default_llm_prices'] = LlmPricing::seedJson();

            return Response::json(['success' => true, 'settings' => $settings]);
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            return Response::json(['error' => 'Server error: ' . $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/admin/settings — apply an admin settings update. The whitelist,
     * clamping and validation live in SettingsService::updateFromAdmin(); this
     * action only translates HTTP to that call and reports ignored/rejected keys.
     * Empty body -> 400 {"error":"Invalid JSON"}; success -> {success:true,
     * message, [ignored], [rejected]}. Replaces the POST branch of
     * public/api/admin/settings.php.
     */
    #[Route('/api/admin/settings', methods: 'POST', name: 'admin.settings.update', middleware: [AdminAuthMiddleware::class])]
    public function update(): Response
    {
        $db = Database::getPDO();

        try {
            // The framework Request has already decoded the JSON body into $_POST.
            $data = $_POST;

            if (empty($data)) {
                return Response::json(['error' => 'Invalid JSON'], 400);
            }

            $phpMaxUpload = ini_get('upload_max_filesize');
            $phpMaxUploadMb = (float) $phpMaxUpload;
            if (preg_match('/^(\d+)(K|M|G)$/i', $phpMaxUpload, $matches)) {
                $value = (float) $matches[1];
                $unit = strtoupper($matches[2]);
                $phpMaxUploadMb = $value * ($unit === 'G' ? 1024 : ($unit === 'M' ? 1 : 1 / 1024));
            }

            $result = (new SettingsService())->updateFromAdmin($data, $phpMaxUploadMb);

            $response = ['success' => true, 'message' => 'Settings updated successfully'];

            if (!empty($result['ignored'])) {
                $response['ignored'] = $result['ignored'];
                $response['message'] .= ' (ignored unknown keys: ' . implode(', ', $result['ignored']) . ')';
            }

            // A rejected value was NOT saved: say so rather than reporting success.
            if (!empty($result['rejected'])) {
                $response['rejected'] = $result['rejected'];
                $response['message'] = 'Saved, except: ' . implode(' ', $result['rejected']);
            }

            return Response::json($response);
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            return Response::json(['error' => 'Server error: ' . $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/admin/update-settings — bulk key/value settings write. Expects
     * {"settings": {..}}; a missing/non-array "settings" -> 400 {success:false,
     * error:"Invalid request format"}. Success -> {success:true, message}. Errors
     * -> 500 {success:false, error:"Failed to update settings: .."}. Replaces
     * public/api/admin/update-settings.php.
     */
    #[Route('/api/admin/update-settings', methods: 'POST', name: 'admin.settings.update-multiple', middleware: [AdminAuthMiddleware::class])]
    public function updateMultiple(): Response
    {
        try {
            // The framework Request has already decoded the JSON body into $_POST.
            $input = $_POST;

            if (!isset($input['settings']) || !is_array($input['settings'])) {
                return Response::json(['success' => false, 'error' => 'Invalid request format'], 400);
            }

            $settingsService = new SettingsService();
            $settingsService->setMultiple($input['settings']);

            return Response::json([
                'success' => true,
                'message' => 'Settings updated successfully',
            ]);
        } catch (\Throwable $e) {
            return Response::json([
                'success' => false,
                'error' => 'Failed to update settings: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/admin/upload-logo — upload a logo or favicon (multipart, field
     * "logo"; "type" is "logo"|"favicon"). Validates mime + 2MB max, stores the
     * file under public/uploads/logos/, saves the URL to the settings table and
     * flushes the settings cache. Success -> {success:true, url, message}.
     * Missing file / bad type / too large -> 400; move failure -> 500. Replaces
     * public/api/admin/upload-logo.php.
     */
    #[Route('/api/admin/upload-logo', methods: 'POST', name: 'admin.settings.upload-logo', middleware: [AdminAuthMiddleware::class])]
    public function uploadLogo(): Response
    {
        $db = Database::getPDO();
        $redis = Database::getRedis();

        try {
            if (!isset($_FILES['logo'])) {
                return Response::json(['error' => 'No file uploaded'], 400);
            }

            $file = $_FILES['logo'];
            $logoType = $_POST['type'] ?? 'logo'; // 'logo' or 'favicon'

            // Validate file type
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml', 'image/x-icon'];
            if (!in_array($file['type'], $allowedTypes)) {
                return Response::json(['error' => 'Invalid file type. Only images are allowed.'], 400);
            }

            // Validate file size (max 2MB)
            if ($file['size'] > 2 * 1024 * 1024) {
                return Response::json(['error' => 'File too large. Maximum size is 2MB.'], 400);
            }

            // Create uploads directory if it doesn't exist
            $uploadDir = Installation::root() . '/public/uploads/logos/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            // Generate unique filename
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = $logoType . '_' . time() . '_' . uniqid() . '.' . $extension;
            $filePath = $uploadDir . $filename;

            // Move uploaded file
            if (!move_uploaded_file($file['tmp_name'], $filePath)) {
                return Response::json(['error' => 'Failed to save file'], 500);
            }

            // Generate URL
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $fileUrl = $protocol . '://' . $host . '/uploads/logos/' . $filename;

            // Update database setting
            $settingKey = $logoType === 'favicon' ? 'favicon_url' : 'logo_url';
            $stmt = $db->prepare(
                'INSERT INTO settings (setting_key, setting_value, updated_at)
                 VALUES (:key, :value, NOW())
                 ON CONFLICT (setting_key)
                 DO UPDATE SET setting_value = :value, updated_at = NOW()'
            );

            $stmt->execute([
                'key' => $settingKey,
                'value' => $fileUrl,
            ]);

            // Invalidate settings cache
            $redis->del('settings:all');

            return Response::json([
                'success' => true,
                'url' => $fileUrl,
                'message' => ucfirst($logoType) . ' uploaded successfully',
            ]);
        } catch (\Throwable $e) {
            return Response::json(['error' => 'Server error: ' . $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/admin/notifications — admin notifications with per-admin read
     * state. Query: limit (<=100, default 50), offset, unread_only=true, type.
     * Restricted to root/administrator/owner (403 otherwise). Success ->
     * {success:true, notifications, unread_count, total, admin}. Replaces the GET
     * branch of public/api/admin/notifications.php.
     */
    #[Route('/api/admin/notifications', methods: 'GET', name: 'admin.notifications.index', middleware: [AdminAuthMiddleware::class])]
    public function notificationsIndex(): Response
    {
        $currentUser = AdminAuth::getCurrentUser();
        if (!$currentUser || !in_array($currentUser['role'], ['root', 'administrator', 'owner'])) {
            return Response::json(['error' => 'Forbidden: Only root/administrator can view notifications'], 403);
        }

        try {
            $pdo = Database::getPDO();
            $request = Request::getInstance();

            $limitRaw = $request->get('limit', null, 'get');
            $limit = $limitRaw !== null ? min((int) $limitRaw, 100) : 50;
            $offset = (int) $request->get('offset', 0, 'get');
            $unreadOnly = $request->get('unread_only', null, 'get') === 'true';
            $type = $request->get('type', null, 'get') ?: null;
            $adminUsername = $currentUser['username'];

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
                $notification['is_read'] = (bool) $notification['is_read'];
            }
            unset($notification);

            // Get unread count for this admin using the function
            $stmt = $pdo->prepare("SELECT get_unread_notification_count(?)");
            $stmt->execute([$adminUsername]);
            $unreadCount = $stmt->fetchColumn();

            return Response::json([
                'success' => true,
                'notifications' => $notifications,
                'unread_count' => (int) $unreadCount,
                'total' => count($notifications),
                'admin' => $adminUsername,
            ]);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            \RadioChatBox\Log::write("Admin notifications error: " . $e->getMessage());
            return Response::json(['error' => 'Internal server error'], 500);
        }
    }

    /**
     * PUT /api/admin/notifications — mark a single notification read
     * (notification_id), mark all read (mark_all_read) or clear read ones
     * (clear_read). Restricted to root/administrator/owner (403 otherwise). Empty
     * body or no actionable field -> 400. Success -> {success, message}. Replaces
     * the PUT branch of public/api/admin/notifications.php.
     */
    #[Route('/api/admin/notifications', methods: 'PUT', name: 'admin.notifications.update', middleware: [AdminAuthMiddleware::class])]
    public function notificationsUpdate(): Response
    {
        $currentUser = AdminAuth::getCurrentUser();
        if (!$currentUser || !in_array($currentUser['role'], ['root', 'administrator', 'owner'])) {
            return Response::json(['error' => 'Forbidden: Only root/administrator can view notifications'], 403);
        }

        try {
            $pdo = Database::getPDO();

            // The framework Request has already decoded the JSON body into $_POST.
            $input = $_POST;

            if (empty($input)) {
                throw new InvalidArgumentException('Invalid JSON');
            }

            $notificationId = $input['notification_id'] ?? null;
            $markAllRead = $input['mark_all_read'] ?? false;
            $clearReadNotifications = $input['clear_read'] ?? false;

            if ($clearReadNotifications) {
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

                return Response::json([
                    'success' => true,
                    'message' => "Cleared $count read notification(s)",
                ]);
            } elseif ($markAllRead) {
                $stmt = $pdo->prepare("SELECT mark_all_notifications_read(?)");
                $stmt->execute([$currentUser['username']]);
                $count = $stmt->fetchColumn();

                return Response::json([
                    'success' => true,
                    'message' => "Marked $count notification(s) as read",
                ]);
            } elseif ($notificationId) {
                $stmt = $pdo->prepare("SELECT mark_notification_read(?, ?)");
                $stmt->execute([$notificationId, $currentUser['username']]);
                $success = $stmt->fetchColumn();

                if ($success) {
                    return Response::json([
                        'success' => true,
                        'message' => 'Notification marked as read',
                    ]);
                }

                return Response::json([
                    'success' => false,
                    'message' => 'Notification not found or already read',
                ]);
            }

            throw new InvalidArgumentException('notification_id, mark_all_read, or clear_read is required');
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            \RadioChatBox\Log::write("Admin notifications error: " . $e->getMessage());
            return Response::json(['error' => 'Internal server error'], 500);
        }
    }

    /**
     * DELETE /api/admin/notifications — cleanup old notifications (root only; a
     * non-root role -> 403). Success -> {success:true, message}. Replaces the
     * DELETE branch of public/api/admin/notifications.php.
     */
    #[Route('/api/admin/notifications', methods: 'DELETE', name: 'admin.notifications.cleanup', middleware: [AdminAuthMiddleware::class])]
    public function notificationsCleanup(): Response
    {
        $currentUser = AdminAuth::getCurrentUser();
        if (!$currentUser || !in_array($currentUser['role'], ['root', 'administrator', 'owner'])) {
            return Response::json(['error' => 'Forbidden: Only root/administrator can view notifications'], 403);
        }

        // Only root can run cleanup
        if ($currentUser['role'] !== 'root') {
            return Response::json(['error' => 'Forbidden: Only root can cleanup notifications'], 403);
        }

        try {
            $pdo = Database::getPDO();

            $stmt = $pdo->query("SELECT cleanup_old_notifications()");
            $count = $stmt->fetchColumn();

            return Response::json([
                'success' => true,
                'message' => "Deleted $count old notification(s)",
            ]);
        } catch (\Throwable $e) {
            \RadioChatBox\Log::write("Admin notifications error: " . $e->getMessage());
            return Response::json(['error' => 'Internal server error'], 500);
        }
    }
}
