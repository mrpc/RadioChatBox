<?php

namespace RadioChatBox\Migrations;

use Pramnos\Database\Migration;

/**
 * Migrated from database/migrations/001_add_reply_to_messages.sql.
 *
 * PostgreSQL-specific SQL (plpgsql functions / triggers / guarded DO blocks /
 * data backfills) that the schema-builder DSL cannot express, kept verbatim and
 * self-contained. Idempotent, so the tracked runner records it as applied on an
 * existing database and it builds the schema on a fresh one.
 */
final class AddReplyToMessages extends Migration
{
    public $description = 'Migrated: 001_add_reply_to_messages.sql';

    // The SQL manages its own transactions; do not double-wrap.
    public bool $transactional = false;

    public function up(): void
    {
        $sql = <<<'SQL'
-- Add reply_to column to messages table for message threading
-- This allows users to reply to specific messages and maintain conversation context

ALTER TABLE messages
ADD COLUMN IF NOT EXISTS reply_to VARCHAR(255) DEFAULT NULL;

-- Add foreign key reference (soft reference since message_id is not a true FK)
CREATE INDEX IF NOT EXISTS idx_messages_reply_to ON messages(reply_to);

-- Add comment for documentation
COMMENT ON COLUMN messages.reply_to IS 'References the message_id of the parent message being replied to';
SQL;
        $this->DB()->statement($sql);
    }

    public function down(): void
    {
        // Baselined migration: no automated rollback.
    }
}
