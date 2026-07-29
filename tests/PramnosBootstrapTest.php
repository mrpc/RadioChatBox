<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Covers the PramnosFramework coexistence bootstrap (migration Phase 1, updated
 * for Phase C's native-config move).
 *
 * Why: the bootstrap makes the framework's \Pramnos\Application\Settings store
 * available to RadioChatBox, populated from the SAME environment the app reads
 * with the native envvar() helper (after loadDotenv()). These tests verify
 * (a) the bootstrap loads the framework safely, (b) the framework ends up seeing
 * exactly the same database/cache values as envvar() — so the two config planes
 * can never diverge — and (c) the bootstrap is a safe, idempotent no-op rather
 * than something that can break an endpoint.
 *
 * The framework core needs the mbstring extension; in environments without it
 * (e.g. an un-provisioned CLI) the framework-dependent assertions are skipped,
 * mirroring the bootstrap's own safe-no-op contract.
 */
class PramnosBootstrapTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // The bootstrap file only defines the function; it is not autoloaded.
        require_once __DIR__ . '/../bootstrap/pramnos.php';
    }

    /**
     * The settings file must be a plain array in the shape the framework expects,
     * mapping the environment onto framework keys (DB_HOST->hostname,
     * DB_NAME->database). This runs without the framework, so it always executes.
     */
    public function testSettingsFileMapsEnvOntoFrameworkKeys(): void
    {
        $settings = require __DIR__ . '/../app/settings/settings.php';

        $this->assertIsArray($settings);
        $this->assertArrayHasKey('database', $settings);
        $this->assertArrayHasKey('cache', $settings);

        // Framework key <= environment value (same default as the app reads).
        $this->assertSame((string) envvar('DB_HOST', 'postgres'), $settings['database']['hostname']);
        $this->assertSame((int) envvar('DB_PORT', 5432), $settings['database']['port']);
        $this->assertSame((string) envvar('DB_NAME', 'radiochatbox'), $settings['database']['database']);
        $this->assertSame((string) envvar('DB_USER', 'radiochatbox'), $settings['database']['user']);
        $this->assertSame((string) envvar('DB_PASSWORD', 'radiochatbox_secret'), $settings['database']['password']);
        // PostgreSQL is a first-class framework dialect; the bridge pins it explicitly.
        $this->assertSame('postgresql', $settings['database']['type']);
        $this->assertSame('public', $settings['database']['schema']);
        $this->assertSame('redis', $settings['cache']['method']);
    }

    /**
     * Booting the framework must be a safe no-op when the framework cannot load
     * (mbstring missing): it returns false rather than throwing.
     */
    public function testBootIsSafeWhenFrameworkUnavailable(): void
    {
        if (extension_loaded('mbstring') && class_exists(\Pramnos\Application\Settings::class)) {
            $this->markTestSkipped('Framework is available here; the no-op path is covered elsewhere.');
        }

        $this->assertFalse(radiochatbox_boot_pramnos());
    }

    /**
     * When the framework is available, the bootstrap loads its Settings store and
     * the framework sees exactly the same database configuration as envvar() —
     * proving the two config planes agree.
     */
    public function testFrameworkSeesSameDatabaseConfigAsEnv(): void
    {
        if (!extension_loaded('mbstring') || !class_exists(\Pramnos\Application\Settings::class)) {
            $this->markTestSkipped('PramnosFramework not loadable in this environment (mbstring missing).');
        }

        $this->assertTrue(radiochatbox_boot_pramnos(), 'Bootstrap should report success.');
        // Idempotent: a second call is still true and does not throw.
        $this->assertTrue(radiochatbox_boot_pramnos());

        $fw = \Pramnos\Application\Settings::getSetting('database');

        // getSetting() returns an array-value as an object.
        $this->assertIsObject($fw);
        $this->assertSame((string) envvar('DB_HOST', 'postgres'), $fw->hostname);
        $this->assertSame((int) envvar('DB_PORT', 5432), $fw->port);
        $this->assertSame((string) envvar('DB_NAME', 'radiochatbox'), $fw->database);
        $this->assertSame('postgresql', $fw->type);
    }

    /**
     * The cache plane must also agree: the framework's Redis cache config carries
     * the same host/port RadioChatBox already uses, under the app's per-database
     * key prefix so framework and app keys never collide.
     */
    public function testFrameworkSeesSameCacheConfigAsEnv(): void
    {
        if (!extension_loaded('mbstring') || !class_exists(\Pramnos\Application\Settings::class)) {
            $this->markTestSkipped('PramnosFramework not loadable in this environment (mbstring missing).');
        }

        radiochatbox_boot_pramnos();

        $cache = \Pramnos\Application\Settings::getSetting('cache');

        $this->assertIsObject($cache);
        $this->assertSame('redis', $cache->method);
        $this->assertSame((string) envvar('REDIS_HOST', 'redis'), $cache->hostname);
        $this->assertSame((int) envvar('REDIS_PORT', 6379), $cache->port);
        $this->assertSame(\Pramnos\Redis\ConnectionManager::getInstance()->prefix(), $cache->prefix);
    }
}
