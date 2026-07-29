<?php

/**
 * PramnosFramework settings file (coexistence bridge).
 *
 * During the framework-integration bridge phase, RadioChatBox\Config remains the
 * single source of truth for configuration parsed from `.env`. This file simply
 * re-expresses that same configuration in the shape PramnosFramework's
 * \Pramnos\Application\Settings expects, so there can never be two divergent
 * config values. Once the app fully adopts the framework, this file becomes the
 * primary source and Config is retired (see docs/pramnos-migration/).
 *
 * @return array<string,mixed>
 */

$db    = \RadioChatBox\Config::get('database');
$redis = \RadioChatBox\Config::get('redis');

return [
    'database' => [
        // Framework key => RadioChatBox\Config key
        'hostname' => $db['host'],
        'port'     => $db['port'],
        'database' => $db['name'],
        'user'     => $db['user'],
        'password' => $db['password'],
        'type'     => 'postgresql',
        'schema'   => 'public',
        'prefix'   => '',
    ],
    'cache' => [
        'method'   => 'redis',
        'hostname' => $redis['host'],
        'port'     => $redis['port'],
        'caching'  => true,
        // Keep the exact per-database key namespace RadioChatBox already uses,
        // so framework-cached keys never collide with app keys. bootstrap/pramnos.php
        // configures the Redis ConnectionManager (the prefix owner) before it loads
        // these settings, so we read the prefix from there.
        'prefix'   => \Pramnos\Redis\ConnectionManager::getInstance()->prefix(),
    ],
];
