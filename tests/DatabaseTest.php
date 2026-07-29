<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database as PramnosDatabase;
use RadioChatBox\Database;

/**
 * Covers RadioChatBox\Database — the app's single connection seam onto the
 * framework database layer. (Raw-PDO seeding for tests lives in the framework's
 * init-less Pramnos\Framework\Testing\TestDatabase helper, not here.)
 */
class DatabaseTest extends TestCase
{
    /**
     * getDb() returns the framework database layer, booted and connected.
     */
    public function testGetDbReturnsConnectedFrameworkDatabase(): void
    {
        $db = Database::getDb();

        $this->assertInstanceOf(PramnosDatabase::class, $db);
        $this->assertTrue($db->connected, 'getDb() must return a connected instance');
    }

    /**
     * getDb() is a per-process singleton (mirrors the persistent connection).
     */
    public function testGetDbReturnsSameInstance(): void
    {
        $this->assertSame(Database::getDb(), Database::getDb());
    }
}
