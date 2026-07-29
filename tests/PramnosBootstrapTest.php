<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use RadioChatBox\Config;

/**
 * Covers the PramnosFramework coexistence bootstrap (migration Phase 1).
 *
 * Why: the first foundation step of adopting the framework is to make its
 * \Pramnos\Application\Settings store available to RadioChatBox, populated from
 * the SAME `.env`-derived configuration RadioChatBox\Config already owns. These
 * tests verify (a) the bootstrap loads the framework safely, (b) the framework
 * ends up seeing exactly the same database/cache values as Config — so the two
 * config planes can never diverge during the bridge phase — and (c) the bootstrap
 * is a safe, idempotent no-op rather than something that can break an endpoint.
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
     * mapping RadioChatBox\Config values onto framework keys (host->hostname,
     * name->database). This runs without the framework, so it always executes.
     */
    public function testSettingsFileMapsConfigOntoFrameworkKeys(): void
    {
        $settings = require __DIR__ . '/../app/settings/settings.php';
        $db = Config::get('database');

        $this->assertIsArray($settings);
        $this->assertArrayHasKey('database', $settings);
        $this->assertArrayHasKey('cache', $settings);

        // Framework key <= Config key.
        $this->assertSame($db['host'], $settings['database']['hostname']);
        $this->assertSame($db['port'], $settings['database']['port']);
        $this->assertSame($db['name'], $settings['database']['database']);
        $this->assertSame($db['user'], $settings['database']['user']);
        $this->assertSame($db['password'], $settings['database']['password']);
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
     * the framework sees exactly the same database configuration as Config —
     * proving the two config planes agree.
     */
    public function testFrameworkSeesSameDatabaseConfigAsConfig(): void
    {
        if (!extension_loaded('mbstring') || !class_exists(\Pramnos\Application\Settings::class)) {
            $this->markTestSkipped('PramnosFramework not loadable in this environment (mbstring missing).');
        }

        $this->assertTrue(radiochatbox_boot_pramnos(), 'Bootstrap should report success.');
        // Idempotent: a second call is still true and does not throw.
        $this->assertTrue(radiochatbox_boot_pramnos());

        $db = Config::get('database');
        $fw = \Pramnos\Application\Settings::getSetting('database');

        // getSetting() returns an array-value as an object.
        $this->assertIsObject($fw);
        $this->assertSame($db['host'], $fw->hostname);
        $this->assertSame($db['port'], $fw->port);
        $this->assertSame($db['name'], $fw->database);
        $this->assertSame('postgresql', $fw->type);
    }

    /**
     * The cache plane must also agree: the framework's Redis cache config carries
     * the same host/port RadioChatBox already uses, under the app's per-database
     * key prefix so framework and app keys never collide.
     */
    public function testFrameworkSeesSameCacheConfigAsConfig(): void
    {
        if (!extension_loaded('mbstring') || !class_exists(\Pramnos\Application\Settings::class)) {
            $this->markTestSkipped('PramnosFramework not loadable in this environment (mbstring missing).');
        }

        radiochatbox_boot_pramnos();

        $redis = Config::get('redis');
        $cache = \Pramnos\Application\Settings::getSetting('cache');

        $this->assertIsObject($cache);
        $this->assertSame('redis', $cache->method);
        $this->assertSame($redis['host'], $cache->hostname);
        $this->assertSame($redis['port'], $cache->port);
        $this->assertSame(\Pramnos\Redis\ConnectionManager::getInstance()->prefix(), $cache->prefix);
    }
}
