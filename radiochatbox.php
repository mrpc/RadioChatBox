#!/usr/bin/env php
<?php

/**
 * RadioChatBox CLI entry point (scaffolded-app convention: a root `<cliName>.php`
 * plus `src/Console.php`).
 *
 * This is a deliberately MINIMAL, console-safe framework bootstrap. It does NOT
 * call Application::init(), because init() runs web-oriented steps — notably
 * session start and SessionTrackingMiddleware, which would write to the
 * `sessions` table whose columns differ from the framework's (a known schema
 * collision, see docs/pramnos-migration/05-schema-convergence.md). Instead it
 * wires only what console commands need: the framework Settings and a connected
 * Database on the Application instance the commands resolve via getInstance().
 *
 * Usage (inside the app container): php radiochatbox.php <command>
 *   e.g. php radiochatbox.php bot:status
 */

declare(strict_types=1);

if (!defined('ROOT')) {
    define('ROOT', __DIR__);
}

require ROOT . '/vendor/autoload.php';
require ROOT . '/bootstrap/pramnos.php';

if (!radiochatbox_boot_pramnos()) {
    fwrite(STDERR, "PramnosFramework is not available in this environment.\n");
    exit(1);
}

// Console-safe wiring: connect the DB and hand it to the Application singleton
// that the framework's console commands resolve via getInstance()->database.
$settings = \Pramnos\Application\Settings::getInstance();
$database = \Pramnos\Database\Database::getInstance($settings);
$database->connect();
\Pramnos\Application\Settings::setDatabase($database);

$application = \Pramnos\Application\Application::getInstance();
$application->database = $database;
$application->settings = $settings;

// Auto-run any pending (post-baseline) migrations on every console execution.
// The fingerprint fast-path makes this a no-op when the schema is current; the
// explicit `migrate` command still handles the full baseline (fresh installs / CI).
$application->migrate();

// Register the framework's native Redis health check (pings via the shared
// ConnectionManager) alongside the built-in database/disk/memory checks.
\Pramnos\Health\HealthRegistry::register(new \Pramnos\Health\Checks\RedisConnectivityCheck());

$console = new \RadioChatBox\Console('RadioChatBox CLI');
$console->run();
