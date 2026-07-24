-- Migration 017: Add dm_blocks table
-- Description: Lets a user block another user in direct messages. Blocking is
--              mutual (bidirectional): if A blocks B, neither A nor B can send
--              a DM to the other. Keyed by username (with optional user_id for
--              registered users), mirroring private_messages identity handling.
-- Date: 2026-07-24

CREATE TABLE IF NOT EXISTS dm_blocks (
    id SERIAL PRIMARY KEY,
    blocker_username VARCHAR(100) NOT NULL,
    blocker_user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
    blocked_username VARCHAR(100) NOT NULL,
    blocked_user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- NULL = never expires (blocker is a registered user, whose username is
    -- reserved). When the blocker is a guest, this is set to a future time so
    -- the block auto-expires and does not linger on a reusable nickname.
    expires_at TIMESTAMPTZ,
    CONSTRAINT uq_dm_blocks_pair UNIQUE (blocker_username, blocked_username)
);

-- Older installs created before expires_at existed: add it idempotently.
ALTER TABLE dm_blocks ADD COLUMN IF NOT EXISTS expires_at TIMESTAMPTZ;

-- Fast lookups from both directions (mutual block check)
CREATE INDEX IF NOT EXISTS idx_dm_blocks_blocker ON dm_blocks(blocker_username);
CREATE INDEX IF NOT EXISTS idx_dm_blocks_blocked ON dm_blocks(blocked_username);
CREATE INDEX IF NOT EXISTS idx_dm_blocks_expires ON dm_blocks(expires_at);

COMMENT ON TABLE dm_blocks IS 'User-initiated DM blocks. Enforced mutually: a row (A,B) blocks DMs in both directions between A and B. Guest-created blocks expire via expires_at.';
COMMENT ON COLUMN dm_blocks.blocker_username IS 'The user who created the block.';
COMMENT ON COLUMN dm_blocks.blocked_username IS 'The user who was blocked.';
COMMENT ON COLUMN dm_blocks.expires_at IS 'When the block auto-expires. NULL = permanent (registered blocker); set for guest blockers.';
