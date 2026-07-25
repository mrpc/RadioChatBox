-- Migration 027: Rolling summary of what fell out of the history window
--
-- The bot only sees the last bot_history_limit messages. At the default budget
-- of four bot replies a thread never gets that long, but a bot with raised
-- limits will, and everything older would simply vanish - the bot would forget
-- names and plans it had just been told.
--
-- So the messages that drop out of the window are summarised once, stored here,
-- and prepended to the prompt. The summary only changes when the window slides
-- again, which also keeps the cacheable prompt prefix stable.
--
-- Safe to run multiple times.

ALTER TABLE bot_threads
    ADD COLUMN IF NOT EXISTS summary TEXT,
    ADD COLUMN IF NOT EXISTS summary_upto_id BIGINT,
    ADD COLUMN IF NOT EXISTS summary_updated_at TIMESTAMPTZ;

COMMENT ON COLUMN bot_threads.summary IS 'Rolling summary of the messages that fell out of the history window';
COMMENT ON COLUMN bot_threads.summary_upto_id IS 'Highest private_messages.id covered by the summary';

INSERT INTO settings (setting_key, setting_value) VALUES
    ('bot_summary_enabled', 'true'),
    ('bot_summary_prompt', '')
ON CONFLICT (setting_key) DO NOTHING;
