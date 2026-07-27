<?php

namespace RadioChatBox\Controllers;

use Pramnos\Http\Response;
use Pramnos\Routing\Attributes\Route;
use RadioChatBox\SettingsService;

/**
 * GET /api/settings — public, client-safe settings bundle (branding, SEO, ads,
 * scripts, analytics, GIF config, upload limit).
 *
 * Migrated from public/api/settings.php, preserving the exact assembly and the
 * no-cache response headers.
 */
final class SettingsController
{
    #[Route('/api/settings', methods: 'GET', name: 'settings.show')]
    public function show(): Response
    {
        try {
            $settingsService = new SettingsService();

            $settings = $settingsService->getPublicSettings();

            // Defaults for settings that may not exist in the database yet.
            $settings['gif_enabled']  = $settings['gif_enabled'] ?? 'true';
            $settings['gif_provider'] = $settings['gif_provider'] ?? 'giphy';
            $settings['giphy_api_key'] = $settings['giphy_api_key'] ?? '';
            $settings['klipy_api_key'] = $settings['klipy_api_key'] ?? '';

            $settings['seo']      = $settingsService->getSeoMeta();
            $settings['branding'] = $settingsService->getBranding();
            $settings['ads']      = $settingsService->getAdSettings();
            $settings['scripts']  = $settingsService->getScripts();

            $analytics = $settingsService->getAnalyticsConfig();
            $settings['analytics'] = [
                'enabled'     => $analytics['enabled'],
                'provider'    => $analytics['provider'],
                'tracking_id' => $analytics['tracking_id'], // safe: visible in page source anyway
            ];

            // PHP's upload_max_filesize, normalised to MB for client-side validation.
            $phpMaxUpload   = ini_get('upload_max_filesize');
            $phpMaxUploadMB = $phpMaxUpload;
            if (preg_match('/^(\d+)(K|M|G)$/i', $phpMaxUpload, $matches)) {
                $value = (int) $matches[1];
                $unit  = strtoupper($matches[2]);
                $phpMaxUploadMB = $value * ($unit === 'G' ? 1024 : ($unit === 'M' ? 1 : 1 / 1024));
            }
            $settings['php_max_upload_mb'] = (int) $phpMaxUploadMB;

            return Response::json(['success' => true, 'settings' => $settings])
                ->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
                ->withHeader('Pragma', 'no-cache')
                ->withHeader('Expires', '0');
        } catch (\Throwable $e) {
            return Response::json(['error' => 'Server error: ' . $e->getMessage()], 500);
        }
    }
}
