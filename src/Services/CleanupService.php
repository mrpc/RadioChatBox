<?php
/**
 * Cleanup Service - Handles automatic cleanup of expired data
 */

namespace RadioChatBox\Services;

use Pramnos\Cache\FlatCache;
use Pramnos\Database\Database as PramnosDatabase;

class CleanupService
{
    private PramnosDatabase $db;

    public function __construct()
    {
        $this->db = PramnosDatabase::getInstance();
    }

    /**
     * Remove expired IP bans from database
     */
    public function cleanupExpiredBans(): int
    {
        try {
            $result = $this->db->queryBuilder()
                ->from('banned_ips')
                ->whereNotNull('banned_until')
                ->whereRaw('banned_until < NOW()')
                ->delete();
            $count = $result ? $result->getAffectedRows() : 0;

            // Invalidate cache if any bans were removed
            if ($count > 0) {
                FlatCache::default()->delete('banned_ips');
                \Pramnos\Logs\Logger::log("Cleanup: Removed {$count} expired IP bans", 'radiochatbox');
            }

            return $count;
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log("Failed to cleanup expired bans: " . $e->getMessage(), 'radiochatbox');
            return 0;
        }
    }

    /**
     * Remove stale sessions (inactive for > 5 minutes)
     */
    public function cleanupStaleSessions(): int
    {
        try {
            $result = $this->db->queryBuilder()
                ->from('sessions')
                ->whereRaw("last_heartbeat < NOW() - INTERVAL '5 minutes'")
                ->delete();
            $count = $result ? $result->getAffectedRows() : 0;

            if ($count > 0) {
                \Pramnos\Logs\Logger::log("Cleanup: Removed {$count} stale sessions", 'radiochatbox');
            }

            return $count;
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log("Failed to cleanup stale sessions: " . $e->getMessage(), 'radiochatbox');
            return 0;
        }
    }

    /**
     * Purge old soft-deleted messages (> 30 days old)
     */
    public function purgeOldDeletedMessages(int $daysOld = 30): int
    {
        try {
            // INTERVAL :days DAY is not parameterisable in PostgreSQL - it parses
            // as a literal, so every run failed with a syntax error and the purge
            // silently never happened. make_interval() takes a real parameter.
            $result = $this->db->queryBuilder()
                ->from('messages')
                ->whereRaw('is_deleted = TRUE')
                ->whereRaw('created_at < NOW() - make_interval(days => %s)', [$daysOld])
                ->delete();
            $count = $result ? $result->getAffectedRows() : 0;

            if ($count > 0) {
                \Pramnos\Logs\Logger::log("Cleanup: Purged {$count} old deleted messages (>{$daysOld} days)", 'radiochatbox');
            }

            return $count;
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log("Failed to purge deleted messages: " . $e->getMessage(), 'radiochatbox');
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
            
            // Move old messages to archive. INSERT ... SELECT ... ON CONFLICT is
            // not expressible via the QueryBuilder's value-based insert, so it
            // stays a verbatim prepared statement.
            $result = $this->db->preparedQuery(
                'INSERT INTO messages_archive
                 SELECT * FROM messages
                 WHERE created_at < NOW() - make_interval(days => :days)
                 AND is_deleted = FALSE
                 ON CONFLICT (message_id) DO NOTHING',
                ['days' => $daysOld]
            );
            $archived = $result ? $result->getAffectedRows() : 0;

            // Delete from main table
            if ($archived > 0) {
                $this->db->queryBuilder()
                    ->from('messages')
                    ->whereRaw('created_at < NOW() - make_interval(days => %s)', [$daysOld])
                    ->whereRaw('is_deleted = FALSE')
                    ->delete();

                \Pramnos\Logs\Logger::log("Cleanup: Archived {$archived} old messages (>{$daysOld} days)", 'radiochatbox');
            }

            return $archived;
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log("Failed to archive old messages: " . $e->getMessage(), 'radiochatbox');
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
            'expired_dm_blocks' => $this->cleanupExpiredDmBlocks(),
        ];

        return $results;
    }

    /**
     * Remove expired DM blocks (guest-created blocks past their expires_at).
     */
    public function cleanupExpiredDmBlocks(): int
    {
        try {
            $result = $this->db->queryBuilder()
                ->from('dm_blocks')
                ->whereNotNull('expires_at')
                ->whereRaw('expires_at < NOW()')
                ->delete();
            $count = $result ? $result->getAffectedRows() : 0;
            if ($count > 0) {
                \Pramnos\Logs\Logger::log("Cleanup: Removed {$count} expired DM blocks", 'radiochatbox');
            }
            return $count;
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log("Failed to cleanup expired DM blocks: " . $e->getMessage(), 'radiochatbox');
            return 0;
        }
    }

    /**
     * Cleanup expired photos (delegated to PhotoService)
     */
    private function cleanupExpiredPhotos(): int
    {
        try {
            $photoService = new \RadioChatBox\Services\PhotoService();
            return $photoService->cleanupExpiredPhotos();
        } catch (\Exception $e) {
            \Pramnos\Logs\Logger::log("Failed to cleanup expired photos: " . $e->getMessage(), 'radiochatbox');
            return 0;
        }
    }

    /**
     * Create archive table if it doesn't exist
     */
    private function createArchiveTableIfNeeded(): void
    {
        $this->db->statement('
            CREATE TABLE IF NOT EXISTS messages_archive (
                LIKE messages INCLUDING ALL
            )
        ');
    }
}
