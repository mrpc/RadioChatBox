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

    public function testGetRedisReturnsRedisInstance()
    {
        $redis = Database::getRedis();
        $this->assertInstanceOf(\Redis::class, $redis);
        
        // Verify it's actually connected
        $this->assertTrue($redis->ping());
    }

    public function testGetRedisReturnsSameInstance()
    {
        $redis1 = Database::getRedis();
        $redis2 = Database::getRedis();
        $this->assertSame($redis1, $redis2, 'Should return the same singleton instance');
    }

    public function testGetRedisPrefixReturnsValidPrefix()
    {
        $prefix = Database::getRedisPrefix();
        
        // Should start with 'radiochatbox:'
        $this->assertStringStartsWith('radiochatbox:', $prefix);
        
        // Should end with ':'
        $this->assertStringEndsWith(':', $prefix);
        
        // Should contain database name
        $this->assertMatchesRegularExpression('/^radiochatbox:[^:]+:$/', $prefix);
    }

    public function testGetRedisPrefixIsConsistent()
    {
        $prefix1 = Database::getRedisPrefix();
        $prefix2 = Database::getRedisPrefix();
        
        $this->assertEquals($prefix1, $prefix2, 'Prefix should be consistent across calls');
    }
}
