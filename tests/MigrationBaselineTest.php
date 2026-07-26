<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Guards the PramnosFramework migration baseline (migration Phase 2).
 *
 * RadioChatBox's raw-SQL migrations in database/migrations/*.sql are each mirrored
 * by a Migration class in app/migrations/ that runs that SQL through the framework's
 * tracked runner. These tests make sure the two sets stay in lock-step: if someone
 * adds a new database/migrations/NNN_*.sql without a matching Migration class (or
 * points a class at a missing SQL file), the baseline would silently drift and the
 * tracked runner would miss a migration. That must fail loudly here instead.
 */
class MigrationBaselineTest extends TestCase
{
    private const SQL_DIR = __DIR__ . '/../database/migrations';
    private const MIG_DIR = __DIR__ . '/../app/migrations';

    /** Slug of a raw SQL file: 001_add_reply_to_messages.sql -> add_reply_to_messages */
    private static function sqlSlug(string $path): string
    {
        return preg_replace('/^\d+_/', '', basename($path, '.sql'));
    }

    /** Slug of a migration class file: 2025_01_01_000001_add_reply_to_messages.php -> add_reply_to_messages */
    private static function migrationSlug(string $path): string
    {
        return preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($path, '.php'));
    }

    /**
     * Every raw SQL migration has exactly one matching Migration class, and there
     * are no extra Migration classes without a SQL file — the two sets are equal.
     */
    public function testEverySqlMigrationHasAMatchingMigrationClass(): void
    {
        $sqlSlugs = array_map([self::class, 'sqlSlug'], glob(self::SQL_DIR . '/*.sql') ?: []);
        $migSlugs = array_map([self::class, 'migrationSlug'], glob(self::MIG_DIR . '/*.php') ?: []);

        sort($sqlSlugs);
        sort($migSlugs);

        $this->assertNotEmpty($sqlSlugs, 'No SQL migrations found — test wiring is wrong.');
        $this->assertSame(
            $sqlSlugs,
            $migSlugs,
            'database/migrations/*.sql and app/migrations/*.php have drifted apart. '
            . 'Add a Migration class for every SQL file (see docs/pramnos-migration/03-integration-plan.md Phase 2).'
        );
    }

    /**
     * Every Migration class actually implements up() — the migrations are
     * self-contained (inline SQL via $this->DB()->statement(), or the schema
     * builder), never a leftover scaffold stub. Guards against a generated-but-
     * empty migration silently doing nothing.
     */
    public function testEveryMigrationClassImplementsUp(): void
    {
        $files = glob(self::MIG_DIR . '/*.php') ?: [];
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $src = (string) file_get_contents($file);
            $name = basename($file);

            $this->assertStringNotContainsString(
                'TODO: implement',
                $src,
                "{$name} still contains the scaffold stub — up() is not implemented."
            );
            $this->assertMatchesRegularExpression(
                '/\$this->DB\(\)->statement\(|\$this->schema\(\)/',
                $src,
                "{$name} does not run any SQL or schema change in up()."
            );
        }
    }
}
