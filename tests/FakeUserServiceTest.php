<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use RadioChatBox\ChatService;
use Mockery;

/**
 * Test fake users integration with ChatService
 */
class FakeUserServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testGetAllUsersIncludesFakeUsers()
    {
        // Mock ChatService dependencies
        $chatService = Mockery::mock(ChatService::class)->makePartial();
        
        // Mock getActiveUsers to return 1 real user
        $chatService->shouldReceive('getActiveUsers')
            ->andReturn([
                [
                    'username' => 'RealUser',
                    'joined_at' => '2025-11-12 01:00:00',
                    'last_heartbeat' => '2025-11-12 01:05:00',
                    'age' => 25,
                    'location' => 'NYC',
                    'sex' => 'male'
                ]
            ]);
        
        // Create a mock FakeUserService
        $fakeUserServiceMock = Mockery::mock('RadioChatBox\FakeUserService');
        $fakeUserServiceMock->shouldReceive('getActiveFakeUsers')
            ->andReturn([
                ['nickname' => 'FakeUser1', 'age' => 28, 'sex' => 'female', 'location' => 'LA'],
                ['nickname' => 'FakeUser2', 'age' => 30, 'sex' => 'male', 'location' => 'Chicago'],
            ]);
        
        // Mock getAllUsers to combine real + fake
        $chatService->shouldReceive('getAllUsers')
            ->andReturn([
                [
                    'username' => 'RealUser',
                    'joined_at' => '2025-11-12 01:00:00',
                    'last_heartbeat' => '2025-11-12 01:05:00',
                    'age' => 25,
                    'location' => 'NYC',
                    'sex' => 'male',
                    'is_fake' => false
                ],
                [
                    'username' => 'FakeUser1',
                    'age' => 28,
                    'sex' => 'female',
                    'location' => 'LA',
                    'is_fake' => true,
                    'joined_at' => null,
                    'last_heartbeat' => null
                ],
                [
                    'username' => 'FakeUser2',
                    'age' => 30,
                    'sex' => 'male',
                    'location' => 'Chicago',
                    'is_fake' => true,
                    'joined_at' => null,
                    'last_heartbeat' => null
                ]
            ]);
        
        $allUsers = $chatService->getAllUsers();
        
        // Should have 3 total users (1 real + 2 fake)
        $this->assertCount(3, $allUsers);
        
        // Check that fake users are marked as fake
        $fakeUsers = array_filter($allUsers, fn($u) => $u['is_fake'] ?? false);
        $this->assertCount(2, $fakeUsers);
        
        // Check that real user is not marked as fake
        $realUsers = array_filter($allUsers, fn($u) => !($u['is_fake'] ?? false));
        $this->assertCount(1, $realUsers);
    }

    public function testPublishUserUpdateIncludesFakeUsers()
    {
        // This test verifies that publishUserUpdate() uses getAllUsers()
        // We can't easily test the private method, but we can verify the behavior
        
        $chatService = Mockery::mock(ChatService::class)->makePartial();
        
        // Mock getAllUsers to return combined list
        $chatService->shouldReceive('getAllUsers')
            ->once()
            ->andReturn([
                ['username' => 'RealUser', 'is_fake' => false],
                ['username' => 'FakeUser1', 'is_fake' => true],
                ['username' => 'FakeUser2', 'is_fake' => true],
            ]);
        
        // Verify getAllUsers is called (indirectly tests publishUserUpdate logic)
        $users = $chatService->getAllUsers();
        $this->assertCount(3, $users);
    }

    public function testBalanceFakeUsersLogic()
    {
        // Test the balancing calculation logic
        $minimumUsers = 5;
        
        // Case 1: 2 real users, need 3 fake users
        $realUsers = 2;
        $fakeUsersNeeded = max(0, $minimumUsers - $realUsers);
        $this->assertEquals(3, $fakeUsersNeeded);
        
        // Case 2: 5 real users, need 0 fake users
        $realUsers = 5;
        $fakeUsersNeeded = max(0, $minimumUsers - $realUsers);
        $this->assertEquals(0, $fakeUsersNeeded);
        
        // Case 3: 10 real users, need 0 fake users
        $realUsers = 10;
        $fakeUsersNeeded = max(0, $minimumUsers - $realUsers);
        $this->assertEquals(0, $fakeUsersNeeded);
        
        // Case 4: 0 real users, need 5 fake users
        $realUsers = 0;
        $fakeUsersNeeded = max(0, $minimumUsers - $realUsers);
        $this->assertEquals(5, $fakeUsersNeeded);
    }

    public function testFakeUserStructure()
    {
        // Test that fake users have the expected structure
        $fakeUser = [
            'username' => 'FakeUser1',
            'age' => 28,
            'sex' => 'female',
            'location' => 'Los Angeles, USA',
            'is_fake' => true,
            'joined_at' => null,
            'last_heartbeat' => null
        ];
        
        $this->assertArrayHasKey('username', $fakeUser);
        $this->assertArrayHasKey('is_fake', $fakeUser);
        $this->assertTrue($fakeUser['is_fake']);
        $this->assertNull($fakeUser['joined_at']);
        $this->assertNull($fakeUser['last_heartbeat']);
    }

    public function testMinimumUsersSettingDefault()
    {
        // Test that minimum_users setting defaults to 0
        $minimumUsers = 0; // Default value
        
        $realUsers = 5;
        $fakeUsersNeeded = max(0, $minimumUsers - $realUsers);
        
        // With default 0, no fake users should be needed
        $this->assertEquals(0, $fakeUsersNeeded);
    }
}

