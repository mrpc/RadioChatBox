-- Admin Notifications Migration
-- Adds notification system for admin panel alerts
--
-- Use cases:
-- - Alert admins when users send DMs to fake users
-- - Future: Other admin-worthy events (reports, suspicious activity, etc.)
--
-- Permissions: Only admins with 'view_private_messages' permission can see notifications
-- (root and administrator roles)
--
-- Architecture: Per-admin notifications
-- - Each notification exists once in admin_notifications table
-- - Each admin has their own read state in admin_notification_reads table
-- - When Admin A marks as read, Admin B still sees as unread

-- ============================================================================
-- ADMIN NOTIFICATIONS TABLE
-- ============================================================================

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

-- ============================================================================
-- ADMIN NOTIFICATION READS TABLE (Per-Admin Read States)
-- ============================================================================

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
-- HELPER FUNCTIONS
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
BEGIN
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

COMMENT ON FUNCTION create_fake_user_dm_notification(VARCHAR, VARCHAR, TEXT, INTEGER) IS 'Create notification when user sends DM to fake user';

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

COMMENT ON FUNCTION mark_notification_read(INTEGER, VARCHAR) IS 'Mark notification as read for specific admin';

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

COMMENT ON FUNCTION mark_all_notifications_read(VARCHAR) IS 'Mark all unread notifications as read for specific admin';

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

COMMENT ON FUNCTION get_unread_notification_count(VARCHAR) IS 'Get count of unread notifications for specific admin';

-- Function to cleanup old notifications (keep last 30 days)
-- Only delete notifications that ALL admins have read
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

COMMENT ON FUNCTION cleanup_old_notifications() IS 'Delete notifications older than 30 days that have been read by at least one admin';
