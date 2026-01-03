-- Migration 009: Update notification function to prevent duplicates
-- This updates the create_fake_user_dm_notification function to prevent duplicate
-- notifications for the same conversation until the admin marks them as read

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

COMMENT ON FUNCTION create_fake_user_dm_notification(VARCHAR, VARCHAR, TEXT, INTEGER) IS 'Create or update notification when user sends DM to fake user - prevents duplicates until admin reads';
