-- Migration 014: Optimize private_messages indexes for conversation queries
-- Created: 2026-01-09
-- Description: Adds composite index to speed up private message conversation queries

-- Add composite index for conversation queries
-- This optimizes queries that filter by both usernames and session_ids
CREATE INDEX IF NOT EXISTS idx_pm_conversation ON private_messages(
    from_username, to_username, from_session_id, to_session_id
);

-- This index speeds up queries like:
-- WHERE (from_username = ? AND from_session_id = ? AND to_username = ?)
--    OR (from_username = ? AND to_username = ? AND to_session_id = ?)

COMMENT ON INDEX idx_pm_conversation IS 'Optimizes private message conversation queries by covering common WHERE clauses';
