<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use Pramnos\Framework\Testing\TestDatabase;

/**
 * Guards the baseline schema migration (the SQL squash) and the migration
 * directory conventions.
 *
 * RadioChatBox's schema starts from one PramnosFramework baseline migration
 * (app/Migrations/*_create_schema.php) instead of the former database/init.sql
 * plus 22 incremental SQL files. The framework schema convergence — once five
 * separate app migrations — is squashed back into this single baseline now that
 * every production site has migrated, so the app again ships exactly one
 * migration. This guards that the baseline exists and is well-formed, that any
 * migration present follows the timestamped-filename + Migration subclass
 * convention, and that the legacy init.sql / raw SQL files stay gone.
 */
class MigrationBaselineTest extends TestCase
{
    private const MIG_DIR  = __DIR__ . '/../app/Migrations';
    private const DB_DIR   = __DIR__ . '/../database';
    private const FW_DIR   = __DIR__ . '/../vendor/mrpc/pramnosframework/database/migrations/framework';

    /**
     * The CreateSchema baseline exists (exactly one) and is well-formed.
     */
    public function testBaselineCreateSchemaMigrationExists(): void
    {
        $baseline = glob(self::MIG_DIR . '/*_create_schema.php') ?: [];
        $this->assertCount(1, $baseline, 'expected exactly one *_create_schema.php baseline');

        $src = (string) file_get_contents($baseline[0]);
        $this->assertStringContainsString('class CreateSchema extends Migration', $src);
        // Uses the schema builder for tables and raw SQL for the rest.
        $this->assertStringContainsString('$this->schema()', $src);
        $this->assertStringContainsString('$this->DB()->statement(', $src);
        $this->assertStringContainsString("createTable('users'", $src);
    }

    /**
     * Every migration file follows the YYYY_MM_DD_HHMMSS_slug.php convention and
     * declares a Migration subclass (so the runner discovers and orders them).
     */
    public function testAllMigrationsFollowConvention(): void
    {
        $files = glob(self::MIG_DIR . '/*.php') ?: [];
        $this->assertNotEmpty($files, 'expected at least the baseline migration');

        foreach ($files as $file) {
            $base = basename($file);
            $this->assertMatchesRegularExpression(
                '/^\d{4}_\d{2}_\d{2}_\d{6}_.+\.php$/',
                $base,
                "migration $base must be named YYYY_MM_DD_HHMMSS_slug.php"
            );
            $this->assertStringContainsString(
                'extends Migration',
                (string) file_get_contents($file),
                "migration $base must declare a Migration subclass"
            );
        }
    }

    /**
     * The legacy init.sql and raw per-migration SQL files were removed by the
     * squash; their presence would mean a half-finished consolidation.
     */
    public function testLegacySqlFilesAreGone(): void
    {
        $this->assertFileDoesNotExist(self::DB_DIR . '/init.sql');
        $this->assertSame(
            [],
            glob(self::DB_DIR . '/migrations/*.sql') ?: [],
            'legacy database/migrations/*.sql should have been removed by the squash'
        );
    }

    /**
     * The declared $priority of a migration file, or the Migration base default
     * (50) when it declares none — which is how the runner sees it.
     */
    private static function declaredPriority(string $file): int
    {
        $src = (string) file_get_contents($file);
        return preg_match('/\$priority\s*=\s*(\d+)\s*;/', $src, $m) ? (int) $m[1] : 50;
    }

    /**
     * The baseline must sort before every framework migration.
     *
     * MigrationRunner::sort() orders by priority first and only breaks ties on
     * the filename timestamp, so the baseline's 2025_01_01 name does NOT put it
     * first — the framework's create_users/settings/messages/sessions declare
     * priorities as low as 10. Losing this ordering is silent and expensive: the
     * framework creates `messages` first, the baseline dies on its own
     * `CREATE TABLE "messages"`, and because it is non-transactional it stops
     * there — no seed data (so no admin user), no convergence, and every later
     * app migration touching private_messages/presence_sessions fails too.
     */
    public function testBaselineOutranksEveryFrameworkMigration(): void
    {
        $baseline = glob(self::MIG_DIR . '/*_create_schema.php') ?: [];
        $this->assertCount(1, $baseline);

        $frameworkFiles = glob(self::FW_DIR . '/*/*.php') ?: [];
        $this->assertNotEmpty(
            $frameworkFiles,
            'framework migrations not found — is the PramnosFramework path repo linked?'
        );

        $lowestFramework = min(array_map([self::class, 'declaredPriority'], $frameworkFiles));

        $this->assertLessThan(
            $lowestFramework,
            self::declaredPriority($baseline[0]),
            'create_schema must declare a $priority below the lowest framework one '
            . "($lowestFramework) so it runs first"
        );
    }

    /**
     * Every migration applied cleanly when this test database was built.
     *
     * tests/bootstrap.php builds it with the same single `migrate` a fresh
     * install runs, so a failure recorded here is a fresh install that would
     * break in the field. The runner records each attempt in schemaversion with
     * result=1 on success, so a non-1 row IS the failure report.
     */
    public function testFreshInstallAppliesEveryMigrationCleanly(): void
    {
        $pdo  = TestDatabase::connection();
        $rows = $pdo->query(
            'SELECT key, error_message FROM schemaversion WHERE result <> 1 ORDER BY "when"'
        )->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertSame(
            [],
            $rows,
            "migrations failed on a fresh install:\n" . implode("\n", array_map(
                static fn(array $r): string => "  ✗ {$r['key']}: " . trim((string) $r['error_message']),
                $rows
            ))
        );

        // Guard the assertion above against a vacuous pass on an empty history.
        $applied = (int) $pdo->query('SELECT COUNT(*) FROM schemaversion')->fetchColumn();
        $this->assertGreaterThan(50, $applied, 'expected the full app + framework set to be recorded');
    }
}
