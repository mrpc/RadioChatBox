<?php

/**
 * PramnosFramework settings file.
 *
 * Re-expresses RadioChatBox's environment configuration (loaded from `.env` by
 * bootstrap/pramnos.php via the native loadDotenv(), read here with envvar())
 * in the shape \Pramnos\Application\Settings expects, so the framework database
 * layer and the Redis cache adapter connect to exactly the same place as the
 * rest of the app. Defaults match the Docker service names.
 *
 * @return array<string,mixed>
 */

return [
    'database' => [
        'hostname' => (string) envvar('DB_HOST', 'postgres'),
        'port'     => (int) envvar('DB_PORT', 5432),
        'database' => (string) envvar('DB_NAME', 'radiochatbox'),
        'user'     => (string) envvar('DB_USER', 'radiochatbox'),
        'password' => (string) envvar('DB_PASSWORD', 'radiochatbox_secret'),
        'type'     => 'postgresql',
        'schema'   => 'public',
        'prefix'   => '',
        // Session time zone applied by the framework on connect (replaces the
        // former manual SET in RadioChatBox\Database::getDb()).
        'timezone' => getenv('TZ') ?: 'Europe/Athens',
    ],
    'cache' => [
        'method'   => 'redis',
        'hostname' => (string) envvar('REDIS_HOST', 'redis'),
        'port'     => (int) envvar('REDIS_PORT', 6379),
        'caching'  => true,
        // Keep the exact per-database key namespace RadioChatBox already uses,
        // so framework-cached keys never collide with app keys. bootstrap/pramnos.php
        // configures the Redis ConnectionManager (the prefix owner) before it loads
        // these settings, so we read the prefix from there.
        'prefix'   => \Pramnos\Redis\ConnectionManager::getInstance()->prefix(),
    ],
];
