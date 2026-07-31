<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Guards the baseline schema migration (the SQL squash) and the migration
 * directory conventions.
 *
 * RadioChatBox's schema starts from one PramnosFramework baseline migration
 * (app/Migrations/*_create_schema.php) instead of the former database/init.sql
 * plus 22 incremental SQL files. Schema convergence onto the framework adds
 * further app migrations alongside it, so this no longer requires a *single*
 * file — it requires that the baseline still exists and is well-formed, that
 * every additional migration follows the timestamped-filename + Migration
 * subclass convention, and that the legacy init.sql / raw SQL files stay gone.
 */
class MigrationBaselineTest extends TestCase
{
    private const MIG_DIR  = __DIR__ . '/../app/Migrations';
    private const DB_DIR   = __DIR__ . '/../database';

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
}
