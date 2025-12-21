-- Add reply_to column to messages table for message threading
-- This allows users to reply to specific messages and maintain conversation context

ALTER TABLE messages 
ADD COLUMN reply_to VARCHAR(255) DEFAULT NULL;

-- Add foreign key reference (soft reference since message_id is not a true FK)
CREATE INDEX idx_messages_reply_to ON messages(reply_to);

-- Add comment for documentation
COMMENT ON COLUMN messages.reply_to IS 'References the message_id of the parent message being replied to';
