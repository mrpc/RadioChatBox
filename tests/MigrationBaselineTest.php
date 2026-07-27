<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Guards the single baseline schema migration (the SQL squash).
 *
 * RadioChatBox's schema is created by one PramnosFramework migration
 * (app/Migrations/*_create_schema.php) instead of the former database/init.sql
 * plus 22 incremental SQL files. These checks make sure that consolidation stays
 * intact: exactly one migration exists, it implements up() using the schema
 * builder and raw SQL, and the legacy init.sql / raw migration files are gone
 * (so nothing silently falls back to them).
 */
class MigrationBaselineTest extends TestCase
{
    private const MIG_DIR  = __DIR__ . '/../app/Migrations';
    private const DB_DIR   = __DIR__ . '/../database';

    /**
     * There is exactly one migration and it is the CreateSchema baseline.
     */
    public function testSingleCreateSchemaMigrationExists(): void
    {
        $files = glob(self::MIG_DIR . '/*.php') ?: [];
        $this->assertCount(1, $files, 'expected a single baseline migration');
        $this->assertStringEndsWith('_create_schema.php', $files[0]);

        $src = (string) file_get_contents($files[0]);
        $this->assertStringContainsString('class CreateSchema extends Migration', $src);
        // Uses the schema builder for tables and raw SQL for the rest.
        $this->assertStringContainsString('$this->schema()', $src);
        $this->assertStringContainsString('$this->DB()->statement(', $src);
        $this->assertStringContainsString("createTable('users'", $src);
        $this->assertStringContainsString("enumType('role'", $src);
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
