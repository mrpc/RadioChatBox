<?php

/**
 * PHPUnit bootstrap — dedicated, isolated test database.
 *
 * The suite runs against a REAL PostgreSQL + Redis, but never against the
 * development database. Every database consumer (the framework
 * {@see \Pramnos\Application\Settings} store, the tracked migration runner,
 * {@see \Pramnos\Framework\Testing\TestDatabase}, and the Redis key prefix in
 * bootstrap/pramnos.php) keys off the DB_NAME environment variable, so
 * redirecting that ONE variable to a dedicated `<db>_test` database isolates the
 * whole suite from dev data.
 *
 * On every run the primary process DROPs and re-CREATEs that test database and
 * rebuilds its schema from the single tracked baseline migration
 * (app/Migrations) — so tests always see a pristine, deterministic schema with
 * no accumulated data. The Redis keyspace under the (now `_test`) prefix is
 * flushed for the same reason. This is what makes coverage deterministic: with a
 * clean database and Redis each run, a test covers the same lines every time
 * (the residual non-determinism from live external HTTP is handled separately by
 * the injectable HTTP client).
 *
 * Only runs when APP_ENV=testing (set in phpunit.xml), and refuses to proceed
 * unless the target name differs from the dev database and contains "test", so
 * it can never build or drop a production/development connection.
 */

require __DIR__ . '/../vendor/autoload.php';

// Project root — every real entry point (public/index.php, radiochatbox.php)
// defines it, and the framework Application needs it, so define it before
// anything constructs an Application.
if (!defined('ROOT')) {
    define('ROOT', dirname(__DIR__));
}

if (($_ENV['APP_ENV'] ?? getenv('APP_ENV')) !== 'testing') {
    return;
}

$root = dirname(__DIR__);

// Load .env in case a non-Docker runner relies on it for the DB_* connection
// parameters (Docker injects them directly into the container environment).
if (function_exists('loadDotenv')) {
    loadDotenv($root);
}

// ---------------------------------------------------------------------------
// Resolve the dedicated test database and redirect every consumer onto it.
// ---------------------------------------------------------------------------
$dbHost = (string) (getenv('DB_HOST') ?: 'postgres');
$dbPort = (int) (getenv('DB_PORT') ?: 5432);
$dbUser = (string) (getenv('DB_USER') ?: 'radiochatbox');
$dbPass = (string) (getenv('DB_PASSWORD') ?: 'radiochatbox_secret');
$devDb  = (string) (getenv('DB_NAME') ?: 'radiochatbox');

$testDb = (string) (getenv('TEST_DB_NAME') ?: $devDb . '_test');
// Only a safe (quoted-identifier-free) name may be interpolated into the DDL below.
$testDb = preg_replace('/[^A-Za-z0-9_]/', '', $testDb);

if ($testDb === $devDb || stripos($testDb, 'test') === false) {
    fwrite(STDERR, "[tests] refusing to run: test DB name '{$testDb}' must differ from the dev DB and contain 'test'.\n");
    exit(1);
}

// The migrate subprocess, the framework Settings store, TestDatabase and the
// Redis prefix all read DB_NAME; setting it here (before any of them run, and
// respected by loadDotenv/envvar because an already-set variable wins) points
// them all at the isolated database.
putenv("DB_NAME={$testDb}");
$_ENV['DB_NAME'] = $_SERVER['DB_NAME'] = $testDb;

// ---------------------------------------------------------------------------
// Rebuild the test database — primary process only. A cross-process lock keeps a
// second `dockertest` (or a RunInSeparateProcess subprocess) from racing on the
// DROP/CREATE + migrate.
// ---------------------------------------------------------------------------
$lockHandle = fopen(sys_get_temp_dir() . '/rcb-phpunit-bootstrap.lock', 'c');
$primary    = $lockHandle !== false && flock($lockHandle, LOCK_EX | LOCK_NB);
if ($primary) {
    // Hold the lock for the whole process so subprocesses see it as taken.
    $GLOBALS['RCB_BOOTSTRAP_LOCK'] = $lockHandle;

    try {
        $admin = new PDO(
            "pgsql:host={$dbHost};port={$dbPort};dbname=postgres",
            $dbUser,
            $dbPass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        // Evict any lingering connections (e.g. a previous run's persistent PDO)
        // before dropping, then rebuild a pristine database.
        $admin->exec(
            'SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '
            . $admin->quote($testDb) . ' AND pid <> pg_backend_pid()'
        );
        $admin->exec("DROP DATABASE IF EXISTS \"{$testDb}\"");
        $admin->exec("CREATE DATABASE \"{$testDb}\"");
        $admin = null;
    } catch (\Throwable $e) {
        fwrite(STDERR, "[tests] test database setup failed: {$e->getMessage()}\n");
        exit(1);
    }

    // Build the schema in two explicit phases (schema convergence, Phase B).
    // On a FRESH database the un-applied baseline (priority 50) and the framework
    // create_* migrations (priority 10) would share one batch, where the lower-
    // priority framework tables sort first and clobber the baseline. So we apply
    // the app migrations alone first (Phase A: baseline → rename → converge →
    // repoint → settings), which reshapes users/settings in place and frees the
    // messages/sessions names, THEN enable the framework set (Phase B), whose
    // create_users/settings become hasTable() skips and whose sessions/messages
    // create fresh. RCB_SKIP_AUTO_MIGRATE=1 stops radiochatbox.php's line-48
    // auto-migrate from pulling everything into one unordered batch first.
    // The subprocesses inherit DB_NAME={$testDb} from putenv() above.
    $php  = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/radiochatbox.php');
    $envp = 'RCB_SKIP_AUTO_MIGRATE=1 ';

    // Phase A — app migrations only (framework dirs excluded by --path).
    $outputA = (string) shell_exec($envp . $php . ' migrate --path=app/Migrations 2>&1');
    fwrite(STDERR, "[tests] migrate A app ({$testDb}):\n" . trim($outputA) . "\n");

    // Phase B — framework migrations (app.php has framework => true).
    $outputB = (string) shell_exec($envp . $php . ' migrate 2>&1');
    fwrite(STDERR, "[tests] migrate B framework ({$testDb}):\n" . trim($outputB) . "\n");
}

// ---------------------------------------------------------------------------
// Boot the framework against the test database (Settings, the Database handle
// and the Redis prefix all key off the now-redirected DB_NAME), exactly as an
// HTTP request or the console does, so tests exercise the real wiring.
// ---------------------------------------------------------------------------
require $root . '/bootstrap/pramnos.php';
radiochatbox_boot_pramnos();

// Flush the test Redis keyspace so cache/session/rate-limit state never leaks in
// from a previous run. The prefix is now `<...>_test:`-scoped, so this only ever
// clears test keys — never the dev keyspace. Primary process only.
if ($primary && class_exists(\Pramnos\Redis\ConnectionManager::class)) {
    try {
        $cm     = \Pramnos\Redis\ConnectionManager::getInstance();
        $redis  = $cm->connection();
        $prefix = $cm->prefix();
        $keys   = $redis->keys($prefix . '*');
        if (is_array($keys) && $keys !== []) {
            // The raw connection returns fully-prefixed key names; delete them as-is.
            foreach (array_chunk($keys, 500) as $chunk) {
                $redis->del($chunk);
            }
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, "[tests] warning: could not flush test Redis keyspace: {$e->getMessage()}\n");
    }
}
