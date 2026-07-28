<?php

/**
 * PramnosFramework coexistence bootstrap.
 *
 * Makes the framework's \Pramnos\Application\Settings available to RadioChatBox,
 * populated from the SAME configuration RadioChatBox\Config already parses from
 * `.env` (via app/settings/settings.php). This is the first foundation step of
 * the framework migration (see docs/pramnos-migration/00-overview-and-bc-strategy.md,
 * Phase 1): it introduces the framework *underneath* the app without changing any
 * request-path behaviour.
 *
 * It is deliberately a SAFE NO-OP when the framework cannot load in the current
 * environment (e.g. the `mbstring` extension is missing), so requiring this file
 * can never break an endpoint or the worker. Callers that need the framework must
 * check the boolean return value.
 *
 * Autoloading (vendor/autoload.php) must already be in effect before this runs.
 */

if (!function_exists('radiochatbox_boot_pramnos')) {
    /**
     * Boot the framework's Settings store from RadioChatBox's configuration.
     *
     * Idempotent: safe to call from every entry point; the underlying load runs
     * at most once per process.
     *
     * @return bool True if framework Settings are loaded and usable; false if the
     *              framework is unavailable in this environment (a safe no-op).
     */
    function radiochatbox_boot_pramnos(): bool
    {
        static $booted = null;
        if ($booted !== null) {
            return $booted;
        }

        // Route framework logs to <ROOT>/logs and choose the output mode. This is
        // independent of mbstring/Settings, so logging works even when the rest of
        // the framework bootstrap is a no-op. Default "both": files (for the
        // LogViewer) + STDERR (for `docker logs`); PRAMNOS_LOG_MODE can override.
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', dirname(__DIR__));
        }
        // Framework components expect the DS path-separator constant (normally
        // defined during a full Application boot, which this bridge skips).
        if (!defined('DS')) {
            define('DS', DIRECTORY_SEPARATOR);
        }
        // Logger runs in FILE mode here: RadioChatBox\Log already emits to STDERR
        // via error_log(), so the Logger only needs to add the LogViewer-readable
        // file. PRAMNOS_LOG_MODE can still override for special deployments.
        if (class_exists(\Pramnos\Logs\Logger::class)) {
            $mode = getenv('PRAMNOS_LOG_MODE');
            \Pramnos\Logs\Logger::setOutputMode($mode !== false && $mode !== '' ? $mode : \Pramnos\Logs\Logger::OUTPUT_FILE);
        }

        // Configure the framework's central Redis connection manager from
        // RadioChatBox's own config, so the cache/broadcasting/queue drivers,
        // the health check and the app's state repositories all share one
        // connection source (with the app's tight local-Redis timeouts). Keys are
        // prefixed per-install. Independent of mbstring/Settings.
        if (class_exists(\Pramnos\Redis\ConnectionManager::class)) {
            $redisConfig = \RadioChatBox\Config::get('redis');
            \Pramnos\Redis\ConnectionManager::setInstance(new \Pramnos\Redis\ConnectionManager([
                'host'         => $redisConfig['host'],
                'port'         => $redisConfig['port'],
                'prefix'       => \RadioChatBox\Database::getRedisPrefix(),
                'timeout'      => 0.5,
                'read_timeout' => 1,
            ]));
        }

        // The framework core requires mbstring; bail out cleanly if it is absent
        // (e.g. a host/CLI that has not been provisioned yet — see Dockerfile).
        if (!extension_loaded('mbstring')) {
            return $booted = false;
        }

        if (!class_exists(\Pramnos\Application\Settings::class)) {
            return $booted = false;
        }

        $settingsFile = __DIR__ . '/../app/settings/settings.php';

        try {
            $booted = (bool) \Pramnos\Application\Settings::loadSettings($settingsFile);
        } catch (\Throwable $e) {
            // Never let framework bootstrap take down the app during the bridge phase.
            error_log('PramnosFramework bootstrap skipped: ' . $e->getMessage());
            $booted = false;
        }

        return $booted;
    }
}
