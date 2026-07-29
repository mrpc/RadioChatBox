<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database as PramnosDatabase;

/**
 * Verifies the app's database wiring after RadioChatBox\Database was retired:
 * the bootstrap (radiochatbox_boot_pramnos, run by the test bootstrap) connects
 * the framework database once, so callers use Pramnos\Database\Database::getInstance()
 * directly and get a connected, per-process singleton — no app DB seam needed.
 */
class DatabaseTest extends TestCase
{
    /**
     * The framework database is booted and connected by the app bootstrap.
     */
    public function testFrameworkDatabaseIsBootedAndConnected(): void
    {
        $db = PramnosDatabase::getInstance();

        $this->assertInstanceOf(PramnosDatabase::class, $db);
        $this->assertTrue($db->connected, 'the bootstrap must leave the framework DB connected');
    }

    /**
     * getInstance() is a per-process singleton (the persistent connection).
     */
    public function testGetInstanceReturnsSameConnection(): void
    {
        $this->assertSame(PramnosDatabase::getInstance(), PramnosDatabase::getInstance());
    }
}
