<?php

namespace RadioChatBox;

use Redis;
use PDO;
use Pramnos\Database\Database as PramnosDatabase;

class Database
{
    private static ?PDO $pdo = null;
    private static ?Redis $redis = null;
    private static ?PramnosDatabase $db = null;

    /**
     * The framework database layer, connected and ready.
     *
     * This is the Phase-7 convergence seam: services that have moved off the raw
     * \PDO handle obtain their connection here instead of via {@see getPDO()}, so
     * queries run through PramnosFramework's QueryBuilder / prepared-statement
     * engine while still pointing at the very same PostgreSQL database.
     *
     * The connection is sourced from the SAME `.env`-derived configuration as
     * {@see getPDO()} (via app/settings/settings.php → framework Settings), and
     * the session timezone is set identically, so timestamps and NOW() behave
     * exactly as they did under PDO. The instance is a per-process singleton,
     * mirroring getPDO()'s persistent connection.
     */
    public static function getDb(): PramnosDatabase
    {
        if (self::$db === null) {
            // Load framework Settings from the same config Config already parses.
            // Safe no-op if already booted (idempotent); throws only if the
            // framework genuinely cannot load in this environment.
            require_once __DIR__ . '/../bootstrap/pramnos.php';
            if (!radiochatbox_boot_pramnos()) {
                throw new \RuntimeException(
                    'PramnosFramework is unavailable; cannot obtain the framework database.'
                );
            }

            $db = PramnosDatabase::getInstance();
            if (!$db->connected) {
                $db->connect();
            }

            // Match the legacy PDO session timezone (see getPDO()) so that NOW()
            // and timestamp rendering are byte-identical after the convergence.
            $timezone = getenv('TZ') ?: 'Europe/Athens';
            $db->statement("SET timezone = '" . str_replace("'", "''", $timezone) . "'");

            self::$db = $db;
        }

        return self::$db;
    }

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
                    // PERFORMANCE OPTIMIZATION: Enable persistent connections
                    // Reuses existing connections instead of creating new ones
                    // Reduces connection overhead by ~50-100ms per request
                    PDO::ATTR_PERSISTENT => true,
                    // Set statement timeout to prevent long-running queries
                    PDO::ATTR_TIMEOUT => 10,
                ]
            );
            
            // Set PostgreSQL timezone to match server timezone
            // This ensures timestamps are stored/retrieved in the correct timezone
            $timezone = getenv('TZ') ?: 'Europe/Athens';
            self::$pdo->exec("SET timezone = '$timezone'");
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
        return 'radiochatbox:' . self::getInstanceName() . ':';
    }

    /**
     * Which *data* this installation owns, for the Redis key prefix.
     *
     * Keyed by database, because that is what the data belongs to: two installations
     * pointed at one database share sessions and caches on purpose.
     *
     * For anything a process owns - lock files, daemon ids, log lines - use
     * Installation::id() instead: that is per directory, and two copies can perfectly
     * well use the same database name.
     */
    public static function getInstanceName(): string
    {
        // Straight from this installation's configuration - nothing to pass in.
        $database = (string) (Config::get('database')['name'] ?? 'radiochatbox');

        return preg_replace('/[^A-Za-z0-9_.-]/', '_', $database) ?: 'radiochatbox';
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
     * Inject a framework database instance for testing.
     * @param PramnosDatabase|null $db Mock/real framework database instance
     */
    public static function setDb(?PramnosDatabase $db): void
    {
        self::$db = $db;
    }

    /**
     * Reset singleton instances (for testing)
     */
    public static function reset(): void
    {
        self::$pdo = null;
        self::$redis = null;
        self::$db = null;
    }
}
