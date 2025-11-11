<?php

namespace RadioChatBox;

use PDO;
use Redis;

class SettingsService
{
    private PDO $pdo;
    private Redis $redis;
    private string $prefix;
    private const SETTINGS_CACHE_KEY = 'settings:all';
    private const CACHE_TTL = 300; // 5 minutes

    public function __construct()
    {
        $this->pdo = Database::getPDO();
        $this->redis = Database::getRedis();
        $this->prefix = Database::getRedisPrefix();
    }

    /**
     * Prefix a Redis key with instance identifier
     */
    private function prefixKey(string $key): string
    {
        return $this->prefix . $key;
    }

    /**
     * Get a specific setting value
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $allSettings = $this->getAll();
        return $allSettings[$key] ?? $default;
    }

    /**
     * Get all settings (cached in Redis)
     */
    public function getAll(): array
    {
        // Try cache first
        $cached = $this->redis->get($this->prefixKey(self::SETTINGS_CACHE_KEY));
        if ($cached !== false) {
            return json_decode($cached, true);
        }

        // Load from database
        $stmt = $this->pdo->query('SELECT setting_key, setting_value FROM settings');
        $settings = [];
        
        while ($row = $stmt->fetch()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }

        // Cache for future requests
        $this->redis->setex($this->prefixKey(self::SETTINGS_CACHE_KEY), self::CACHE_TTL, json_encode($settings));

        return $settings;
    }

    /**
     * Get public-safe settings (for frontend)
     * Excludes sensitive settings like admin passwords
     */
    public function getPublicSettings(): array
    {
        $all = $this->getAll();
        
        // Remove sensitive keys
        $excludeKeys = [
            'admin_password_hash',
            'rate_limit_messages',
            'rate_limit_window'
        ];

        foreach ($excludeKeys as $key) {
            unset($all[$key]);
        }

        return $all;
    }

    /**
     * Update a setting value
     */
    public function set(string $key, mixed $value): bool
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value, updated_at) 
             VALUES (:key, :value, NOW()) 
             ON CONFLICT (setting_key) 
             DO UPDATE SET setting_value = :value, updated_at = NOW()'
        );

        $result = $stmt->execute([
            'key' => $key,
            'value' => (string)$value
        ]);

        if ($result) {
            // Invalidate cache
            $this->redis->del($this->prefixKey(self::SETTINGS_CACHE_KEY));
        }

        return $result;
    }

    /**
     * Update multiple settings at once
     */
    public function setMultiple(array $settings): bool
    {
        $this->pdo->beginTransaction();

        try {
            foreach ($settings as $key => $value) {
                $stmt = $this->pdo->prepare(
                    'INSERT INTO settings (setting_key, setting_value, updated_at) 
                     VALUES (:key, :value, NOW()) 
                     ON CONFLICT (setting_key) 
                     DO UPDATE SET setting_value = :value, updated_at = NOW()'
                );

                $stmt->execute([
                    'key' => $key,
                    'value' => (string)$value
                ]);
            }

            $this->pdo->commit();
            
            // Invalidate cache
            $this->redis->del($this->prefixKey(self::SETTINGS_CACHE_KEY));

            return true;
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Get SEO meta tags as array
     */
    public function getSeoMeta(): array
    {
        $settings = $this->getAll();
        
        return [
            'title' => $settings['site_title'] ?? 'RadioChatBox',
            'description' => $settings['site_description'] ?? '',
            'keywords' => $settings['site_keywords'] ?? '',
            'author' => $settings['meta_author'] ?? '',
            'og_image' => $settings['meta_og_image'] ?? '',
            'og_type' => $settings['meta_og_type'] ?? 'website',
        ];
    }

    /**
     * Get branding settings
     */
    public function getBranding(): array
    {
        $settings = $this->getAll();
        
        return [
            'name' => $settings['brand_name'] ?? 'RadioChatBox',
            'color' => $settings['brand_color'] ?? '#007bff',
            'logo_url' => $settings['logo_url'] ?? '',
            'favicon_url' => $settings['favicon_url'] ?? '',
        ];
    }

    /**
     * Get advertisement settings
     */
    public function getAdSettings(): array
    {
        $settings = $this->getAll();
        
        return [
            'enabled' => ($settings['ads_enabled'] ?? 'false') === 'true',
            'main_top' => $settings['ads_main_top'] ?? '',
            'main_bottom' => $settings['ads_main_bottom'] ?? '',
            'chat_sidebar' => $settings['ads_chat_sidebar'] ?? '',
            'refresh_interval' => (int)($settings['ads_refresh_interval'] ?? 30),
            'refresh_enabled' => ($settings['ads_refresh_enabled'] ?? 'false') === 'true',
        ];
    }

    /**
     * Get custom scripts for injection
     */
    public function getScripts(): array
    {
        $settings = $this->getAll();
        
        return [
            'header' => $settings['header_scripts'] ?? '',
            'body' => $settings['body_scripts'] ?? '',
        ];
    }

    /**
     * Get analytics configuration
     */
    public function getAnalyticsConfig(): array
    {
        $settings = $this->getAll();
        
        return [
            'enabled' => ($settings['analytics_enabled'] ?? 'false') === 'true',
            'provider' => $settings['analytics_provider'] ?? '',
            'tracking_id' => $settings['analytics_tracking_id'] ?? '',
        ];
    }
}
