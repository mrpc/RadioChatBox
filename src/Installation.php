<?php

namespace RadioChatBox;

/**
 * Which copy of the code this is.
 *
 * Several installations run on one server - different directories, often the same code
 * and sometimes the same database name - so anything a *process* owns has to be named
 * per installation, not per database: lock files, daemon ids, the lines in the log you
 * are reading at 3am.
 *
 * The database name is not enough for that. Two checkouts can use identically named
 * databases on different hosts, and when `logs/` is not writable the lock falls back to
 * the shared system temp directory, where `worker-radiochatbox.lock` from one
 * installation would silently keep the other's worker from ever starting.
 *
 * So the identity is the installation directory: a readable label plus a short hash of
 * the absolute path, which is stable across restarts and different for every copy.
 * `APP_INSTANCE` overrides it when a name you chose reads better in logs and unit files.
 *
 * Note this is deliberately NOT the Redis key prefix: that scopes *data* (sessions,
 * caches, chat history) and is keyed by database, where it belongs.
 */
class Installation
{
    private static ?string $id = null;

    /**
     * The installation directory, absolute and without a trailing slash.
     */
    public static function root(): string
    {
        return rtrim((string) (realpath(dirname(__DIR__)) ?: dirname(__DIR__)), '/');
    }

    /**
     * A short, filename-safe identity for this installation.
     *
     * `mysite-3f9a1b2c` for /var/www/mysite, or whatever APP_INSTANCE says.
     */
    public static function id(): string
    {
        if (self::$id !== null) {
            return self::$id;
        }

        $configured = trim((string) (getenv('APP_INSTANCE') ?: ''));

        if ($configured !== '') {
            return self::$id = self::sanitize($configured);
        }

        $root = self::root();
        $label = self::sanitize(basename($root));

        // The hash is what makes two copies distinguishable even when their directories
        // are named the same (…/site/current on two release paths, for instance).
        return self::$id = $label . '-' . substr(md5($root), 0, 8);
    }

    /**
     * Path and identity together, for logs and status output: the identity alone does
     * not tell you which directory to go and look at.
     */
    public static function label(): string
    {
        return self::id() . ' (' . self::root() . ')';
    }

    /**
     * Forget the cached identity. Only for tests that change APP_INSTANCE.
     */
    public static function reset(): void
    {
        self::$id = null;
    }

    private static function sanitize(string $value): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_.-]/', '_', $value);

        return ($safe === null || $safe === '') ? 'radiochatbox' : $safe;
    }
}
