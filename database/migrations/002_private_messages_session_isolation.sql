-- Migration: Add session isolation to private messages
-- This prevents users from seeing private messages from previous users with the same username

-- Add session_id columns to private_messages
ALTER TABLE private_messages 
ADD COLUMN IF NOT EXISTS from_session_id VARCHAR(255),
ADD COLUMN IF NOT EXISTS to_session_id VARCHAR(255);

-- Create indexes for session-based queries
CREATE INDEX IF NOT EXISTS idx_private_messages_from_session ON private_messages(from_username, from_session_id);
CREATE INDEX IF NOT EXISTS idx_private_messages_to_session ON private_messages(to_username, to_session_id);

-- Add comment explaining the change
COMMENT ON COLUMN private_messages.from_session_id IS 'Session ID of sender - ensures message isolation between different users using same username';
COMMENT ON COLUMN private_messages.to_session_id IS 'Session ID of recipient - ensures message isolation between different users using same username';

-- Note: Existing messages without session_id will not be visible to new sessions
-- This is intentional for privacy - old messages are orphaned when users log out
