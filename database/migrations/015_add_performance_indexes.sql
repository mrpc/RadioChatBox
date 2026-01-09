-- Migration 015: Add Performance Indexes
-- Created: 2026-01-09
-- Description: Adds missing indexes to improve query performance

-- 1. Composite index for session validation queries
-- Optimizes queries like: WHERE session_id = ? AND username = ?
CREATE INDEX IF NOT EXISTS idx_sessions_validation ON sessions(session_id, username);

-- 2. Index for user_id lookups in sessions (used frequently in joins)
-- Already exists from init.sql, but ensuring it's there
CREATE INDEX IF NOT EXISTS idx_sessions_user_id ON sessions(user_id) WHERE user_id IS NOT NULL;

-- 3. Composite index for active user queries with display names
-- Optimizes: SELECT username, display_name FROM users WHERE is_active = true
CREATE INDEX IF NOT EXISTS idx_users_active_display ON users(is_active, username, display_name) WHERE is_active = true;

-- 4. Index for user_activity username lookups
-- Already exists, but adding covering index for common queries
CREATE INDEX IF NOT EXISTS idx_user_activity_username_stats ON user_activity(username, last_seen, message_count);

-- 5. Index for settings queries (frequently accessed)
-- Optimizes: SELECT setting_key, setting_value FROM settings WHERE setting_key IN (...)
CREATE INDEX IF NOT EXISTS idx_settings_key_value ON settings(setting_key, setting_value);

-- 6. Composite index for private message conversation ordering
-- Optimizes: ORDER BY created_at when fetching conversations
CREATE INDEX IF NOT EXISTS idx_pm_created_at ON private_messages(from_username, to_username, created_at DESC);

-- 7. Index for fake user active status queries
-- Already exists from init.sql (idx_fake_users_active)

-- 8. Index for permanent banned IPs
-- Optimizes: WHERE banned_until IS NULL (permanent bans)
-- Note: Cannot use NOW() in index predicate (not immutable), so indexing permanent bans only
CREATE INDEX IF NOT EXISTS idx_banned_ips_permanent ON banned_ips(ip_address) WHERE banned_until IS NULL;

-- Comments
COMMENT ON INDEX idx_sessions_validation IS 'Optimizes session validation queries (session_id + username)';
COMMENT ON INDEX idx_users_active_display IS 'Covering index for active user queries with display names';
COMMENT ON INDEX idx_user_activity_username_stats IS 'Covering index for user activity stats queries';
COMMENT ON INDEX idx_settings_key_value IS 'Covering index for settings lookups';
COMMENT ON INDEX idx_pm_created_at IS 'Optimizes private message conversation ordering';
COMMENT ON INDEX idx_banned_ips_permanent IS 'Partial index for permanent IP bans (banned_until IS NULL)';

-- Analyze tables to update statistics
ANALYZE sessions;
ANALYZE users;
ANALYZE user_activity;
ANALYZE settings;
ANALYZE private_messages;
ANALYZE banned_ips;
