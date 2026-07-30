<?php

/**
 * PHPUnit bootstrap.
 *
 * The suite runs against a real PostgreSQL database, so its schema has to exist
 * and be current or tests fail with cryptic "relation/column does not exist"
 * errors. We build/verify it through PramnosFramework's tracked migration
 * runner (the single CreateSchema baseline migration in app/Migrations), the
 * same path `radiochatbox migrate` uses.
 *
 * The runner records applied migrations in `schemaversion`, so this is a no-op
 * once the schema is present and builds it from scratch on a fresh database. It
 * only runs when APP_ENV=testing (set in phpunit.xml), so it can never touch a
 * production connection. A failure here is reported but not fatal — a database
 * that already has the schema (but isn't yet tracked) simply reports "nothing
 * to do" once baselined.
 *
 * We also run the app's framework bootstrap here so the PHPUnit process shares
 * the SAME framework state as a real request — most importantly the Redis
 * {@see \Pramnos\Redis\ConnectionManager} singleton (host/port/per-install
 * prefix). Without it the Cache/Broadcast/JobQueue accessors would fall back to
 * the manager's default (empty-prefix) config, and test helpers seeding through
 * the same accessors would land on a different keyspace than the code reads.
 */

require __DIR__ . '/../vendor/autoload.php';

// Project root — every real entry point (public/index.php, radiochatbox.php) defines
// it, and the framework Application (resolved via app/app.php) needs it too, so
// the test process defines it before anything constructs an Application.
if (!defined('ROOT')) {
    define('ROOT', dirname(__DIR__));
}

if (($_ENV['APP_ENV'] ?? getenv('APP_ENV')) !== 'testing') {
    return;
}

$root = dirname(__DIR__);
$output = (string) shell_exec(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/radiochatbox.php')
    . ' migrate --path=app/Migrations 2>&1'
);

fwrite(STDERR, "[tests] migrate:\n" . trim($output) . "\n");

// Configure framework state (ConnectionManager, Logger, Settings) exactly as an
// HTTP request or the console does, so tests exercise the real wiring.
require $root . '/bootstrap/pramnos.php';
radiochatbox_boot_pramnos();
