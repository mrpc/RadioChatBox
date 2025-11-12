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
            
            // Set connection timeout to 0.5 seconds (500ms)
            // Redis should be local/same-datacenter, if it takes longer something is wrong
            self::$redis->connect($config['host'], $config['port'], 0.5);
            
            // Set read/write timeout to 1 second for normal operations
            // Most Redis operations should complete in milliseconds
            self::$redis->setOption(Redis::OPT_READ_TIMEOUT, 1);
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
        
        // Set connection timeout to 0.5 seconds (500ms)
        $redis->connect($config['host'], $config['port'], 0.5);
        
        // Set initial read timeout to 30 seconds instead of infinite
        // This prevents indefinite hangs if Redis becomes unresponsive
        // stream.php can override this if needed for specific use cases
        $redis->setOption(Redis::OPT_READ_TIMEOUT, 30);
        
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
    
    // ========================================================================
    // TEST HELPER METHODS - Only use in tests!
    // ========================================================================
    
    /**
     * Set a mock PDO instance for testing
     * @param PDO|null $pdo Mock PDO instance
     */
    public static function setPDO(?PDO $pdo): void
    {
        self::$pdo = $pdo;
    }
    
    /**
     * Set a mock Redis instance for testing
     * @param Redis|null $redis Mock Redis instance
     */
    public static function setRedis(?Redis $redis): void
    {
        self::$redis = $redis;
    }
    
    /**
     * Reset singleton instances (for testing)
     */
    public static function reset(): void
    {
        self::$pdo = null;
        self::$redis = null;
    }
}
