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
    reply_to VARCHAR(255) DEFAULT NULL
);

CREATE INDEX idx_messages_created_at ON messages(created_at DESC);
CREATE INDEX idx_messages_username ON messages(username);
CREATE INDEX idx_messages_ip_address ON messages(ip_address);
CREATE INDEX idx_messages_message_id ON messages(message_id);
CREATE INDEX idx_messages_active_recent ON messages(is_deleted, created_at DESC) WHERE is_deleted = FALSE;
CREATE INDEX idx_messages_reply_to ON messages(reply_to);

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
    user_id INTEGER REFERENCES users(id) ON DELETE SET NULL
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
    user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
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

-- Reserved nicknames
INSERT INTO banned_nicknames (nickname, reason, banned_by) VALUES 
    ('admin', 'Reserved for administrators', 'system'),
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


-- Migration 004: Allow authenticated users to have multiple simultaneous sessions
-- Note: This constraint is now part of the sessions table definition above
-- (sessions_username_session_unique constraint)
-- Regular users still limited to one session per username
-- Authenticated users can join from multiple devices

-- Note: The application logic in ChatService.php has been updated to:
-- 1. Allow authenticated usernames to register (no longer blocked)
-- 2. Allow authenticated usernames to have multiple active sessions
-- 3. Regular users still enforced to one session per username
