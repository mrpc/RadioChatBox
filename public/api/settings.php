<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use RadioChatBox\CorsHandler;
use RadioChatBox\SettingsService;

header('Content-Type: application/json');

CorsHandler::handle();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

try {
    $settingsService = new SettingsService();
    
    // Get public-safe settings for frontend
    $settings = $settingsService->getPublicSettings();
    
    // Add SEO meta tags
    $settings['seo'] = $settingsService->getSeoMeta();
    
    // Add branding
    $settings['branding'] = $settingsService->getBranding();
    
    // Add ad settings
    $settings['ads'] = $settingsService->getAdSettings();
    
    // Add custom scripts
    $settings['scripts'] = $settingsService->getScripts();
    
    // Add analytics config (client-safe)
    $analytics = $settingsService->getAnalyticsConfig();
    $settings['analytics'] = [
        'enabled' => $analytics['enabled'],
        'provider' => $analytics['provider'],
        // Don't send tracking_id to prevent abuse - it's injected server-side
    ];
    
    // Add PHP's upload_max_filesize limit for client-side validation
    $phpMaxUpload = ini_get('upload_max_filesize');
    $phpMaxUploadMB = $phpMaxUpload;
    if (preg_match('/^(\d+)(K|M|G)$/i', $phpMaxUpload, $matches)) {
        $value = (int)$matches[1];
        $unit = strtoupper($matches[2]);
        $phpMaxUploadMB = $value * ($unit === 'G' ? 1024 : ($unit === 'M' ? 1 : 1/1024));
    }
    $settings['php_max_upload_mb'] = (int)$phpMaxUploadMB;
    
    echo json_encode([
        'success' => true,
        'settings' => $settings
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}

