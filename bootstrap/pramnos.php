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
