-- Migration 004: Allow admin users to have multiple simultaneous sessions
-- Regular users still limited to one session per username
-- Admins can join from multiple devices

-- Remove the UNIQUE constraint on username
ALTER TABLE active_users DROP CONSTRAINT IF EXISTS active_users_username_key;

-- Add a composite unique constraint on (username, session_id) instead
-- This allows same username from different sessions
ALTER TABLE active_users ADD CONSTRAINT active_users_username_session_unique UNIQUE (username, session_id);

-- Update the index since username is no longer unique by itself
-- The existing index is still useful for lookups
-- Keep idx_active_users_username for fast username lookups

-- Note: The application logic in ChatService.php has been updated to:
-- 1. Allow admin usernames to register (no longer blocked)
-- 2. Allow admin usernames to have multiple active sessions
-- 3. Regular users still enforced to one session per username
