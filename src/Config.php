<?php

namespace RadioChatBox;

class Config
{
    private static ?array $config = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::$config === null) {
            self::load();
        }

        return self::$config[$key] ?? $default;
    }

    private static function load(): void
    {
        // Load .env file if it exists (for production)
        $envFile = __DIR__ . '/../.env';
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                // Skip comments
                if (strpos(trim($line), '#') === 0) {
                    continue;
                }
                
                // Parse KEY=VALUE
                if (strpos($line, '=') !== false) {
                    list($key, $value) = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value);
                    
                    // Only set if not already set by server environment
                    if (!getenv($key)) {
                        putenv("$key=$value");
                        $_ENV[$key] = $value;
                        $_SERVER[$key] = $value;
                    }
                }
            }
        }
        
        // Load from environment variables
        self::$config = [
            'redis' => [
                'host' => getenv('REDIS_HOST') ?: 'redis',
                'port' => (int)(getenv('REDIS_PORT') ?: 6379),
            ],
            'database' => [
                'host' => getenv('DB_HOST') ?: 'postgres',
                'port' => (int)(getenv('DB_PORT') ?: 5432),
                'name' => getenv('DB_NAME') ?: 'radiochatbox',
                'user' => getenv('DB_USER') ?: 'radiochatbox',
                'password' => getenv('DB_PASSWORD') ?: 'radiochatbox_secret',
            ],
            'chat' => [
                'max_message_length' => (int)(getenv('CHAT_MAX_MESSAGE_LENGTH') ?: 500),
                'rate_limit_seconds' => (int)(getenv('CHAT_RATE_LIMIT_SECONDS') ?: 2),
                'history_limit' => (int)(getenv('CHAT_HISTORY_LIMIT') ?: 100),
                'message_ttl' => (int)(getenv('CHAT_MESSAGE_TTL') ?: 3600),
            ],
            // LLM provider used by the fake user auto-reply bots (DeepSeek by
            // default; any OpenAI-compatible endpoint works). These are only
            // fallbacks: the admin panel settings (bot_llm_*) take precedence,
            // and every bot_* setting is stripped from the public payload.
            'llm' => [
                'api_key' => getenv('DEEPSEEK_API_KEY') ?: '',
                'base_url' => getenv('DEEPSEEK_BASE_URL') ?: 'https://api.deepseek.com',
                // Empty means "use BotService::defaultModel()".
                'model' => getenv('DEEPSEEK_MODEL') ?: '',
                'timeout' => (int)(getenv('DEEPSEEK_TIMEOUT') ?: 20),
            ],
            'allowed_origins' => explode(',', getenv('ALLOWED_ORIGINS') ?: '*'),
            'version' => getenv('APP_VERSION') ?: self::getAutoVersion(),
        ];
    }

    /**
     * Generate automatic version based on file modification time
     * Falls back to timestamp if style.css doesn't exist
     */
    private static function getAutoVersion(): string
    {
        $cssFile = __DIR__ . '/../public/css/style.css';
        if (file_exists($cssFile)) {
            return (string)filemtime($cssFile);
        }
        return (string)time();
    }
}
