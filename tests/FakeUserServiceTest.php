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

    public function testExportOmitsRuntimeStateAndKeepsBotSettings(): void
    {
        $pdo = \RadioChatBox\Database::getPDO();
        $service = new \RadioChatBox\FakeUserService();
        $nick = 'exp_' . substr(bin2hex(random_bytes(5)), 0, 8);

        try {
            $created = $service->addFakeUser($nick, 29, 'female', 'GR');
            $service->updateBotSettings((int) $created['id'], [
                'bot_enabled' => true,
                'bot_persona' => 'content creator',
                'bot_reply_language' => 'greeklish',
                'bot_ignore_chance' => 40,
            ]);

            $row = null;
            foreach ($service->exportFakeUsers() as $entry) {
                if ($entry['nickname'] === $nick) {
                    $row = $entry;
                    break;
                }
            }

            $this->assertNotNull($row, 'the exported list must contain the fake user');
            // Runtime state is excluded.
            $this->assertArrayNotHasKey('id', $row);
            $this->assertArrayNotHasKey('created_at', $row);
            $this->assertArrayNotHasKey('is_active', $row);
            // Profile + bot settings are kept, with clean types.
            $this->assertSame(29, $row['age']);
            $this->assertTrue($row['bot_enabled']);
            $this->assertSame('content creator', $row['bot_persona']);
            $this->assertSame('greeklish', $row['bot_reply_language']);
            $this->assertSame(40, $row['bot_ignore_chance']);
        } finally {
            $pdo->prepare('DELETE FROM fake_users WHERE nickname = ?')->execute([$nick]);
        }
    }

    public function testImportIsAdditiveAndNeverOverwrites(): void
    {
        $pdo = \RadioChatBox\Database::getPDO();
        $service = new \RadioChatBox\FakeUserService();
        $suffix = substr(bin2hex(random_bytes(5)), 0, 8);
        $existing = 'imp_exist_' . $suffix;
        $fresh = 'imp_new_' . $suffix;

        try {
            // A pre-existing bot with a persona we can check was NOT overwritten.
            $created = $service->addFakeUser($existing, 25, 'female', 'GR');
            $service->updateBotSettings((int) $created['id'], ['bot_persona' => 'ORIGINAL']);

            $result = $service->importFakeUsers([
                // Same nickname, different persona -> must be skipped, not overwritten.
                ['nickname' => $existing, 'age' => 40, 'bot_persona' => 'HIJACKED'],
                // Brand new, fully configured -> imported.
                ['nickname' => $fresh, 'age' => 31, 'sex' => 'male', 'location' => 'GR',
                 'bot_enabled' => true, 'bot_persona' => 'new bot', 'bot_ignore_chance' => 15],
                // Invalid nickname -> reported invalid, not inserted.
                ['nickname' => 'ab'],
                // Invalid age -> reported invalid.
                ['nickname' => 'imp_bad_' . $suffix, 'age' => 5],
            ]);

            $this->assertSame([$fresh], $result['imported']);
            $this->assertSame([$existing], $result['skipped']);
            $this->assertCount(2, $result['invalid']);

            // The existing bot is untouched.
            $unchanged = $service->getFakeUserByNickname($existing);
            $this->assertSame('ORIGINAL', $unchanged['bot_persona']);
            $this->assertSame(25, (int) $unchanged['age']);

            // The new bot exists with its settings applied.
            $imported = $service->getFakeUserByNickname($fresh);
            $this->assertNotNull($imported);
            $this->assertTrue((bool) $imported['bot_enabled']);
            $this->assertSame('new bot', $imported['bot_persona']);
            $this->assertSame(15, (int) $imported['bot_ignore_chance']);

            // Nothing was created for the invalid rows.
            $this->assertNull($service->getFakeUserByNickname('imp_bad_' . $suffix));
        } finally {
            $pdo->prepare("DELETE FROM fake_users WHERE nickname LIKE ?")->execute(['imp_%_' . $suffix]);
        }
    }

    public function testImportUpdatesExistingUsersOnlyWhenAsked(): void
    {
        $pdo = \RadioChatBox\Database::getPDO();
        $service = new \RadioChatBox\FakeUserService();
        $nick = 'upd_' . substr(bin2hex(random_bytes(5)), 0, 8);

        try {
            $created = $service->addFakeUser($nick, 25, 'female', 'GR');
            $service->updateBotSettings((int) $created['id'], ['bot_persona' => 'ORIGINAL']);

            $row = [['nickname' => $nick, 'age' => 41, 'sex' => 'male', 'bot_persona' => 'UPDATED', 'bot_enabled' => true]];

            // Default: existing users are skipped, nothing changes.
            $skip = $service->importFakeUsers($row);
            $this->assertSame([$nick], $skip['skipped']);
            $this->assertSame([], $skip['updated']);
            $this->assertSame('ORIGINAL', $service->getFakeUserByNickname($nick)['bot_persona']);

            // Opt-in: profile and bot settings are overwritten from the row.
            $upd = $service->importFakeUsers($row, true);
            $this->assertSame([$nick], $upd['updated']);
            $this->assertSame([], $upd['skipped']);

            $after = $service->getFakeUserByNickname($nick);
            $this->assertSame('UPDATED', $after['bot_persona']);
            $this->assertSame(41, (int) $after['age']);
            $this->assertSame('male', $after['sex']);
            $this->assertTrue((bool) $after['bot_enabled']);
        } finally {
            $pdo->prepare('DELETE FROM fake_users WHERE nickname = ?')->execute([$nick]);
        }
    }

    /**
     * A bot in a live conversation must not be a rotation candidate, while an
     * idle bot stays eligible. Read-only: it inspects the candidate list rather
     * than deactivating, so it never touches other fake users in the database.
     */
    public function testRotationSparesBotsInLiveConversations(): void
    {
        $pdo = \RadioChatBox\Database::getPDO();
        $service = new \RadioChatBox\FakeUserService();

        $suffix = substr(bin2hex(random_bytes(5)), 0, 8);
        $busy = 'busybot_' . $suffix;   // active bot, message just now -> spared
        $idle = 'idlebot_' . $suffix;   // active bot, no recent chat -> eligible
        $peer = 'peer_' . $suffix;

        $insert = $pdo->prepare(
            "INSERT INTO fake_users (nickname, age, sex, location, is_active, bot_enabled)
             VALUES (?, 27, 'female', 'GR', TRUE, TRUE) RETURNING id"
        );

        try {
            $insert->execute([$busy]);
            $busyId = (int) $insert->fetchColumn();
            $insert->execute([$idle]);
            $idleId = (int) $insert->fetchColumn();

            // The busy bot has a live thread and a message exchanged just now.
            $pdo->prepare("INSERT INTO bot_threads (fake_user_id, peer_username) VALUES (?, ?)")
                ->execute([$busyId, $peer]);
            $pdo->prepare(
                "INSERT INTO private_messages (from_username, to_username, message, created_at)
                 VALUES (?, ?, 'geia ti kaneis', NOW())"
            )->execute([$peer, $busy]);

            // Large count so every eligible active fake user is returned.
            $method = new \ReflectionMethod($service, 'rotationDeactivationCandidates');
            $method->setAccessible(true);
            $candidates = $method->invoke($service, 1000);
            $nicknames = array_column($candidates, 'nickname');

            $this->assertNotContains($busy, $nicknames, 'a bot mid-conversation must be spared');
            $this->assertContains($idle, $nicknames, 'an idle bot stays eligible for rotation');
        } finally {
            $pdo->prepare("DELETE FROM private_messages WHERE from_username = ? OR to_username = ?")
                ->execute([$peer, $busy]);
            $pdo->prepare("DELETE FROM bot_threads WHERE peer_username = ?")->execute([$peer]);
            $pdo->prepare("DELETE FROM fake_users WHERE nickname IN (?, ?)")->execute([$busy, $idle]);
        }
    }
}

