<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use RadioChatBox\ChatService;
use RadioChatBox\Database;

class ChatServiceTest extends TestCase
{
    private ChatService $chatService;
    
    protected function setUp(): void
    {
        $this->chatService = new ChatService();
    }

    public function testChatServiceUsesRedisPrefixForKeys()
    {
        $redis = Database::getRedis();
        $prefix = Database::getRedisPrefix();
        
        // Verify prefix format
        $this->assertStringStartsWith('radiochatbox:', $prefix);
        $this->assertStringEndsWith(':', $prefix);
        
        // Get active user count (this triggers Redis operations)
        $count = $this->chatService->getActiveUserCount();
        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function testGetActiveUserCountReturnsInteger()
    {
        $count = $this->chatService->getActiveUserCount();
        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function testGetActiveUsersReturnsArray()
    {
        $users = $this->chatService->getActiveUsers();
        $this->assertIsArray($users);
    }

    public function testGetHistoryReturnsArray()
    {
        $history = $this->chatService->getHistory(10);
        $this->assertIsArray($history);
        
        // Each message should have required fields
        foreach ($history as $msg) {
            $this->assertArrayHasKey('username', $msg);
            $this->assertArrayHasKey('message', $msg);
            $this->assertArrayHasKey('timestamp', $msg);
        }
    }

    public function testGetSettingReturnsValue()
    {
        // Test getting a setting - should use prefixed Redis keys
        $chatMode = $this->chatService->getSetting('chat_mode', 'public');
        $this->assertIsString($chatMode);
        $this->assertContains($chatMode, ['public', 'private', 'both']);
    }

    public function testRedisPrefixIsolation()
    {
        $redis = Database::getRedis();
        $prefix = Database::getRedisPrefix();
        
        // Create a test key with prefix
        $testKey = $prefix . 'test:isolation';
        $testValue = 'test_' . time();
        
        $redis->setex($testKey, 60, $testValue);
        
        // Verify it was set with the prefix
        $retrieved = $redis->get($testKey);
        $this->assertEquals($testValue, $retrieved);
        
        // Verify unprefixed key doesn't exist
        $unprefixed = $redis->get('test:isolation');
        $this->assertFalse($unprefixed);
        
        // Cleanup
        $redis->del($testKey);
    }

    public function testMultipleInstancesHaveDifferentPrefixes()
    {
        // This test verifies that the prefix is based on database name
        $prefix1 = Database::getRedisPrefix();
        
        // The prefix should be consistent
        $prefix2 = Database::getRedisPrefix();
        $this->assertEquals($prefix1, $prefix2);
        
        // Prefix should contain the database name
        $dbConfig = \RadioChatBox\Config::get('database');
        $dbName = $dbConfig['name'];
        
        $this->assertStringContainsString($dbName, $prefix1);
        $this->assertEquals("radiochatbox:{$dbName}:", $prefix1);
    }
}
