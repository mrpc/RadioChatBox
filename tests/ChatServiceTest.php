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

    public function testRegisterUserRejectsAdminUsername()
    {
        // Try to register with an admin username
        $result = $this->chatService->registerUser(
            'admin',
            'test_session_' . time(),
            '127.0.0.1'
        );
        
        $this->assertFalse($result, 'Should reject registration with admin username');
    }

    public function testRegisterUserRejectsDuplicateActiveUsername()
    {
        $username = 'phpunit_test_' . uniqid() . '_' . time();
        $sessionId1 = 'session1_' . uniqid();
        $sessionId2 = 'session2_' . uniqid();
        
        // Register first user
        $result1 = $this->chatService->registerUser($username, $sessionId1, '127.0.0.1');
        $this->assertTrue($result1, 'First registration should succeed');
        
        // Try to register with same username but different session
        $result2 = $this->chatService->registerUser($username, $sessionId2, '127.0.0.2');
        $this->assertFalse($result2, 'Should reject duplicate username from different session');
    }

    public function testRegisterUserAllowsSameSessionToReregister()
    {
        $username = 'phpunit_test_' . uniqid() . '_' . time();
        $sessionId = 'session_' . uniqid();
        
        // Register user
        $result1 = $this->chatService->registerUser($username, $sessionId, '127.0.0.1');
        $this->assertTrue($result1, 'First registration should succeed');
        
        // Same session can re-register (e.g., after page refresh)
        $result2 = $this->chatService->registerUser($username, $sessionId, '127.0.0.1');
        $this->assertTrue($result2, 'Same session should be able to re-register');
    }
}
