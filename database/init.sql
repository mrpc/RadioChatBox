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

DROP TABLE IF EXISTS url_blacklist CASCADE;
DROP TABLE IF EXISTS banned_nicknames CASCADE;
DROP TABLE IF EXISTS banned_ips CASCADE;
DROP TABLE IF EXISTS user_profiles CASCADE;
DROP TABLE IF EXISTS attachments CASCADE;
DROP TABLE IF EXISTS private_messages CASCADE;
DROP TABLE IF EXISTS active_users CASCADE;
DROP TABLE IF EXISTS users CASCADE;
DROP TABLE IF EXISTS messages CASCADE;
DROP TABLE IF EXISTS settings CASCADE;

-- Drop views
DROP VIEW IF EXISTS recent_messages CASCADE;
DROP VIEW IF EXISTS user_stats CASCADE;

-- Drop functions
DROP FUNCTION IF EXISTS update_user_stats() CASCADE;
DROP FUNCTION IF EXISTS cleanup_inactive_users() CASCADE;

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
    is_deleted BOOLEAN DEFAULT FALSE
);

CREATE INDEX idx_messages_created_at ON messages(created_at DESC);
CREATE INDEX idx_messages_username ON messages(username);
CREATE INDEX idx_messages_ip_address ON messages(ip_address);
CREATE INDEX idx_messages_message_id ON messages(message_id);
CREATE INDEX idx_messages_active_recent ON messages(is_deleted, created_at DESC) WHERE is_deleted = FALSE;

-- Users table for tracking and moderation
CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    session_id VARCHAR(255),
    ip_address VARCHAR(45) NOT NULL,
    first_seen TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    message_count INTEGER DEFAULT 0,
    is_banned BOOLEAN DEFAULT FALSE,
    is_moderator BOOLEAN DEFAULT FALSE
);

CREATE INDEX idx_users_username ON users(username);
CREATE INDEX idx_users_ip_address ON users(ip_address);

-- Active users (currently online)
CREATE TABLE IF NOT EXISTS active_users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    session_id VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    last_heartbeat TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_active_users_username ON active_users(username);
CREATE INDEX idx_active_users_last_heartbeat ON active_users(last_heartbeat);
CREATE INDEX idx_active_users_session_id ON active_users(session_id);

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
    INSERT INTO users (username, ip_address, last_seen, message_count)
    VALUES (NEW.username, NEW.ip_address, NEW.created_at, 1)
    ON CONFLICT (username) 
    DO UPDATE SET
        last_seen = NEW.created_at,
        message_count = users.message_count + 1,
        ip_address = NEW.ip_address;
    
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_update_user_stats
AFTER INSERT ON messages
FOR EACH ROW
EXECUTE FUNCTION update_user_stats();

-- Clean up inactive users (not seen in 5 minutes)
CREATE OR REPLACE FUNCTION cleanup_inactive_users()
RETURNS INTEGER AS $$
DECLARE
    deleted_count INTEGER;
BEGIN
    DELETE FROM active_users
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
    is_moderator
FROM users
ORDER BY message_count DESC;

-- ============================================================================
-- DEFAULT DATA
-- ============================================================================

-- Default system settings
INSERT INTO settings (setting_key, setting_value) VALUES 
    -- Rate limiting
    ('rate_limit_messages', '10'),
    ('rate_limit_window', '60'),
    
    -- Admin authentication (default password: admin123 - CHANGE IN PRODUCTION!)
    ('admin_password_hash', '$2y$10$ZUCvW9SmSpOUwPtWC.XzL.mA0piFBy.DM8TKPHvkWdd0CsG121vCC'),
    
    -- Chat settings
    ('color_scheme', 'dark'),
    ('page_title', 'RadioChatBox'),
    ('require_profile', 'false'),
    ('chat_mode', 'both'),
    ('allow_photo_uploads', 'true'),
    ('max_photo_size_mb', '5'),
    
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
    ('ads_refresh_enabled', 'false')
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
