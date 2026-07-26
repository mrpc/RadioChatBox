<?php

namespace RadioChatBox\Migrations;

use Pramnos\Database\Migration;

/**
 * Migrated from database/migrations/012_backfill_all_message_user_ids.sql.
 *
 * PostgreSQL-specific SQL (plpgsql functions / triggers / guarded DO blocks /
 * data backfills) that the schema-builder DSL cannot express, kept verbatim and
 * self-contained. Idempotent, so the tracked runner records it as applied on an
 * existing database and it builds the schema on a fresh one.
 */
final class BackfillAllMessageUserIds extends Migration
{
    public $description = 'Migrated: 012_backfill_all_message_user_ids.sql';

    // The SQL manages its own transactions; do not double-wrap.
    public bool $transactional = false;

    public function up(): void
    {
        $sql = <<<'SQL'
-- Migration 012: Backfill user_id for ALL messages where username matches a registered user
-- This updates both public messages and private messages

-- Update public messages
UPDATE messages m
SET user_id = u.id
FROM users u
WHERE m.username = u.username
  AND m.user_id IS NULL;

-- Update private messages from_user_id
UPDATE private_messages pm
SET from_user_id = u.id
FROM users u
WHERE pm.from_username = u.username
  AND pm.from_user_id IS NULL;

-- Update private messages to_user_id
UPDATE private_messages pm
SET to_user_id = u.id
FROM users u
WHERE pm.to_username = u.username
  AND pm.to_user_id IS NULL;
SQL;
        $this->DB()->statement($sql);
    }

    public function down(): void
    {
        // Baselined migration: no automated rollback.
    }
}
