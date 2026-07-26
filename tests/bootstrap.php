<?php

/**
 * PHPUnit bootstrap.
 *
 * The suite runs against a real PostgreSQL database (see BotServicePipelineTest
 * and friends), so its schema has to be current or tests fail with cryptic
 * "column ... does not exist" errors far from the real cause. As a safeguard we
 * apply every migration here before the first test runs — each file in its own
 * transaction, tolerating ones already applied — so a newly added migration can
 * never silently be missing from the test database.
 *
 * This mirrors `./radiochatbox migrate`: migrations are written to be
 * idempotent, and each runs isolated so a failure never leaves half of one
 * behind. It only touches the database when APP_ENV=testing (set in
 * phpunit.xml), so it can never run against a production connection.
 */

require __DIR__ . '/../vendor/autoload.php';

use RadioChatBox\Database;

if (($_ENV['APP_ENV'] ?? getenv('APP_ENV')) !== 'testing') {
    return;
}

$files = glob(__DIR__ . '/../database/migrations/*.sql') ?: [];
sort($files);

$pdo = Database::getPDO();
$applied = 0;
$failures = [];

foreach ($files as $file) {
    $sql = (string) file_get_contents($file);
    if (trim($sql) === '') {
        continue;
    }

    // Run the file as-is, exactly as `psql < file` would: Postgres treats a
    // multi-statement simple query as one implicit transaction and rolls the
    // whole thing back on error, and some migrations manage their own
    // BEGIN/COMMIT — so wrapping this in a PDO transaction would collide.
    try {
        $pdo->exec($sql);
        $applied++;
    } catch (\Throwable $e) {
        // Non-idempotent migrations that were already applied land here; that is
        // expected. We still record it so a genuinely broken migration is not
        // completely invisible.
        $failures[basename($file)] = strtok($e->getMessage(), "\n");
    }

    // Clear any aborted transaction a failed file may have left on the
    // persistent connection, so the next file starts clean.
    try {
        $pdo->exec('ROLLBACK');
    } catch (\Throwable) {
        // No transaction to roll back — fine.
    }
}

fwrite(STDERR, sprintf(
    "[tests] migrations: %d/%d applied cleanly.\n",
    $applied,
    count($files)
));

if ($failures !== []) {
    fwrite(STDERR, "[tests] already applied or skipped:\n");
    foreach ($failures as $name => $reason) {
        fwrite(STDERR, sprintf("        %-46s %s\n", $name, $reason));
    }
}
