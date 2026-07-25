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
    private const RATE_LIMIT_CACHE_KEY = 'settings:rate_limit';
    private const CACHE_TTL = 300; // 5 minutes

    /**
     * Settings the admin panel is allowed to write.
     *
     * Anything not listed here is ignored by updateFromAdmin(), so a new setting
     * must be added to this list or it will never be saved.
     *
     * @var list<string>
     */
    public const ADMIN_EDITABLE = [
        'rate_limit_messages',
        'rate_limit_window',
        'color_scheme',
        'page_title',
        'require_profile',
        'chat_mode',
        'allow_photo_uploads',
        'gif_enabled',
        'gif_provider',
        'giphy_api_key',
        'klipy_api_key',
        'max_photo_size_mb',
        'minimum_users',
        // Radio stream status (Icecast/Shoutcast)
        'radio_status_url',
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
        // Fake user auto-replies (bots)
        'bot_replies_enabled',
        'bot_llm_api_key',
        'bot_llm_base_url',
        'bot_llm_model',
        'bot_llm_temperature',
        'bot_llm_max_tokens',
        'bot_max_messages_per_thread',
        'bot_history_limit',
        'bot_context_prompt',
        'bot_farewell_prompt',
        'bot_farewell_messages',
        'bot_typing_seconds_per_word',
        'bot_typing_min_delay',
        'bot_typing_max_delay',
        'bot_read_delay_min',
        'bot_read_delay_max',
    ];

    /**
     * Bounds for numeric settings, clamped on write. The bot delays and token
     * caps drive external API calls, so they are not left to the client.
     *
     * @var array<string,array{0:float,1:float}>
     */
    private const NUMERIC_BOUNDS = [
        'rate_limit_messages' => [1, 1000],
        'rate_limit_window' => [1, 3600],
        'minimum_users' => [0, 10000],
        'ads_refresh_interval' => [1, 3600],
        'bot_llm_temperature' => [0, 2],
        'bot_llm_max_tokens' => [16, 4000],
        'bot_max_messages_per_thread' => [0, 100],
        'bot_history_limit' => [2, 100],
        'bot_typing_seconds_per_word' => [0, 10],
        'bot_typing_min_delay' => [0, 300],
        'bot_typing_max_delay' => [1, 600],
        'bot_read_delay_min' => [0, 300],
        'bot_read_delay_max' => [0, 600],
    ];

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

        // Bot (fake user auto-reply) configuration is internal: the frontend
        // never needs it and the prompts/limits should not be discoverable.
        foreach (array_keys($all) as $key) {
            if (str_starts_with($key, 'bot_')) {
                unset($all[$key]);
            }
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
     * Apply a batch of settings coming from the admin panel.
     *
     * Keys outside ADMIN_EDITABLE are ignored and returned, so the caller can
     * report them: a new setting missing from the whitelist would otherwise look
     * like it saved successfully. Numeric settings are clamped to their bounds.
     *
     * @param array<string,mixed> $data
     * @param float|null          $maxPhotoSizeMb PHP's upload_max_filesize in MB,
     *                                            used to cap max_photo_size_mb
     *
     * @return array{saved: list<string>, ignored: list<string>}
     *
     * @throws \InvalidArgumentException when a value fails validation
     */
    public function updateFromAdmin(array $data, ?float $maxPhotoSizeMb = null): array
    {
        $saved = [];
        $ignored = [];

        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO settings (setting_key, setting_value, updated_at)
                 VALUES (:key, :value, NOW())
                 ON CONFLICT (setting_key)
                 DO UPDATE SET setting_value = EXCLUDED.setting_value, updated_at = NOW()'
            );

            foreach ($data as $key => $value) {
                if (!in_array($key, self::ADMIN_EDITABLE, true)) {
                    $ignored[] = (string) $key;
                    continue;
                }

                if ($key === 'max_photo_size_mb') {
                    $value = $this->validatePhotoSize($value, $maxPhotoSizeMb);
                } elseif (isset(self::NUMERIC_BOUNDS[$key])) {
                    $value = $this->clampNumeric($key, $value);
                }

                $stmt->execute(['key' => $key, 'value' => (string) $value]);
                $saved[] = (string) $key;
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $this->invalidateCache();

        return ['saved' => $saved, 'ignored' => $ignored];
    }

    /**
     * Clamp a numeric setting to its configured bounds, keeping whole numbers
     * whole (2 rather than 2.0). An empty value is left alone so a field can be
     * cleared back to "use the default".
     */
    private function clampNumeric(string $key, mixed $value): string
    {
        if ($value === '' || $value === null) {
            return '';
        }

        [$min, $max] = self::NUMERIC_BOUNDS[$key];
        $clamped = max($min, min($max, (float) $value));

        return $clamped === floor($clamped) ? (string) (int) $clamped : (string) $clamped;
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function validatePhotoSize(mixed $value, ?float $maxPhotoSizeMb): string
    {
        $requested = (int) $value;

        if ($requested < 1) {
            throw new \InvalidArgumentException('Photo size limit must be at least 1MB');
        }

        if ($maxPhotoSizeMb !== null && $requested > $maxPhotoSizeMb) {
            throw new \InvalidArgumentException(sprintf(
                "Photo size limit cannot exceed PHP's upload_max_filesize (%sMB)",
                rtrim(rtrim(number_format($maxPhotoSizeMb, 2, '.', ''), '0'), '.')
            ));
        }

        return (string) $requested;
    }

    /**
     * Drop every cached view of the settings.
     */
    public function invalidateCache(): void
    {
        $this->redis->del($this->prefixKey(self::SETTINGS_CACHE_KEY));
        $this->redis->del($this->prefixKey(self::RATE_LIMIT_CACHE_KEY));
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
            $cacheKey = $this->prefixKey(self::SETTINGS_CACHE_KEY);
            $this->redis->del($cacheKey);

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
