<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use RadioChatBox\Config;

class ConfigTest extends TestCase
{
    public function testGetReturnsDefaultConfig()
    {
        $chat = Config::get('chat');
        
        $this->assertIsArray($chat);
        $this->assertArrayHasKey('history_limit', $chat);
        $this->assertArrayHasKey('message_ttl', $chat);
    }
    
    public function testGetRedisConfig()
    {
        $redis = Config::get('redis');
        
        $this->assertIsArray($redis);
        $this->assertArrayHasKey('host', $redis);
        $this->assertArrayHasKey('port', $redis);
    }
    
    public function testGetDatabaseConfig(): void
    {
        $dbConfig = Config::get('database');
        
        $this->assertIsArray($dbConfig);
        $this->assertArrayHasKey('host', $dbConfig);
        $this->assertArrayHasKey('port', $dbConfig);
        $this->assertArrayHasKey('name', $dbConfig);
        $this->assertArrayHasKey('user', $dbConfig);
        $this->assertArrayHasKey('password', $dbConfig);
    }
    
    public function testGetInvalidKeyReturnsNull()
    {
        $result = Config::get('nonexistent_key');
        
        $this->assertNull($result);
    }
}
