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
            'allowed_origins' => explode(',', getenv('ALLOWED_ORIGINS') ?: '*'),
        ];
    }
}
