-- Migration 005: Rename Tables to Logical Structure
-- Created: 2025-12-31
-- Description: Refactors user-related tables to reflect their actual purpose:
--   - admin_users → users (authenticated accounts with passwords/roles)
--   - users → user_activity (historical chat participation audit log)
--   - active_users → sessions (current online sessions - anonymous + authenticated)
-- Also adds user_id foreign keys to support future hybrid anonymous/authenticated model

BEGIN;

-- ============================================================================
-- STEP 1: Rename enum type admin_role → user_role
-- ============================================================================

ALTER TYPE admin_role RENAME TO user_role;

-- ============================================================================
-- STEP 2: Rename tables in correct dependency order
-- ============================================================================

-- Rename users → user_activity first (no incoming FKs except messages/private_messages which are nullable)
ALTER TABLE users RENAME TO user_activity;

-- Rename active_users → sessions (no incoming FKs)
ALTER TABLE active_users RENAME TO sessions;

-- Rename admin_users → users last (has self-referencing FK)
ALTER TABLE admin_users RENAME TO users;

-- ============================================================================
-- STEP 3: Add user_id columns to sessions and user_activity
-- ============================================================================

-- Add user_id to sessions table
-- NULL = anonymous session, NOT NULL = authenticated user session
ALTER TABLE sessions 
ADD COLUMN IF NOT EXISTS user_id INTEGER REFERENCES users(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_sessions_user ON sessions(user_id);

COMMENT ON COLUMN sessions.user_id IS 'References authenticated user account (NULL for anonymous users, NOT NULL for logged-in users)';

-- Add user_id to user_activity table
-- NULL = anonymous participant, NOT NULL = authenticated user's activity
ALTER TABLE user_activity 
ADD COLUMN IF NOT EXISTS user_id INTEGER REFERENCES users(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_user_activity_user ON user_activity(user_id);

COMMENT ON COLUMN user_activity.user_id IS 'References authenticated user account (NULL for anonymous participants, NOT NULL for registered users)';

-- ============================================================================
-- STEP 4: Rename indexes to match new table names
-- ============================================================================

-- Indexes for user_activity (formerly users)
ALTER INDEX IF EXISTS idx_users_username RENAME TO idx_user_activity_username;
ALTER INDEX IF EXISTS idx_users_ip_address RENAME TO idx_user_activity_ip_address;

-- Indexes for sessions (formerly active_users)
ALTER INDEX IF EXISTS idx_active_users_username RENAME TO idx_sessions_username;
ALTER INDEX IF EXISTS idx_active_users_last_heartbeat RENAME TO idx_sessions_last_heartbeat;
ALTER INDEX IF EXISTS idx_active_users_session_id RENAME TO idx_sessions_session_id;

-- Indexes for users (formerly admin_users) - keep original names as they're still correct
-- idx_admin_users_username → idx_users_username (makes sense)
-- idx_admin_users_role → idx_users_role (makes sense)
-- idx_admin_users_is_active → idx_users_is_active (makes sense)
ALTER INDEX IF EXISTS idx_admin_users_username RENAME TO idx_users_username;
ALTER INDEX IF EXISTS idx_admin_users_role RENAME TO idx_users_role;
ALTER INDEX IF EXISTS idx_admin_users_is_active RENAME TO idx_users_is_active;

-- ============================================================================
-- STEP 5: Rename constraints to match new table names
-- ============================================================================

-- Sessions table constraints (formerly active_users)
ALTER TABLE sessions RENAME CONSTRAINT active_users_username_session_unique TO sessions_username_session_unique;

-- ============================================================================
-- STEP 6: Update database functions to use new table names
-- ============================================================================

-- Rename and update cleanup_inactive_users → cleanup_inactive_sessions
DROP FUNCTION IF EXISTS cleanup_inactive_users() CASCADE;

CREATE OR REPLACE FUNCTION cleanup_inactive_sessions()
RETURNS INTEGER AS $$
DECLARE
    deleted_count INTEGER;
BEGIN
    DELETE FROM sessions
    WHERE last_heartbeat < NOW() - INTERVAL '5 minutes';
    
    GET DIAGNOSTICS deleted_count = ROW_COUNT;
    RETURN deleted_count;
END;
$$ LANGUAGE plpgsql;

COMMENT ON FUNCTION cleanup_inactive_sessions() IS 'Removes sessions inactive for more than 5 minutes (heartbeat timeout)';

-- Update update_user_stats function to use user_activity table
DROP FUNCTION IF EXISTS update_user_stats() CASCADE;

CREATE OR REPLACE FUNCTION update_user_stats()
RETURNS TRIGGER AS $$
BEGIN
    INSERT INTO user_activity (username, ip_address, last_seen, message_count, user_id)
    VALUES (NEW.username, NEW.ip_address, NEW.created_at, 1, NEW.user_id)
    ON CONFLICT (username) 
    DO UPDATE SET
        last_seen = NEW.created_at,
        message_count = user_activity.message_count + 1,
        ip_address = NEW.ip_address,
        user_id = COALESCE(NEW.user_id, user_activity.user_id);
    
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

COMMENT ON FUNCTION update_user_stats() IS 'Automatically updates user_activity table when messages are posted (tracks participation history)';

-- Recreate trigger (should still work but recreate for clarity)
DROP TRIGGER IF EXISTS trigger_update_user_stats ON messages;
CREATE TRIGGER trigger_update_user_stats
AFTER INSERT ON messages
FOR EACH ROW
EXECUTE FUNCTION update_user_stats();

-- ============================================================================
-- STEP 7: Update database views to use new table names
-- ============================================================================

-- Drop and recreate user_stats view
DROP VIEW IF EXISTS user_stats CASCADE;

CREATE OR REPLACE VIEW user_stats AS
SELECT 
    username,
    ip_address,
    first_seen,
    last_seen,
    message_count,
    is_banned,
    is_moderator,
    user_id
FROM user_activity
ORDER BY last_seen DESC;

COMMENT ON VIEW user_stats IS 'Convenient view of user activity statistics (historical participation data)';

-- ============================================================================
-- STEP 8: Update table comments for clarity
-- ============================================================================

COMMENT ON TABLE users IS 'Authenticated user accounts with passwords and role-based access (admins, moderators, future registered chat users)';
COMMENT ON TABLE sessions IS 'Currently active chat sessions - includes both anonymous users (user_id NULL) and authenticated users (user_id NOT NULL)';
COMMENT ON TABLE user_activity IS 'Historical participation tracking - auto-populated by trigger when messages are sent (audit log of all chat participants)';

-- ============================================================================
-- STEP 9: Update existing foreign key references in messages/private_messages
-- ============================================================================

-- The messages.user_id and private_messages.*_user_id columns already reference
-- the old 'users' table. Since we renamed 'users' → 'user_activity' and 
-- 'admin_users' → 'users', the FK should now point to the NEW 'users' table.
-- However, PostgreSQL automatically updates FK references when renaming tables.
-- Let's verify and recreate them to be explicit:

-- Check current state and drop old constraints
ALTER TABLE messages DROP CONSTRAINT IF EXISTS messages_user_id_fkey;
ALTER TABLE private_messages DROP CONSTRAINT IF EXISTS private_messages_from_user_id_fkey;
ALTER TABLE private_messages DROP CONSTRAINT IF EXISTS private_messages_to_user_id_fkey;

-- Recreate FKs pointing to correct table (users = authenticated accounts)
ALTER TABLE messages 
ADD CONSTRAINT messages_user_id_fkey 
FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE private_messages
ADD CONSTRAINT private_messages_from_user_id_fkey
FOREIGN KEY (from_user_id) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE private_messages
ADD CONSTRAINT private_messages_to_user_id_fkey
FOREIGN KEY (to_user_id) REFERENCES users(id) ON DELETE SET NULL;

-- ============================================================================
-- STEP 10: Final validation
-- ============================================================================

-- Verify all tables exist with new names
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'users') THEN
        RAISE EXCEPTION 'Table "users" not found after rename';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'sessions') THEN
        RAISE EXCEPTION 'Table "sessions" not found after rename';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'user_activity') THEN
        RAISE EXCEPTION 'Table "user_activity" not found after rename';
    END IF;
    
    RAISE NOTICE 'Migration 005 completed successfully!';
    RAISE NOTICE 'Tables renamed:';
    RAISE NOTICE '  - admin_users → users (authenticated accounts)';
    RAISE NOTICE '  - users → user_activity (participation audit log)';
    RAISE NOTICE '  - active_users → sessions (current sessions)';
    RAISE NOTICE 'Added user_id columns to sessions and user_activity for future authenticated user support';
END $$;

COMMIT;
