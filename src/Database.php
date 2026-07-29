<?php

namespace RadioChatBox;

use PDO;
use Pramnos\Database\Database as PramnosDatabase;

/**
 * RadioChatBox's connection seam for the relational database.
 *
 * Two handles onto the SAME PostgreSQL database live here: the framework
 * {@see getDb()} (QueryBuilder / prepared-statement engine, the Phase-7 target)
 * and the legacy {@see getPDO()} still used by not-yet-converged call sites.
 *
 * Redis is NOT owned here anymore: connections, the per-install key prefix and
 * the blocking subscribe connection all come from the framework Redis
 * {@see \Pramnos\Redis\ConnectionManager} (configured in bootstrap/pramnos.php),
 * which the Cache/Broadcast/JobQueue capabilities and the state repositories
 * share. This class is a pure database seam.
 */
class Database
{
    private static ?PDO $pdo = null;
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
            $dsn = sprintf(
                'pgsql:host=%s;port=%d;dbname=%s',
                (string) envvar('DB_HOST', 'postgres'),
                (int) envvar('DB_PORT', 5432),
                (string) envvar('DB_NAME', 'radiochatbox')
            );

            self::$pdo = new PDO(
                $dsn,
                (string) envvar('DB_USER', 'radiochatbox'),
                (string) envvar('DB_PASSWORD', 'radiochatbox_secret'),
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
        self::$db = null;
    }
}
