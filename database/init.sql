-- RadioChatBox v1.0 Database Schema
-- PostgreSQL database initialization script
-- 
-- This schema provides complete storage for:
-- - Public and private chat messages  
-- - User management and profiles
-- - Moderation (bans, blacklists)
-- - Photo attachments
-- - System settings

-- ============================================================================
-- DROP EXISTING TABLES (for clean re-import)
-- ============================================================================

DROP TABLE IF EXISTS admin_notification_reads CASCADE;
DROP TABLE IF EXISTS admin_notifications CASCADE;
DROP TABLE IF EXISTS fake_users CASCADE;
DROP TABLE IF EXISTS url_blacklist CASCADE;
DROP TABLE IF EXISTS banned_nicknames CASCADE;
DROP TABLE IF EXISTS banned_ips CASCADE;
DROP TABLE IF EXISTS user_profiles CASCADE;
DROP TABLE IF EXISTS attachments CASCADE;
DROP TABLE IF EXISTS private_messages CASCADE;
DROP TABLE IF EXISTS sessions CASCADE;
DROP TABLE IF EXISTS user_activity CASCADE;
DROP TABLE IF EXISTS users CASCADE;
DROP TABLE IF EXISTS messages CASCADE;
DROP TABLE IF EXISTS settings CASCADE;

-- Drop views
DROP VIEW IF EXISTS recent_messages CASCADE;
DROP VIEW IF EXISTS user_stats CASCADE;

-- Drop functions
DROP FUNCTION IF EXISTS update_user_stats() CASCADE;
DROP FUNCTION IF EXISTS cleanup_inactive_sessions() CASCADE;
DROP FUNCTION IF EXISTS create_fake_user_dm_notification(VARCHAR, VARCHAR, TEXT, INTEGER) CASCADE;
DROP FUNCTION IF EXISTS mark_notification_read(INTEGER, VARCHAR) CASCADE;
DROP FUNCTION IF EXISTS mark_all_notifications_read(VARCHAR) CASCADE;
DROP FUNCTION IF EXISTS get_unread_notification_count(VARCHAR) CASCADE;
DROP FUNCTION IF EXISTS cleanup_old_notifications() CASCADE;

-- ============================================================================
-- CORE TABLES
-- ============================================================================

-- Public messages table
CREATE TABLE IF NOT EXISTS messages (
    id SERIAL PRIMARY KEY,
    message_id VARCHAR(255) UNIQUE NOT NULL,
    username VARCHAR(50) NOT NULL,
    message TEXT NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    is_deleted BOOLEAN DEFAULT FALSE,
    reply_to VARCHAR(255) DEFAULT NULL,
    user_id INTEGER  -- Foreign key constraint added later after users table exists
);

CREATE INDEX idx_messages_created_at ON messages(created_at DESC);
CREATE INDEX idx_messages_username ON messages(username);
CREATE INDEX idx_messages_ip_address ON messages(ip_address);
CREATE INDEX idx_messages_message_id ON messages(message_id);
CREATE INDEX idx_messages_active_recent ON messages(is_deleted, created_at DESC) WHERE is_deleted = FALSE;
CREATE INDEX idx_messages_reply_to ON messages(reply_to);
CREATE INDEX idx_messages_user ON messages(user_id);

COMMENT ON COLUMN messages.reply_to IS 'References the message_id of the parent message being replied to';

-- User activity table for tracking and moderation (historical participation audit)
CREATE TABLE IF NOT EXISTS user_activity (
    id SERIAL PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    session_id VARCHAR(255),
    ip_address VARCHAR(45) NOT NULL,
    first_seen TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    message_count INTEGER DEFAULT 0,
    is_banned BOOLEAN DEFAULT FALSE,
    is_moderator BOOLEAN DEFAULT FALSE,
    user_id INTEGER  -- Foreign key constraint added later after users table exists
);

CREATE INDEX idx_user_activity_username ON user_activity(username);
CREATE INDEX idx_user_activity_ip_address ON user_activity(ip_address);
CREATE INDEX idx_user_activity_user ON user_activity(user_id);

COMMENT ON TABLE user_activity IS 'Historical participation tracking - auto-populated by trigger when messages are sent (audit log of all chat participants)';
COMMENT ON COLUMN user_activity.user_id IS 'References authenticated user account (NULL for anonymous participants, NOT NULL for registered users)';

-- Sessions table (currently online users - anonymous and authenticated)
CREATE TABLE IF NOT EXISTS sessions (
    id SERIAL PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    session_id VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    last_heartbeat TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    user_id INTEGER,  -- Foreign key constraint added later after users table exists
    CONSTRAINT sessions_username_session_unique UNIQUE (username, session_id)
);

CREATE INDEX idx_sessions_username ON sessions(username);
CREATE INDEX idx_sessions_last_heartbeat ON sessions(last_heartbeat);
CREATE INDEX idx_sessions_session_id ON sessions(session_id);
CREATE INDEX idx_sessions_user ON sessions(user_id);

COMMENT ON TABLE sessions IS 'Currently active chat sessions - includes both anonymous users (user_id NULL) and authenticated users (user_id NOT NULL)';
COMMENT ON COLUMN sessions.user_id IS 'References authenticated user account (NULL for anonymous users, NOT NULL for logged-in users)';

-- Fake users (to fill chat when real user count is low)
CREATE TABLE IF NOT EXISTS fake_users (
    id SERIAL PRIMARY KEY,
    nickname VARCHAR(50) NOT NULL UNIQUE,
    age INTEGER CHECK (age >= 18 AND age <= 99),
    sex VARCHAR(10) CHECK (sex IN ('male', 'female', 'other')),
    location VARCHAR(100),
    is_active BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT valid_nickname CHECK (LENGTH(nickname) >= 3)
);

