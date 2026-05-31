-- Migration: Add edited_at column to messages
-- Description: Track when a public message was last edited by its author
-- Date: 2026-05-31

ALTER TABLE messages ADD COLUMN IF NOT EXISTS edited_at TIMESTAMP WITH TIME ZONE DEFAULT NULL;

COMMENT ON COLUMN messages.edited_at IS 'Timestamp of last edit by the message author. NULL means never edited.';
