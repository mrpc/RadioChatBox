<?php

/**
 * PHPUnit bootstrap.
 *
 * The suite runs against a real PostgreSQL database, so its schema has to exist
 * and be current or tests fail with cryptic "relation/column does not exist"
 * errors. We build/verify it through PramnosFramework's tracked migration
 * runner (the single CreateSchema baseline migration in app/migrations), the
 * same path `radiochatbox migrate` uses.
 *
 * The runner records applied migrations in `schemaversion`, so this is a no-op
 * once the schema is present and builds it from scratch on a fresh database. It
 * only runs when APP_ENV=testing (set in phpunit.xml), so it can never touch a
 * production connection. A failure here is reported but not fatal — a database
 * that already has the schema (but isn't yet tracked) simply reports "nothing
 * to do" once baselined.
 */

require __DIR__ . '/../vendor/autoload.php';

if (($_ENV['APP_ENV'] ?? getenv('APP_ENV')) !== 'testing') {
    return;
}

$root = dirname(__DIR__);
$output = (string) shell_exec(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/rcb')
    . ' migrate --path=app/migrations 2>&1'
);

fwrite(STDERR, "[tests] migrate:\n" . trim($output) . "\n");
