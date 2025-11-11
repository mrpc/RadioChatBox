<?php
/**
 * Cleanup Service - Handles automatic cleanup of expired data
 */

namespace RadioChatBox;

use PDO;

class CleanupService
{
    private PDO $pdo;
    private \Redis $redis;

    public function __construct()
    {
        $this->pdo = Database::getPDO();
        $this->redis = Database::getRedis();
    }

    /**
     * Remove expired IP bans from database
     */
    public function cleanupExpiredBans(): int
    {
        try {
            $stmt = $this->pdo->prepare(
                'DELETE FROM banned_ips 
                 WHERE banned_until IS NOT NULL 
                 AND banned_until < NOW()'
            );
            $stmt->execute();
            $count = $stmt->rowCount();
            
            // Invalidate cache if any bans were removed
            if ($count > 0) {
                $this->redis->del('banned_ips');
                error_log("Cleanup: Removed {$count} expired IP bans");
            }
            
            return $count;
        } catch (\PDOException $e) {
            error_log("Failed to cleanup expired bans: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Remove stale sessions (inactive for > 5 minutes)
     */
    public function cleanupStaleSessions(): int
    {
        try {
            $stmt = $this->pdo->prepare(
                'DELETE FROM active_users 
                 WHERE last_heartbeat < NOW() - INTERVAL \'5 minutes\''
            );
            $stmt->execute();
            $count = $stmt->rowCount();
            
            if ($count > 0) {
                error_log("Cleanup: Removed {$count} stale sessions");
            }
            
            return $count;
        } catch (\PDOException $e) {
            error_log("Failed to cleanup stale sessions: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Purge old soft-deleted messages (> 30 days old)
     */
    public function purgeOldDeletedMessages(int $daysOld = 30): int
    {
        try {
            $stmt = $this->pdo->prepare(
                'DELETE FROM messages 
                 WHERE is_deleted = TRUE 
                 AND created_at < NOW() - INTERVAL :days DAY'
            );
            $stmt->bindValue(':days', $daysOld, PDO::PARAM_INT);
            $stmt->execute();
            $count = $stmt->rowCount();
            
            if ($count > 0) {
                error_log("Cleanup: Purged {$count} old deleted messages (>{$daysOld} days)");
            }
            
            return $count;
        } catch (\PDOException $e) {
            error_log("Failed to purge deleted messages: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Archive old messages (> 90 days) to reduce active table size
     * This could move messages to an archive table for historical purposes
     */
    public function archiveOldMessages(int $daysOld = 90): int
    {
        try {
            // First, ensure archive table exists
            $this->createArchiveTableIfNeeded();
            
            // Move old messages to archive
            $stmt = $this->pdo->prepare(
                'INSERT INTO messages_archive 
                 SELECT * FROM messages 
                 WHERE created_at < NOW() - INTERVAL :days DAY 
                 AND is_deleted = FALSE
                 ON CONFLICT (message_id) DO NOTHING'
            );
            $stmt->bindValue(':days', $daysOld, PDO::PARAM_INT);
            $stmt->execute();
            $archived = $stmt->rowCount();
            
            // Delete from main table
            if ($archived > 0) {
                $stmt = $this->pdo->prepare(
                    'DELETE FROM messages 
                     WHERE created_at < NOW() - INTERVAL :days DAY 
                     AND is_deleted = FALSE'
                );
                $stmt->bindValue(':days', $daysOld, PDO::PARAM_INT);
                $stmt->execute();
                
                error_log("Cleanup: Archived {$archived} old messages (>{$daysOld} days)");
            }
            
            return $archived;
        } catch (\PDOException $e) {
            error_log("Failed to archive old messages: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Run all cleanup tasks
     */
    public function runAll(): array
    {
        $results = [
            'expired_bans' => $this->cleanupExpiredBans(),
            'stale_sessions' => $this->cleanupStaleSessions(),
            'deleted_messages' => $this->purgeOldDeletedMessages(30),
            'expired_photos' => $this->cleanupExpiredPhotos(),
        ];
        
        return $results;
    }

    /**
     * Cleanup expired photos (delegated to PhotoService)
     */
    private function cleanupExpiredPhotos(): int
    {
        try {
            $photoService = new \RadioChatBox\PhotoService();
            return $photoService->cleanupExpiredPhotos();
        } catch (\Exception $e) {
            error_log("Failed to cleanup expired photos: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Create archive table if it doesn't exist
     */
    private function createArchiveTableIfNeeded(): void
    {
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS messages_archive (
                LIKE messages INCLUDING ALL
            )
        ');
    }
}
