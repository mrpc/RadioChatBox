<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use RadioChatBox\Database;

class DatabaseTest extends TestCase
{
    public function testGetPDOReturnsPDOInstance()
    {
        $pdo = Database::getPDO();
        $this->assertInstanceOf(\PDO::class, $pdo);
    }

    public function testGetPDOReturnsSameInstance()
    {
        $pdo1 = Database::getPDO();
        $pdo2 = Database::getPDO();
        $this->assertSame($pdo1, $pdo2, 'Should return the same singleton instance');
    }
}
