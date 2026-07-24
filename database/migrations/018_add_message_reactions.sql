-- Migration 018: Add message_reactions table
-- Description: Emoji reactions on public chat messages. Each user may have at
--              most ONE reaction per message (choosing a different emoji
--              replaces the previous one; choosing the same emoji removes it).
--              Reactions reference the app-level messages.message_id (VARCHAR),
--              not the numeric primary key. The allowed emoji set is enforced in
--              application code (ReactionService).
-- Date: 2026-07-24

CREATE TABLE IF NOT EXISTS message_reactions (
    id SERIAL PRIMARY KEY,
    message_id VARCHAR(255) NOT NULL,
    username VARCHAR(100) NOT NULL,
    session_id VARCHAR(255),
    emoji VARCHAR(16) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- One reaction per user per message.
    CONSTRAINT uq_message_reactions_user UNIQUE (message_id, username)
);

-- Aggregation queries fetch all reactions for a set of message_ids
CREATE INDEX IF NOT EXISTS idx_message_reactions_message ON message_reactions(message_id);

-- Reconcile older installs that used a (message_id, username, emoji) unique
-- (which allowed multiple emojis per user): drop it, dedupe to one row per
-- (message_id, username) keeping the most recent, then add the one-per-user
-- unique. All idempotent.
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'uq_message_reactions') THEN
        ALTER TABLE message_reactions DROP CONSTRAINT uq_message_reactions;
    END IF;

    DELETE FROM message_reactions mr
    USING message_reactions keep
    WHERE mr.message_id = keep.message_id
      AND mr.username = keep.username
      AND mr.id < keep.id;

    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'uq_message_reactions_user') THEN
        ALTER TABLE message_reactions
            ADD CONSTRAINT uq_message_reactions_user UNIQUE (message_id, username);
    END IF;
END$$;

-- FK to messages(message_id): messages.message_id is UNIQUE, so this is valid.
-- Wrapped in a DO block so the migration stays idempotent (ADD CONSTRAINT has no
-- IF NOT EXISTS in older PostgreSQL).
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'fk_message_reactions_message'
    ) THEN
        ALTER TABLE message_reactions
            ADD CONSTRAINT fk_message_reactions_message
            FOREIGN KEY (message_id) REFERENCES messages(message_id) ON DELETE CASCADE;
    END IF;
END$$;

COMMENT ON TABLE message_reactions IS 'Emoji reactions on public messages. One reaction per (message, user); a new emoji replaces the previous one.';
