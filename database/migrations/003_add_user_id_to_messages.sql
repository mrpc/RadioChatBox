-- Migration: Add user_id columns for future registered user support
-- Currently system uses session-based anonymous users, but this prepares for proper user accounts

-- Add user_id columns to private_messages
ALTER TABLE private_messages 
ADD COLUMN IF NOT EXISTS from_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
ADD COLUMN IF NOT EXISTS to_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL;

-- Add user_id column to messages (public chat)
ALTER TABLE messages
ADD COLUMN IF NOT EXISTS user_id INTEGER REFERENCES users(id) ON DELETE SET NULL;

-- Create indexes for user-based queries
CREATE INDEX IF NOT EXISTS idx_private_messages_from_user ON private_messages(from_user_id);
CREATE INDEX IF NOT EXISTS idx_private_messages_to_user ON private_messages(to_user_id);
CREATE INDEX IF NOT EXISTS idx_messages_user ON messages(user_id);

-- Add comments explaining the design
COMMENT ON COLUMN private_messages.from_user_id IS 'User ID of sender if logged in as registered user (NULL for anonymous/session-only users)';
COMMENT ON COLUMN private_messages.to_user_id IS 'User ID of recipient if logged in as registered user (NULL for anonymous/session-only users)';
COMMENT ON COLUMN messages.user_id IS 'User ID if message sent by registered user (NULL for anonymous/session-only users)';

-- Note: This is nullable and optional
-- Current flow: username + session_id (anonymous users)
-- Future flow: username + session_id + user_id (registered users)
-- Registered users will have persistent user_id that survives across sessions
-- Anonymous users will continue to use session_id only
