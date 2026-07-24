-- Migration 020: Add pinned_track column to messages
-- Description: Lets a public chat message pin the track that was playing when
--              it was sent, so later readers understand which song the message
--              refers to. Stores a text snapshot of the now-playing display.
-- Date: 2026-07-24

ALTER TABLE messages ADD COLUMN IF NOT EXISTS pinned_track VARCHAR(500) DEFAULT NULL;

COMMENT ON COLUMN messages.pinned_track IS 'Snapshot of the now-playing track pinned to this message (NULL if none).';
