<?php

namespace RadioChatBox;

use Redis;
use PDO;

class Database
{
    private static ?PDO $pdo = null;
    private static ?Redis $redis = null;

    public static function getPDO(): PDO
    {
        if (self::$pdo === null) {
            $config = Config::get('database');
            $dsn = sprintf(
                'pgsql:host=%s;port=%d;dbname=%s',
                $config['host'],
                $config['port'],
                $config['name']
            );

            self::$pdo = new PDO(
                $dsn,
                $config['user'],
                $config['password'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
        }

        return self::$pdo;
    }

    public static function getRedis(): Redis
    {
        if (self::$redis === null) {
            $config = Config::get('redis');
            self::$redis = new Redis();
            self::$redis->connect($config['host'], $config['port']);
        }

        return self::$redis;
    }
    
    /**
     * Get a new Redis connection for subscribe operations
     * Subscribe blocks the connection, so we need a dedicated instance
     */
    public static function getRedisForSubscribe(): Redis
    {
        $config = Config::get('redis');
        $redis = new Redis();
        $redis->connect($config['host'], $config['port']);
        return $redis;
    }
    
    /**
     * Get Redis key prefix based on database name
     * This ensures multiple instances don't interfere with each other
     */
    public static function getRedisPrefix(): string
    {
        $config = Config::get('database');
        $dbName = $config['name'];
        return "radiochatbox:{$dbName}:";
    }
}
