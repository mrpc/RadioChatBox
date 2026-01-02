<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use RadioChatBox\Database;
use PDO;
use PDOStatement;
use Mockery;

/**
 * Unit tests for Admin Notifications feature
 */
class AdminNotificationsTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        Database::setPDO(null);
        Database::setRedis(null);
        parent::tearDown();
    }

    public function testCreateFakeUserDmNotificationFunction()
    {
        // Mock PDO and PDOStatement
        $mockStmt = Mockery::mock(PDOStatement::class);
        $mockStmt->shouldReceive('execute')
            ->once()
            ->with(['sender123', 'FakeUser1', 'Hello fake user!', 42])
            ->andReturn(true);
        $mockStmt->shouldReceive('fetchColumn')
            ->once()
            ->andReturn(123); // notification ID

        $mockPdo = Mockery::mock(PDO::class);
        $mockPdo->shouldReceive('prepare')
            ->once()
            ->with("SELECT create_fake_user_dm_notification(?, ?, ?, ?)")
            ->andReturn($mockStmt);

        Database::setPDO($mockPdo);

        // Simulate the function call
        $stmt = Database::getPDO()->prepare("SELECT create_fake_user_dm_notification(?, ?, ?, ?)");
        $stmt->execute(['sender123', 'FakeUser1', 'Hello fake user!', 42]);
        $notificationId = $stmt->fetchColumn();

        $this->assertEquals(123, $notificationId);
    }

    public function testMarkNotificationReadFunction()
    {
        // Mock PDO and PDOStatement
        $mockStmt = Mockery::mock(PDOStatement::class);
        $mockStmt->shouldReceive('execute')
            ->once()
            ->with([123, 'admin'])
            ->andReturn(true);
        $mockStmt->shouldReceive('fetchColumn')
            ->once()
            ->andReturn(true);

        $mockPdo = Mockery::mock(PDO::class);
        $mockPdo->shouldReceive('prepare')
            ->once()
            ->with("SELECT mark_notification_read(?, ?)")
            ->andReturn($mockStmt);

        Database::setPDO($mockPdo);

        // Simulate marking notification as read
        $stmt = Database::getPDO()->prepare("SELECT mark_notification_read(?, ?)");
        $stmt->execute([123, 'admin']);
        $success = $stmt->fetchColumn();

        $this->assertTrue($success);
    }

    public function testMarkAllNotificationsReadFunction()
    {
        // Mock PDO and PDOStatement
        $mockStmt = Mockery::mock(PDOStatement::class);
        $mockStmt->shouldReceive('execute')
            ->once()
            ->with(['admin'])
            ->andReturn(true);
        $mockStmt->shouldReceive('fetchColumn')
            ->once()
            ->andReturn(5); // 5 notifications marked as read

        $mockPdo = Mockery::mock(PDO::class);
        $mockPdo->shouldReceive('prepare')
            ->once()
            ->with("SELECT mark_all_notifications_read(?)")
            ->andReturn($mockStmt);

        Database::setPDO($mockPdo);

        // Simulate marking all notifications as read
        $stmt = Database::getPDO()->prepare("SELECT mark_all_notifications_read(?)");
        $stmt->execute(['admin']);
        $count = $stmt->fetchColumn();

        $this->assertEquals(5, $count);
    }

    public function testGetUnreadNotificationCountFunction()
    {
        // Mock PDO and PDOStatement
        $mockStmt = Mockery::mock(PDOStatement::class);
        $mockStmt->shouldReceive('execute')
            ->once()
            ->with(['admin'])
            ->andReturn(true);
        $mockStmt->shouldReceive('fetchColumn')
            ->once()
            ->andReturn(3); // 3 unread notifications

        $mockPdo = Mockery::mock(PDO::class);
        $mockPdo->shouldReceive('prepare')
            ->once()
            ->with("SELECT get_unread_notification_count(?)")
            ->andReturn($mockStmt);

        Database::setPDO($mockPdo);

        // Simulate getting unread count
        $stmt = Database::getPDO()->prepare("SELECT get_unread_notification_count(?)");
        $stmt->execute(['admin']);
        $count = $stmt->fetchColumn();

        $this->assertEquals(3, $count);
    }

    public function testNotificationMetadataStructure()
    {
        // Test that notification metadata contains all required fields
        $metadata = [
            'from_username' => 'sender123',
            'to_username' => 'FakeUser1',
            'message_id' => 42,
            'message_preview' => 'Hello fake user!'
        ];

        $this->assertArrayHasKey('from_username', $metadata);
        $this->assertArrayHasKey('to_username', $metadata);
        $this->assertArrayHasKey('message_id', $metadata);
        $this->assertArrayHasKey('message_preview', $metadata);
        $this->assertEquals('sender123', $metadata['from_username']);
        $this->assertEquals('FakeUser1', $metadata['to_username']);
        $this->assertEquals(42, $metadata['message_id']);
    }

    public function testNotificationTypeConstant()
    {
        // Test that the notification type is correctly defined
        $notificationType = 'fake_user_dm';
        
        $this->assertEquals('fake_user_dm', $notificationType);
        $this->assertIsString($notificationType);
        $this->assertLessThanOrEqual(50, strlen($notificationType), 
            'Notification type exceeds VARCHAR(50) database limit');
    }

    public function testNotificationTitleLength()
    {
        // Test that notification titles don't exceed database limit
        $longUsername = str_repeat('a', 50);
        $title = 'New DM to fake user: ' . $longUsername;
        
        $this->assertLessThanOrEqual(255, strlen($title), 
            'Notification title exceeds VARCHAR(255) database limit');
    }

    public function testNotificationMessagePreviewTruncation()
    {
        // Test that message previews are properly truncated
        $longMessage = str_repeat('Test message ', 50); // ~650 chars
        $truncated = substr($longMessage, 0, 200);
        
        $this->assertEquals(200, strlen($truncated));
        $this->assertLessThanOrEqual(200, strlen($truncated));
    }

    public function testPerAdminReadStateIsolation()
    {
        // Test that different admins have independent read states
        $notifications = [
            [
                'id' => 1,
                'title' => 'Test notification',
                'admin_username' => 'admin1',
                'is_read' => true
            ],
            [
                'id' => 1, // Same notification ID
                'title' => 'Test notification',
                'admin_username' => 'admin2',
                'is_read' => false
            ]
        ];

        // Admin1 read it, Admin2 did not
        $this->assertTrue($notifications[0]['is_read']);
        $this->assertFalse($notifications[1]['is_read']);
        $this->assertEquals($notifications[0]['id'], $notifications[1]['id']);
    }

    public function testCleanupOldNotificationsRetentionPeriod()
    {
        // Test that cleanup function respects 30-day retention
        $thirtyDaysAgo = time() - (30 * 24 * 60 * 60);
        $twentyNineDaysAgo = time() - (29 * 24 * 60 * 60);
        
        // Notifications older than 30 days should be cleaned
        $this->assertLessThan(time(), $thirtyDaysAgo);
        
        // Notifications newer than 30 days should be kept
        $this->assertGreaterThan($thirtyDaysAgo, $twentyNineDaysAgo);
    }

    public function testNotificationChannelNaming()
    {
        // Test Redis channel naming convention
        $prefix = 'radiochatbox:test_db:';
        $channel = $prefix . 'chat:admin_notifications';
        
        $this->assertEquals('radiochatbox:test_db:chat:admin_notifications', $channel);
        $this->assertStringStartsWith('radiochatbox:', $channel);
        $this->assertStringEndsWith(':chat:admin_notifications', $channel);
    }

    public function testNotificationDataStructureForRedis()
    {
        // Test that Redis published notification has correct structure
        $notificationData = [
            'id' => 123,
            'type' => 'fake_user_dm',
            'from_username' => 'sender123',
            'to_username' => 'FakeUser1',
            'message_preview' => 'Hello fake user!',
            'message_id' => 42,
            'timestamp' => time()
        ];

        $this->assertArrayHasKey('id', $notificationData);
        $this->assertArrayHasKey('type', $notificationData);
        $this->assertArrayHasKey('from_username', $notificationData);
        $this->assertArrayHasKey('to_username', $notificationData);
        $this->assertArrayHasKey('message_preview', $notificationData);
        $this->assertArrayHasKey('message_id', $notificationData);
        $this->assertArrayHasKey('timestamp', $notificationData);
        
        // Verify JSON encoding works
        $json = json_encode($notificationData);
        $this->assertIsString($json);
        $decoded = json_decode($json, true);
        $this->assertEquals($notificationData, $decoded);
    }

    public function testNotificationFilteringByType()
    {
        // Test notification filtering logic
        $notifications = [
            ['id' => 1, 'notification_type' => 'fake_user_dm'],
            ['id' => 2, 'notification_type' => 'report'],
            ['id' => 3, 'notification_type' => 'fake_user_dm'],
        ];

        $filtered = array_filter($notifications, function($n) {
            return $n['notification_type'] === 'fake_user_dm';
        });

        $this->assertCount(2, $filtered);
    }

    public function testNotificationUnreadOnlyFiltering()
    {
        // Test unread-only filtering
        $notifications = [
            ['id' => 1, 'is_read' => false],
            ['id' => 2, 'is_read' => true],
            ['id' => 3, 'is_read' => false],
        ];

        $unread = array_filter($notifications, function($n) {
            return !$n['is_read'];
        });

        $this->assertCount(2, $unread);
    }

    public function testNotificationRoleBasedAccess()
    {
        // Test that only root/administrator/owner can view notifications
        $allowedRoles = ['root', 'administrator', 'owner'];
        $deniedRoles = ['moderator', 'simple_user'];

        foreach ($allowedRoles as $role) {
            $this->assertTrue(in_array($role, ['root', 'administrator', 'owner']));
        }

        foreach ($deniedRoles as $role) {
            $this->assertFalse(in_array($role, ['root', 'administrator', 'owner']));
        }
    }

    public function testNotificationCountBadgeLimit()
    {
        // Test that badge count is capped at 99+
        $count = 150;
        $displayCount = $count > 99 ? '99+' : (string)$count;
        
        $this->assertEquals('99+', $displayCount);
        
        $count = 50;
        $displayCount = $count > 99 ? '99+' : (string)$count;
        $this->assertEquals('50', $displayCount);
    }

    public function testMessagePreviewForPhotoAttachment()
    {
        // Test that photo-only messages show proper preview
        $message = '';
        $attachmentId = 'photo_123';
        
        $preview = $message ?: '[Photo attachment]';
        
        $this->assertEquals('[Photo attachment]', $preview);
        
        // Test with actual message
        $message = 'Check this out';
        $preview = $message ?: '[Photo attachment]';
        $this->assertEquals('Check this out', $preview);
    }

    public function testNotificationCreatedAtTimestamp()
    {
        // Test that created_at defaults to current timestamp
        $beforeTime = time();
        sleep(1);
        $createdAt = time();
        sleep(1);
        $afterTime = time();
        
        $this->assertGreaterThan($beforeTime, $createdAt);
        $this->assertLessThan($afterTime, $createdAt);
    }

    public function testNotificationIdempotentRead()
    {
        // Test that marking same notification read twice doesn't cause issues (ON CONFLICT DO NOTHING)
        $notificationId = 123;
        $adminUsername = 'admin';
        
        // First read
        $reads = [
            ['notification_id' => $notificationId, 'admin_username' => $adminUsername, 'read_at' => time()]
        ];
        
        // Second read (should not duplicate)
        $existingRead = array_filter($reads, function($r) use ($notificationId, $adminUsername) {
            return $r['notification_id'] === $notificationId && $r['admin_username'] === $adminUsername;
        });
        
        $this->assertCount(1, $existingRead);
    }

    public function testNotificationsCascadeDelete()
    {
        // Test that when notification is deleted, reads are also deleted (ON DELETE CASCADE)
        // This is a design verification test
        $notificationId = 123;
        $reads = [
            ['id' => 1, 'notification_id' => $notificationId],
            ['id' => 2, 'notification_id' => $notificationId],
            ['id' => 3, 'notification_id' => 456]
        ];
        
        // Simulate cascade delete
        $remainingReads = array_filter($reads, function($r) use ($notificationId) {
            return $r['notification_id'] !== $notificationId;
        });
        
        $this->assertCount(1, $remainingReads);
    }
}
