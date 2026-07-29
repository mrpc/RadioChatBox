<?php

namespace RadioChatBox;

use Pramnos\Database\Database as PramnosDatabase;

/**
 * RadioChatBox's connection seam for the relational database.
 *
 * A single handle onto the framework database layer ({@see getDb()} —
 * QueryBuilder / prepared-statement engine), which every service and controller
 * uses. It also guarantees the framework is booted before the first DB access.
 *
 * There is no raw \PDO here: production runs entirely on the framework layer.
 * Tests that seed with plain SQL use the framework's init-less test helper
 * {@see \Pramnos\Framework\Testing\TestDatabase} instead. Redis is likewise not
 * owned here — connections/prefix come from the framework Redis
 * {@see \Pramnos\Redis\ConnectionManager} (configured in bootstrap/pramnos.php).
 */
class Database
{
    private static ?PramnosDatabase $db = null;

    /**
     * The framework database layer, connected and ready.
     *
     * The single connection every service and controller uses (QueryBuilder /
     * prepared-statement engine). It self-boots the framework on first access
     * (idempotent) so callers never depend on boot ordering, and the session
     * timezone is applied by the framework on connect from the `database.timezone`
     * setting (app/settings/settings.php). Per-process singleton.
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

            // The session timezone is applied by the framework on connect, from
            // the `database.timezone` setting (app/settings/settings.php), so NOW()
            // and timestamp rendering match the app zone without a manual SET here.
            $db = PramnosDatabase::getInstance();
            if (!$db->connected) {
                $db->connect();
            }

            self::$db = $db;
        }

        return self::$db;
    }

    // ========================================================================
    // TEST HELPER METHODS - Only use in tests!
    // ========================================================================

    /**
     * Inject a framework database instance for testing.
     * @param PramnosDatabase|null $db Mock/real framework database instance
     */
    public static function setDb(?PramnosDatabase $db): void
    {
        self::$db = $db;
    }

    /**
     * Reset the singleton (for testing).
     */
    public static function reset(): void
    {
        self::$db = null;
    }
}
