-- Migration 013: Add display_name snapshots to messages for audit purposes
-- Created: 2026-01-09
-- Description: Stores display_name at message send time for historical audit trail.
--              UI continues to show current display_name via JOINs, but admins can 
--              see what name was used when message was actually sent.

BEGIN;

-- Add display_name snapshot to public messages
ALTER TABLE messages 
ADD COLUMN IF NOT EXISTS display_name VARCHAR(100);

COMMENT ON COLUMN messages.display_name IS 'Display name at time message was sent (snapshot for audit trail - UI shows current display_name via JOIN)';

-- Add display_name snapshots to private messages
ALTER TABLE private_messages
ADD COLUMN IF NOT EXISTS from_display_name VARCHAR(100),
ADD COLUMN IF NOT EXISTS to_display_name VARCHAR(100);

COMMENT ON COLUMN private_messages.from_display_name IS 'Sender display name at time message was sent (snapshot for audit trail - UI shows current display_name via JOIN)';
COMMENT ON COLUMN private_messages.to_display_name IS 'Recipient display name at time message was sent (snapshot for audit trail - UI shows current display_name via JOIN)';

COMMIT;
