<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use RadioChatBox\Database;

class DatabaseTest extends TestCase
{
    public function testGetPDOReturnsPDOInstance()
    {
        $this->markTestSkipped('Requires actual database connection');
    }

    public function testGetPDOReturnsSameInstance()
    {
        $this->markTestSkipped('Requires actual database connection');
    }

    public function testGetRedisReturnsRedisInstance()
    {
        $this->markTestSkipped('Requires actual Redis connection');
    }

    public function testGetRedisReturnsSameInstance()
    {
        $this->markTestSkipped('Requires actual Redis connection');
    }
}
