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

$db = Database::getPDO();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Get all settings
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
            $value = (int)$matches[1];
            $unit = strtoupper($matches[2]);
            $phpMaxUploadMB = $value * ($unit === 'G' ? 1024 : ($unit === 'M' ? 1 : 1/1024));
        }
        $settings['php_max_upload_mb'] = (int)$phpMaxUploadMB;
        
        // Generate embed code
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost:98';
        $embedUrl = $protocol . '://' . $host . '/';
        
        $embedCode = '<iframe src="' . htmlspecialchars($embedUrl) . '" width="100%" height="600" frameborder="0" style="border: 1px solid #ccc;"></iframe>';
        
        $settings['embed_code'] = $embedCode;
        $settings['embed_url'] = $embedUrl;
        
        echo json_encode(['success' => true, 'settings' => $settings]);
        
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Update settings
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON']);
            exit;
        }
        
        $allowedSettings = [
            'rate_limit_messages',
            'rate_limit_window',
            'color_scheme',
            'page_title',
            'require_profile',
            'chat_mode',
            'allow_photo_uploads',
            'max_photo_size_mb',
            // SEO & Branding
            'site_title',
            'site_description',
            'site_keywords',
            'meta_author',
            'meta_og_image',
            'meta_og_type',
            'favicon_url',
            'logo_url',
            'brand_color',
            'brand_name',
            // Analytics
            'analytics_enabled',
            'analytics_provider',
            'analytics_tracking_id',
            // Advertisements
            'ads_enabled',
            'ads_main_top',
            'ads_main_bottom',
            'ads_chat_sidebar',
            'ads_refresh_interval',
            'ads_refresh_enabled',
            // Custom Scripts
            'header_scripts',
            'body_scripts',
        ];
        
        // Get PHP's upload_max_filesize limit
        $phpMaxUpload = ini_get('upload_max_filesize');
        $phpMaxUploadBytes = $phpMaxUpload;
        if (preg_match('/^(\d+)(K|M|G)$/i', $phpMaxUpload, $matches)) {
            $value = (int)$matches[1];
            $unit = strtoupper($matches[2]);
            $phpMaxUploadBytes = $value * ($unit === 'G' ? 1024 : ($unit === 'M' ? 1 : 1/1024));
        }
        
        $db->beginTransaction();
        
        foreach ($data as $key => $value) {
            if ($key === 'admin_password' && !empty($value)) {
                // Hash the new password
                $hash = password_hash($value, PASSWORD_DEFAULT);
                $stmt = $db->prepare("
                    INSERT INTO settings (setting_key, setting_value, updated_at) 
                    VALUES ('admin_password_hash', ?, NOW())
                    ON CONFLICT (setting_key) 
                    DO UPDATE SET setting_value = EXCLUDED.setting_value, updated_at = NOW()
                ");
                $stmt->execute([$hash]);
            } elseif ($key === 'max_photo_size_mb') {
                // Validate against PHP's upload_max_filesize
                $requestedSize = (int)$value;
                if ($requestedSize > $phpMaxUploadBytes) {
                    throw new \InvalidArgumentException(
                        "Photo size limit cannot exceed PHP's upload_max_filesize ({$phpMaxUpload})"
                    );
                }
                if ($requestedSize < 1) {
                    throw new \InvalidArgumentException("Photo size limit must be at least 1MB");
                }
                $stmt = $db->prepare("
                    INSERT INTO settings (setting_key, setting_value, updated_at) 
                    VALUES (?, ?, NOW())
                    ON CONFLICT (setting_key) 
                    DO UPDATE SET setting_value = EXCLUDED.setting_value, updated_at = NOW()
                ");
                $stmt->execute([$key, $value]);
            } elseif (in_array($key, $allowedSettings)) {
                $stmt = $db->prepare("
                    INSERT INTO settings (setting_key, setting_value, updated_at) 
                    VALUES (?, ?, NOW())
                    ON CONFLICT (setting_key) 
                    DO UPDATE SET setting_value = EXCLUDED.setting_value, updated_at = NOW()
                ");
                $stmt->execute([$key, $value]);
            }
        }
        
        $db->commit();
        
        // Invalidate settings cache in Redis
        $redis = Database::getRedis();
        $redis->del('settings:rate_limit');
        
        echo json_encode(['success' => true, 'message' => 'Settings updated successfully']);
        
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