CREATE INDEX idx_fake_users_active ON fake_users(is_active);

COMMENT ON TABLE fake_users IS 'Fake users that can be activated to fill the chat when real user count is low';
COMMENT ON COLUMN fake_users.is_active IS 'Whether this fake user is currently shown in the user list';

-- ============================================================================
-- ADMIN NOTIFICATIONS
-- ============================================================================

-- Admin notifications table (per-admin read states)
CREATE TABLE IF NOT EXISTS admin_notifications (
    id SERIAL PRIMARY KEY,
    notification_type VARCHAR(50) NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    metadata JSONB DEFAULT '{}',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_admin_notifications_created_at ON admin_notifications(created_at DESC);
CREATE INDEX idx_admin_notifications_type ON admin_notifications(notification_type);
CREATE INDEX idx_admin_notifications_metadata ON admin_notifications USING GIN (metadata);

COMMENT ON TABLE admin_notifications IS 'Notifications for admin panel - each notification is shared across all admins';
COMMENT ON COLUMN admin_notifications.notification_type IS 'Type of notification (fake_user_dm, report, suspicious_activity, etc.)';
COMMENT ON COLUMN admin_notifications.title IS 'Short notification title';
COMMENT ON COLUMN admin_notifications.message IS 'Full notification message/description';
COMMENT ON COLUMN admin_notifications.metadata IS 'Additional structured data (user_ids, message_ids, context)';

-- Admin notification reads table (per-admin tracking)
CREATE TABLE IF NOT EXISTS admin_notification_reads (
    id SERIAL PRIMARY KEY,
    notification_id INTEGER NOT NULL REFERENCES admin_notifications(id) ON DELETE CASCADE,
    admin_username VARCHAR(100) NOT NULL,
    read_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(notification_id, admin_username)
);

CREATE INDEX idx_admin_notification_reads_notification ON admin_notification_reads(notification_id);
CREATE INDEX idx_admin_notification_reads_admin ON admin_notification_reads(admin_username);

COMMENT ON TABLE admin_notification_reads IS 'Tracks which admins have read which notifications - per-admin read states';
COMMENT ON COLUMN admin_notification_reads.notification_id IS 'Reference to the notification';
COMMENT ON COLUMN admin_notification_reads.admin_username IS 'Username of admin who read this notification';
COMMENT ON COLUMN admin_notification_reads.read_at IS 'When this admin marked it as read';

-- ============================================================================
-- PRIVATE MESSAGING
-- ============================================================================

-- Private messages table
CREATE TABLE IF NOT EXISTS private_messages (
    id SERIAL PRIMARY KEY,
    from_username VARCHAR(100) NOT NULL,
    to_username VARCHAR(100) NOT NULL,
    message TEXT NOT NULL,
    attachment_id VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_at TIMESTAMP
);

CREATE INDEX idx_private_messages_to ON private_messages(to_username);
CREATE INDEX idx_private_messages_from ON private_messages(from_username);
CREATE INDEX idx_private_messages_attachment ON private_messages(attachment_id);

-- Photo attachments
CREATE TABLE IF NOT EXISTS attachments (
    id SERIAL PRIMARY KEY,
    attachment_id VARCHAR(255) UNIQUE NOT NULL,
    filename VARCHAR(255) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_size INTEGER NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    width INTEGER,
    height INTEGER,
    uploaded_by VARCHAR(50) NOT NULL,
    recipient VARCHAR(50),
    ip_address VARCHAR(45) NOT NULL,
    uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL DEFAULT (CURRENT_TIMESTAMP + INTERVAL '48 hours'),
    is_deleted BOOLEAN DEFAULT FALSE
);

CREATE INDEX idx_attachments_uploaded_by ON attachments(uploaded_by);
CREATE INDEX idx_attachments_recipient ON attachments(recipient);
CREATE INDEX idx_attachments_expires_at ON attachments(expires_at);
CREATE INDEX idx_attachments_uploaded_at ON attachments(uploaded_at DESC);

-- ============================================================================
-- USER PROFILES
-- ============================================================================

-- User profiles (age, location, sex)
CREATE TABLE IF NOT EXISTS user_profiles (
    id SERIAL PRIMARY KEY,
    username VARCHAR(100) NOT NULL,
    session_id VARCHAR(255) NOT NULL,
    age VARCHAR(50),
    location VARCHAR(255),
    sex VARCHAR(20),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(username, session_id)
);

CREATE INDEX idx_user_profiles_username ON user_profiles(username);

-- ============================================================================
-- MODERATION & SECURITY
-- ============================================================================

-- IP bans
CREATE TABLE IF NOT EXISTS banned_ips (
    id SERIAL PRIMARY KEY,
    ip_address VARCHAR(45) UNIQUE NOT NULL,
    reason TEXT,
    banned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    banned_until TIMESTAMP,
    banned_by VARCHAR(50)
);

CREATE INDEX idx_banned_ips_ip_address ON banned_ips(ip_address);
CREATE INDEX idx_banned_ips_banned_until ON banned_ips(banned_until) WHERE banned_until IS NOT NULL;

-- Nickname bans
CREATE TABLE IF NOT EXISTS banned_nicknames (
    id SERIAL PRIMARY KEY,
    nickname VARCHAR(50) UNIQUE NOT NULL,
    reason TEXT,
    banned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    banned_by VARCHAR(50)
);

CREATE INDEX idx_banned_nicknames_nickname ON banned_nicknames(nickname);

-- URL blacklist
CREATE TABLE IF NOT EXISTS url_blacklist (
    id SERIAL PRIMARY KEY,
    pattern VARCHAR(500) NOT NULL UNIQUE,
    description TEXT,
    added_by VARCHAR(100),
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_url_blacklist_pattern ON url_blacklist(pattern);

-- ============================================================================
-- SYSTEM SETTINGS
-- ============================================================================

-- Application settings
CREATE TABLE IF NOT EXISTS settings (
    id SERIAL PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================================
-- FUNCTIONS & TRIGGERS
-- ============================================================================

-- Auto-update user stats on new message
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

CREATE TRIGGER trigger_update_user_stats
AFTER INSERT ON messages
FOR EACH ROW
EXECUTE FUNCTION update_user_stats();

-- Clean up inactive sessions (not seen in 5 minutes)
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

-- ============================================================================
-- VIEWS FOR CONVENIENCE
-- ============================================================================

-- Recent messages (last 24 hours)
CREATE OR REPLACE VIEW recent_messages AS
SELECT 
    id,
    message_id,
    username,
    message,
    ip_address,
    created_at
FROM messages
WHERE created_at > NOW() - INTERVAL '24 hours'
  AND is_deleted = FALSE
ORDER BY created_at DESC;

-- User statistics
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
ORDER BY message_count DESC;

-- ============================================================================
-- DEFAULT DATA
-- ============================================================================

-- Default system settings
INSERT INTO settings (setting_key, setting_value) VALUES 
    -- Rate limiting
    ('rate_limit_messages', '10'),
    ('rate_limit_window', '60'),
    
    -- Chat settings
    ('color_scheme', 'dark'),
    ('page_title', 'RadioChatBox'),
    ('require_profile', 'false'),
    ('chat_mode', 'both'),
    ('allow_photo_uploads', 'true'),
    ('max_photo_size_mb', '5'),
    ('minimum_users', '0'),
    
    -- SEO & Branding
    ('site_title', 'RadioChatBox - Real-time Chat'),
    ('site_description', 'Connect with listeners in real-time during your radio show'),
    ('site_keywords', 'radio, chat, live, real-time, broadcast'),
    ('meta_author', ''),
    ('meta_og_image', ''),
    ('meta_og_type', 'website'),
    ('favicon_url', ''),
    ('logo_url', ''),
    ('brand_color', '#007bff'),
    ('brand_name', 'RadioChatBox'),
    
    -- Custom Scripts (Analytics, Tracking, etc.)
    ('header_scripts', ''),
    ('body_scripts', ''),
    ('analytics_enabled', 'false'),
    ('analytics_provider', ''),
    ('analytics_tracking_id', ''),
    
    -- Advertisement Settings
    ('ads_enabled', 'false'),
    ('ads_main_top', ''),
    ('ads_main_bottom', ''),
    ('ads_chat_sidebar', ''),
    ('ads_refresh_interval', '30'),
    ('ads_refresh_enabled', 'false'),
    -- Radio Stream Status (Icecast/Shoutcast JSON URL)
    ('radio_status_url', '')
ON CONFLICT (setting_key) DO NOTHING;

-- Reserved nicknames (admin is NOT banned since it's the default user account)
INSERT INTO banned_nicknames (nickname, reason, banned_by) VALUES 
    ('moderator', 'Reserved for moderators', 'system'),
    ('system', 'Reserved for system messages', 'system')
ON CONFLICT (nickname) DO NOTHING;

-- Common spam URL patterns
INSERT INTO url_blacklist (pattern, description, added_by) VALUES 
    ('bit.ly', 'URL shortener - often used for spam', 'system'),
    ('tinyurl.com', 'URL shortener - often used for spam', 'system'),
    ('goo.gl', 'URL shortener - often used for spam', 'system')
ON CONFLICT (pattern) DO NOTHING;

-- Welcome message
INSERT INTO messages (message_id, username, message, ip_address, created_at) VALUES 
    ('welcome_msg', 'System', 'Welcome to RadioChatBox! Be respectful and have fun! 🎙️', '127.0.0.1', CURRENT_TIMESTAMP)
ON CONFLICT (message_id) DO NOTHING;

-- ============================================================================
-- COMMENTS
-- ============================================================================

COMMENT ON TABLE messages IS 'Public chat messages';
COMMENT ON TABLE private_messages IS 'Private user-to-user messages';
COMMENT ON TABLE attachments IS 'Photo attachments for private messages (auto-expire after 48h)';
COMMENT ON TABLE user_profiles IS 'Optional user demographics (age, sex, location)';
COMMENT ON TABLE settings IS 'System configuration stored in database';
COMMENT ON INDEX idx_messages_active_recent IS 'Optimizes message history queries';
COMMENT ON INDEX idx_banned_ips_banned_until IS 'Speeds up active ban checks';
COMMENT ON COLUMN attachments.expires_at IS 'Photos auto-delete after 48 hours';
COMMENT ON COLUMN attachments.file_size IS 'File size in bytes';

-- ============================================================================
-- GRANT PERMISSIONS
-- ============================================================================
-- Note: Run this script as postgres superuser
-- The script will grant all necessary permissions to the database owner
-- Usage: psql -U postgres -d YOUR_DATABASE -f init.sql


-- Migration: Admin Users with Role-Based Access Control
-- Created: 2025-11-12
-- Description: Creates users table with hierarchical roles for authentication and access control

-- Create enum type for user roles
DO $$ 
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'user_role') THEN
        CREATE TYPE user_role AS ENUM ('root', 'administrator', 'moderator', 'simple_user');
    END IF;
END $$;

-- Users table (authenticated accounts - admin panel + future registered chat users)
CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role user_role NOT NULL DEFAULT 'simple_user',
    email VARCHAR(255),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP,
    created_by INTEGER REFERENCES users(id),
    CONSTRAINT username_length CHECK (LENGTH(username) >= 3),
    CONSTRAINT valid_email CHECK (email IS NULL OR email ~* '^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$')
);

CREATE INDEX idx_users_username ON users(username);
CREATE INDEX idx_users_role ON users(role);
CREATE INDEX idx_users_is_active ON users(is_active);

COMMENT ON TABLE users IS 'Authenticated user accounts with passwords and role-based access (admins, moderators, future registered chat users)';

-- Insert default root user (username: admin, password: admin123)
INSERT INTO users (username, password_hash, role, email, is_active) VALUES 
    ('admin', '$2y$10$ZUCvW9SmSpOUwPtWC.XzL.mA0piFBy.DM8TKPHvkWdd0CsG121vCC', 'root', NULL, TRUE)
ON CONFLICT (username) DO NOTHING;

-- Auto-update updated_at timestamp
CREATE OR REPLACE FUNCTION update_users_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_users_updated_at
BEFORE UPDATE ON users
FOR EACH ROW
EXECUTE FUNCTION update_users_updated_at();

-- Add foreign key constraint to user_activity now that users table exists
ALTER TABLE user_activity 
ADD CONSTRAINT fk_user_activity_user 
FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;

-- Add foreign key constraint to sessions now that users table exists
ALTER TABLE sessions 
ADD CONSTRAINT fk_sessions_user 
FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;

-- Add foreign key constraint to messages now that users table exists
ALTER TABLE messages 
ADD CONSTRAINT fk_messages_user 
FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;


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


-- Migration: Add user_id columns for future registered user support
-- Currently system uses session-based anonymous users, but this prepares for proper user accounts

-- Add user_id columns to private_messages
ALTER TABLE private_messages 
ADD COLUMN IF NOT EXISTS from_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
ADD COLUMN IF NOT EXISTS to_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL;

-- Note: user_id column for messages is already added in the main table creation above
-- (along with sessions and user_activity tables)

-- Create indexes for user-based queries
CREATE INDEX IF NOT EXISTS idx_private_messages_from_user ON private_messages(from_user_id);
CREATE INDEX IF NOT EXISTS idx_private_messages_to_user ON private_messages(to_user_id);

-- Add comments explaining the design
COMMENT ON COLUMN private_messages.from_user_id IS 'User ID of sender if logged in as registered user (NULL for anonymous/session-only users)';
COMMENT ON COLUMN private_messages.to_user_id IS 'User ID of recipient if logged in as registered user (NULL for anonymous/session-only users)';
COMMENT ON COLUMN messages.user_id IS 'User ID if message sent by registered user (NULL for anonymous/session-only users)';

-- Note: This is nullable and optional
-- Current flow: username + session_id (anonymous users)
-- Future flow: username + session_id + user_id (registered users)
-- Registered users will have persistent user_id that survives across sessions
-- Anonymous users will continue to use session_id only


-- Migration 004: Allow authenticated users to have multiple simultaneous sessions
-- Note: This constraint is now part of the sessions table definition above
-- (sessions_username_session_unique constraint)
-- Regular users still limited to one session per username
-- Authenticated users can join from multiple devices

-- Note: The application logic in ChatService.php has been updated to:
-- 1. Allow authenticated usernames to register (no longer blocked)
-- 2. Allow authenticated usernames to have multiple active sessions
-- 3. Regular users still enforced to one session per username

-- ============================================================================
-- STATISTICS TABLES (Migration 006)
-- ============================================================================

-- Hourly statistics
CREATE TABLE IF NOT EXISTS stats_hourly (
    id SERIAL PRIMARY KEY,
    stat_hour TIMESTAMP NOT NULL,
    active_users INTEGER DEFAULT 0,
    guest_users INTEGER DEFAULT 0,
    registered_users INTEGER DEFAULT 0,
    total_messages INTEGER DEFAULT 0,
    private_messages INTEGER DEFAULT 0,
    photo_uploads INTEGER DEFAULT 0,
    new_registrations INTEGER DEFAULT 0,
    radio_listeners_avg INTEGER DEFAULT 0,
    radio_listeners_peak INTEGER DEFAULT 0,
    peak_concurrent_users INTEGER DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(stat_hour)
);

CREATE INDEX IF NOT EXISTS idx_stats_hourly_stat_hour ON stats_hourly(stat_hour DESC);

COMMENT ON TABLE stats_hourly IS 'Hourly aggregated statistics - one row per hour';
COMMENT ON COLUMN stats_hourly.radio_listeners_avg IS 'Average radio listeners during this hour';
COMMENT ON COLUMN stats_hourly.radio_listeners_peak IS 'Peak radio listeners during the hour';

-- Daily statistics
CREATE TABLE IF NOT EXISTS stats_daily (
    id SERIAL PRIMARY KEY,
    stat_date DATE NOT NULL,
    active_users INTEGER DEFAULT 0,
    guest_users INTEGER DEFAULT 0,
    registered_users INTEGER DEFAULT 0,
    total_messages INTEGER DEFAULT 0,
    private_messages INTEGER DEFAULT 0,
    photo_uploads INTEGER DEFAULT 0,
    new_registrations INTEGER DEFAULT 0,
    radio_listeners_avg INTEGER DEFAULT 0,
    radio_listeners_peak INTEGER DEFAULT 0,
    peak_concurrent_users INTEGER DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(stat_date)
);

CREATE INDEX IF NOT EXISTS idx_stats_daily_stat_date ON stats_daily(stat_date DESC);

COMMENT ON TABLE stats_daily IS 'Daily aggregated statistics - one row per day';

-- Weekly statistics
CREATE TABLE IF NOT EXISTS stats_weekly (
    id SERIAL PRIMARY KEY,
    stat_year INTEGER NOT NULL,
    stat_week INTEGER NOT NULL,
    week_start_date DATE NOT NULL,
    active_users INTEGER DEFAULT 0,
    guest_users INTEGER DEFAULT 0,
    registered_users INTEGER DEFAULT 0,
    total_messages INTEGER DEFAULT 0,
    private_messages INTEGER DEFAULT 0,
    photo_uploads INTEGER DEFAULT 0,
    new_registrations INTEGER DEFAULT 0,
    radio_listeners_avg INTEGER DEFAULT 0,
    radio_listeners_peak INTEGER DEFAULT 0,
    peak_concurrent_users INTEGER DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(stat_year, stat_week)
);

CREATE INDEX IF NOT EXISTS idx_stats_weekly_year_week ON stats_weekly(stat_year DESC, stat_week DESC);
CREATE INDEX IF NOT EXISTS idx_stats_weekly_week_start ON stats_weekly(week_start_date DESC);

COMMENT ON TABLE stats_weekly IS 'Weekly aggregated statistics - one row per week (ISO week numbering)';

-- Monthly statistics
CREATE TABLE IF NOT EXISTS stats_monthly (
    id SERIAL PRIMARY KEY,
    stat_year INTEGER NOT NULL,
    stat_month INTEGER NOT NULL,
    active_users INTEGER DEFAULT 0,
    guest_users INTEGER DEFAULT 0,
    registered_users INTEGER DEFAULT 0,
    total_messages INTEGER DEFAULT 0,
    private_messages INTEGER DEFAULT 0,
    photo_uploads INTEGER DEFAULT 0,
    new_registrations INTEGER DEFAULT 0,
    radio_listeners_avg INTEGER DEFAULT 0,
    radio_listeners_peak INTEGER DEFAULT 0,
    peak_concurrent_users INTEGER DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(stat_year, stat_month)
);

CREATE INDEX IF NOT EXISTS idx_stats_monthly_year_month ON stats_monthly(stat_year DESC, stat_month DESC);

COMMENT ON TABLE stats_monthly IS 'Monthly aggregated statistics - one row per month';

-- Yearly statistics
CREATE TABLE IF NOT EXISTS stats_yearly (
    id SERIAL PRIMARY KEY,
    stat_year INTEGER NOT NULL UNIQUE,
    active_users INTEGER DEFAULT 0,
    guest_users INTEGER DEFAULT 0,
    registered_users INTEGER DEFAULT 0,
    total_messages INTEGER DEFAULT 0,
    private_messages INTEGER DEFAULT 0,
    photo_uploads INTEGER DEFAULT 0,
    new_registrations INTEGER DEFAULT 0,
    radio_listeners_avg INTEGER DEFAULT 0,
    radio_listeners_peak INTEGER DEFAULT 0,
    peak_concurrent_users INTEGER DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_stats_yearly_year ON stats_yearly(stat_year DESC);

COMMENT ON TABLE stats_yearly IS 'Yearly aggregated statistics - one row per year';

-- Real-time snapshots
CREATE TABLE IF NOT EXISTS stats_snapshots (
    id SERIAL PRIMARY KEY,
    snapshot_time TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    concurrent_users INTEGER DEFAULT 0,
    radio_listeners INTEGER DEFAULT 0,
    active_sessions INTEGER DEFAULT 0
);

CREATE INDEX IF NOT EXISTS idx_stats_snapshots_time ON stats_snapshots(snapshot_time DESC);

COMMENT ON TABLE stats_snapshots IS 'Real-time snapshots taken every 5-15 minutes for calculating averages and peaks';

-- Statistics aggregation functions
CREATE OR REPLACE FUNCTION aggregate_hourly_stats(target_hour TIMESTAMP)
RETURNS void AS $$
DECLARE
    v_active_users INTEGER;
    v_guest_users INTEGER;
    v_registered_users INTEGER;
    v_total_messages INTEGER;
    v_private_messages INTEGER;
    v_photo_uploads INTEGER;
    v_new_registrations INTEGER;
    v_radio_listeners_avg INTEGER;
    v_radio_listeners_peak INTEGER;
    v_peak_concurrent INTEGER;
    v_hour_start TIMESTAMP;
    v_hour_end TIMESTAMP;
BEGIN
    v_hour_start := date_trunc('hour', target_hour);
    v_hour_end := v_hour_start + INTERVAL '1 hour';
    
    SELECT COUNT(DISTINCT username) INTO v_active_users
    FROM messages
    WHERE created_at >= v_hour_start AND created_at < v_hour_end AND is_deleted = FALSE;
    
    SELECT COUNT(DISTINCT m.username) INTO v_guest_users
    FROM messages m LEFT JOIN users u ON m.username = u.username
    WHERE m.created_at >= v_hour_start AND m.created_at < v_hour_end
      AND m.is_deleted = FALSE AND u.id IS NULL;
    
    v_registered_users := COALESCE(v_active_users, 0) - COALESCE(v_guest_users, 0);
    
    SELECT COUNT(*) INTO v_total_messages
    FROM messages
    WHERE created_at >= v_hour_start AND created_at < v_hour_end AND is_deleted = FALSE;
    
    SELECT COUNT(*) INTO v_private_messages
    FROM private_messages
    WHERE created_at >= v_hour_start AND created_at < v_hour_end;
    
    SELECT COUNT(*) INTO v_photo_uploads
    FROM attachments
    WHERE uploaded_at >= v_hour_start AND uploaded_at < v_hour_end AND is_deleted = FALSE;
    
    SELECT COUNT(*) INTO v_new_registrations
    FROM users
    WHERE created_at >= v_hour_start AND created_at < v_hour_end;
    
    SELECT COALESCE(AVG(radio_listeners)::INTEGER, 0) INTO v_radio_listeners_avg
    FROM stats_snapshots
    WHERE snapshot_time >= v_hour_start AND snapshot_time < v_hour_end;
    
    SELECT COALESCE(MAX(radio_listeners), 0) INTO v_radio_listeners_peak
    FROM stats_snapshots
    WHERE snapshot_time >= v_hour_start AND snapshot_time < v_hour_end;
    
    SELECT COALESCE(MAX(concurrent_users), 0) INTO v_peak_concurrent
    FROM stats_snapshots
    WHERE snapshot_time >= v_hour_start AND snapshot_time < v_hour_end;
    
    INSERT INTO stats_hourly (
        stat_hour, active_users, guest_users, registered_users,
        total_messages, private_messages, photo_uploads,
        new_registrations, radio_listeners_avg, radio_listeners_peak, peak_concurrent_users
    )
    VALUES (
        v_hour_start,
        COALESCE(v_active_users, 0),
        COALESCE(v_guest_users, 0),
        COALESCE(v_registered_users, 0),
        COALESCE(v_total_messages, 0),
        COALESCE(v_private_messages, 0),
        COALESCE(v_photo_uploads, 0),
        COALESCE(v_new_registrations, 0),
        COALESCE(v_radio_listeners_avg, 0),
        COALESCE(v_radio_listeners_peak, 0),
        COALESCE(v_peak_concurrent, 0)
    )
    ON CONFLICT (stat_hour) DO UPDATE SET
        active_users = EXCLUDED.active_users,
        guest_users = EXCLUDED.guest_users,
        registered_users = EXCLUDED.registered_users,
        total_messages = EXCLUDED.total_messages,
        private_messages = EXCLUDED.private_messages,
        photo_uploads = EXCLUDED.photo_uploads,
        new_registrations = EXCLUDED.new_registrations,
        radio_listeners_avg = EXCLUDED.radio_listeners_avg,
        radio_listeners_peak = EXCLUDED.radio_listeners_peak,
        peak_concurrent_users = EXCLUDED.peak_concurrent_users;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION aggregate_daily_stats(target_date DATE)
RETURNS void AS $$
DECLARE
    v_day_start TIMESTAMP;
    v_day_end TIMESTAMP;
BEGIN
    v_day_start := target_date::TIMESTAMP;
    v_day_end := v_day_start + INTERVAL '1 day';
    
    INSERT INTO stats_daily (
        stat_date, active_users, guest_users, registered_users,
        total_messages, private_messages, photo_uploads,
        new_registrations, radio_listeners_avg, radio_listeners_peak,
        peak_concurrent_users
    )
    SELECT
        target_date,
        MAX(active_users),
        MAX(guest_users),
        MAX(registered_users),
        SUM(total_messages),
        SUM(private_messages),
        SUM(photo_uploads),
        SUM(new_registrations),
        AVG(radio_listeners)::INTEGER,
        MAX(radio_listeners),
        MAX(peak_concurrent_users)
    FROM stats_hourly
    WHERE stat_hour >= v_day_start AND stat_hour < v_day_end
    ON CONFLICT (stat_date) DO UPDATE SET
        active_users = EXCLUDED.active_users,
        guest_users = EXCLUDED.guest_users,
        registered_users = EXCLUDED.registered_users,
        total_messages = EXCLUDED.total_messages,
        private_messages = EXCLUDED.private_messages,
        photo_uploads = EXCLUDED.photo_uploads,
        new_registrations = EXCLUDED.new_registrations,
        radio_listeners_avg = EXCLUDED.radio_listeners_avg,
        radio_listeners_peak = EXCLUDED.radio_listeners_peak,
        peak_concurrent_users = EXCLUDED.peak_concurrent_users;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION aggregate_weekly_stats(target_date DATE)
RETURNS void AS $$
DECLARE
    v_year INTEGER;
    v_week INTEGER;
    v_week_start DATE;
    v_week_end DATE;
BEGIN
    v_year := EXTRACT(ISOYEAR FROM target_date);
    v_week := EXTRACT(WEEK FROM target_date);
    v_week_start := date_trunc('week', target_date)::DATE;
    v_week_end := v_week_start + INTERVAL '1 week';
    
    INSERT INTO stats_weekly (
        stat_year, stat_week, week_start_date,
        active_users, guest_users, registered_users,
        total_messages, private_messages, photo_uploads,
        new_registrations, radio_listeners_avg, radio_listeners_peak,
        peak_concurrent_users
    )
    SELECT
        v_year, v_week, v_week_start,
        MAX(active_users),
        MAX(guest_users),
        MAX(registered_users),
        SUM(total_messages),
        SUM(private_messages),
        SUM(photo_uploads),
        SUM(new_registrations),
        AVG(radio_listeners_avg)::INTEGER,
        MAX(radio_listeners_peak),
        MAX(peak_concurrent_users)
    FROM stats_daily
    WHERE stat_date >= v_week_start AND stat_date < v_week_end::DATE
    ON CONFLICT (stat_year, stat_week) DO UPDATE SET
        active_users = EXCLUDED.active_users,
        guest_users = EXCLUDED.guest_users,
        registered_users = EXCLUDED.registered_users,
        total_messages = EXCLUDED.total_messages,
        private_messages = EXCLUDED.private_messages,
        photo_uploads = EXCLUDED.photo_uploads,
        new_registrations = EXCLUDED.new_registrations,
        radio_listeners_avg = EXCLUDED.radio_listeners_avg,
        radio_listeners_peak = EXCLUDED.radio_listeners_peak,
        peak_concurrent_users = EXCLUDED.peak_concurrent_users;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION aggregate_monthly_stats(target_date DATE)
RETURNS void AS $$
DECLARE
    v_year INTEGER;
    v_month INTEGER;
    v_month_start DATE;
    v_month_end DATE;
BEGIN
    v_year := EXTRACT(YEAR FROM target_date);
    v_month := EXTRACT(MONTH FROM target_date);
    v_month_start := date_trunc('month', target_date)::DATE;
    v_month_end := (v_month_start + INTERVAL '1 month')::DATE;
    
    INSERT INTO stats_monthly (
        stat_year, stat_month,
        active_users, guest_users, registered_users,
        total_messages, private_messages, photo_uploads,
        new_registrations, radio_listeners_avg, radio_listeners_peak,
        peak_concurrent_users
    )
    SELECT
        v_year, v_month,
        MAX(active_users),
        MAX(guest_users),
        MAX(registered_users),
        SUM(total_messages),
        SUM(private_messages),
        SUM(photo_uploads),
        SUM(new_registrations),
        AVG(radio_listeners_avg)::INTEGER,
        MAX(radio_listeners_peak),
        MAX(peak_concurrent_users)
    FROM stats_daily
    WHERE stat_date >= v_month_start AND stat_date < v_month_end
    ON CONFLICT (stat_year, stat_month) DO UPDATE SET
        active_users = EXCLUDED.active_users,
        guest_users = EXCLUDED.guest_users,
        registered_users = EXCLUDED.registered_users,
        total_messages = EXCLUDED.total_messages,
        private_messages = EXCLUDED.private_messages,
        photo_uploads = EXCLUDED.photo_uploads,
        new_registrations = EXCLUDED.new_registrations,
        radio_listeners_avg = EXCLUDED.radio_listeners_avg,
        radio_listeners_peak = EXCLUDED.radio_listeners_peak,
        peak_concurrent_users = EXCLUDED.peak_concurrent_users;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION aggregate_yearly_stats(target_year INTEGER)
RETURNS void AS $$
DECLARE
    v_year_start DATE;
    v_year_end DATE;
BEGIN
    v_year_start := (target_year || '-01-01')::DATE;
    v_year_end := (target_year + 1 || '-01-01')::DATE;
    
    INSERT INTO stats_yearly (
        stat_year,
        active_users, guest_users, registered_users,
        total_messages, private_messages, photo_uploads,
        new_registrations, radio_listeners_avg, radio_listeners_peak,
        peak_concurrent_users
    )
    SELECT
        target_year,
        MAX(active_users),
        MAX(guest_users),
        MAX(registered_users),
        SUM(total_messages),
        SUM(private_messages),
        SUM(photo_uploads),
        SUM(new_registrations),
        AVG(radio_listeners_avg)::INTEGER,
        MAX(radio_listeners_peak),
        MAX(peak_concurrent_users)
    FROM stats_daily
    WHERE stat_date >= v_year_start AND stat_date < v_year_end
    ON CONFLICT (stat_year) DO UPDATE SET
        active_users = EXCLUDED.active_users,
        guest_users = EXCLUDED.guest_users,
        registered_users = EXCLUDED.registered_users,
        total_messages = EXCLUDED.total_messages,
        private_messages = EXCLUDED.private_messages,
        photo_uploads = EXCLUDED.photo_uploads,
        new_registrations = EXCLUDED.new_registrations,
        radio_listeners_avg = EXCLUDED.radio_listeners_avg,
        radio_listeners_peak = EXCLUDED.radio_listeners_peak,
        peak_concurrent_users = EXCLUDED.peak_concurrent_users;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION cleanup_old_snapshots()
RETURNS void AS $$
BEGIN
    DELETE FROM stats_snapshots WHERE snapshot_time < NOW() - INTERVAL '30 days';
END;
$$ LANGUAGE plpgsql;

COMMENT ON FUNCTION aggregate_hourly_stats(TIMESTAMP) IS 'Aggregate statistics for a specific hour from raw data and snapshots';
COMMENT ON FUNCTION aggregate_daily_stats(DATE) IS 'Aggregate daily statistics from hourly stats';
COMMENT ON FUNCTION aggregate_weekly_stats(DATE) IS 'Aggregate weekly statistics from daily stats';
COMMENT ON FUNCTION aggregate_monthly_stats(DATE) IS 'Aggregate monthly statistics from daily stats';
COMMENT ON FUNCTION aggregate_yearly_stats(INTEGER) IS 'Aggregate yearly statistics from daily stats';
COMMENT ON FUNCTION cleanup_old_snapshots() IS 'Remove snapshots older than 30 days to keep table size manageable';

-- ============================================================================
-- ADMIN NOTIFICATION FUNCTIONS
-- ============================================================================

-- Function to create a notification for DM to fake user
CREATE OR REPLACE FUNCTION create_fake_user_dm_notification(
    p_from_username VARCHAR,
    p_to_username VARCHAR,
    p_message_preview TEXT,
    p_message_id INTEGER
)
RETURNS INTEGER AS $$
DECLARE
    v_notification_id INTEGER;
    v_existing_unread INTEGER;
BEGIN
    -- Check if there's already an unread notification for this conversation
    -- Only create a new one if all existing notifications have been read by all admins
    SELECT n.id INTO v_existing_unread
    FROM admin_notifications n
    WHERE n.notification_type = 'fake_user_dm'
      AND n.metadata->>'from_username' = p_from_username
      AND n.metadata->>'to_username' = p_to_username
      AND n.created_at > NOW() - INTERVAL '30 days'
      AND NOT EXISTS (
          SELECT 1 FROM admin_notification_reads r
          WHERE r.notification_id = n.id
      )
    ORDER BY n.created_at DESC
    LIMIT 1;

    -- If there's an existing unread notification, update it and return its ID
    IF v_existing_unread IS NOT NULL THEN
        UPDATE admin_notifications
        SET message = p_from_username || ' sent a message to fake user ' || p_to_username || ': ' || LEFT(p_message_preview, 100),
            metadata = jsonb_set(
                jsonb_set(metadata, '{message_id}', to_jsonb(p_message_id)),
                '{message_preview}', to_jsonb(LEFT(p_message_preview, 200))
            ),
            created_at = NOW()  -- Update timestamp to move it to top
        WHERE id = v_existing_unread;
        
        RETURN v_existing_unread;
    END IF;

    -- No existing unread notification, create a new one
    INSERT INTO admin_notifications (
        notification_type,
        title,
        message,
        metadata
    )
    VALUES (
        'fake_user_dm',
        'New DM to fake user: ' || p_to_username,
        p_from_username || ' sent a message to fake user ' || p_to_username || ': ' || LEFT(p_message_preview, 100),
        jsonb_build_object(
            'from_username', p_from_username,
            'to_username', p_to_username,
            'message_id', p_message_id,
            'message_preview', LEFT(p_message_preview, 200)
        )
    )
    RETURNING id INTO v_notification_id;

    RETURN v_notification_id;
END;
$$ LANGUAGE plpgsql;

-- Function to mark notification as read for specific admin
CREATE OR REPLACE FUNCTION mark_notification_read(
    p_notification_id INTEGER,
    p_admin_username VARCHAR
)
RETURNS BOOLEAN AS $$
BEGIN
    -- Insert or update read state for this admin
    INSERT INTO admin_notification_reads (notification_id, admin_username)
    VALUES (p_notification_id, p_admin_username)
    ON CONFLICT (notification_id, admin_username) DO NOTHING;

    RETURN FOUND;
END;
$$ LANGUAGE plpgsql;

-- Function to mark all notifications as read for specific admin
CREATE OR REPLACE FUNCTION mark_all_notifications_read(
    p_admin_username VARCHAR
)
RETURNS INTEGER AS $$
DECLARE
    v_count INTEGER;
BEGIN
    -- Mark all unread notifications as read for this admin
    INSERT INTO admin_notification_reads (notification_id, admin_username)
    SELECT n.id, p_admin_username
    FROM admin_notifications n
    WHERE NOT EXISTS (
        SELECT 1 FROM admin_notification_reads r
        WHERE r.notification_id = n.id
          AND r.admin_username = p_admin_username
    );

    GET DIAGNOSTICS v_count = ROW_COUNT;
    RETURN v_count;
END;
$$ LANGUAGE plpgsql;

-- Function to get unread count for specific admin
CREATE OR REPLACE FUNCTION get_unread_notification_count(
    p_admin_username VARCHAR
)
RETURNS INTEGER AS $$
DECLARE
    v_count INTEGER;
BEGIN
    SELECT COUNT(*)
    INTO v_count
    FROM admin_notifications n
    WHERE NOT EXISTS (
        SELECT 1 FROM admin_notification_reads r
        WHERE r.notification_id = n.id
          AND r.admin_username = p_admin_username
    );

    RETURN v_count;
END;
$$ LANGUAGE plpgsql;

-- Function to cleanup old notifications (keep last 30 days)
CREATE OR REPLACE FUNCTION cleanup_old_notifications()
RETURNS INTEGER AS $$
DECLARE
    v_count INTEGER;
BEGIN
    DELETE FROM admin_notifications
    WHERE created_at < NOW() - INTERVAL '30 days'
      AND EXISTS (
        -- Only delete if at least one admin has read it
        SELECT 1 FROM admin_notification_reads r WHERE r.notification_id = admin_notifications.id
      );

    GET DIAGNOSTICS v_count = ROW_COUNT;
    RETURN v_count;
END;
$$ LANGUAGE plpgsql;

COMMENT ON FUNCTION create_fake_user_dm_notification(VARCHAR, VARCHAR, TEXT, INTEGER) IS 'Create notification when user sends DM to fake user';
COMMENT ON FUNCTION mark_notification_read(INTEGER, VARCHAR) IS 'Mark notification as read for specific admin';
COMMENT ON FUNCTION mark_all_notifications_read(VARCHAR) IS 'Mark all unread notifications as read for specific admin';
COMMENT ON FUNCTION get_unread_notification_count(VARCHAR) IS 'Get count of unread notifications for specific admin';
COMMENT ON FUNCTION cleanup_old_notifications() IS 'Delete notifications older than 30 days that have been read by at least one admin';
