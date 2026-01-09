-- Migration 012: Backfill user_id for ALL messages where username matches a registered user
-- This updates both public messages and private messages

-- Update public messages
UPDATE messages m
SET user_id = u.id
FROM users u
WHERE m.username = u.username
  AND m.user_id IS NULL;

-- Update private messages from_user_id
UPDATE private_messages pm
SET from_user_id = u.id
FROM users u
WHERE pm.from_username = u.username
  AND pm.from_user_id IS NULL;

-- Update private messages to_user_id
UPDATE private_messages pm
SET to_user_id = u.id
FROM users u
WHERE pm.to_username = u.username
  AND pm.to_user_id IS NULL;

