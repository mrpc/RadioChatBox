-- Migration 023: Automatic LLM replies for fake users (bots)
--
-- Adds per-fake-user bot configuration and a per-conversation state table so
-- the bot can (a) count how many messages it already sent in a thread,
-- (b) stop permanently after the graceful exit, and (c) be taken over by an
-- admin through the Impersonate tab.
--
-- Safe to run multiple times.

-- ============================================================================
-- FAKE USER BOT CONFIGURATION
-- ============================================================================

ALTER TABLE fake_users
    ADD COLUMN IF NOT EXISTS bot_enabled BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS bot_persona TEXT,
    ADD COLUMN IF NOT EXISTS bot_custom_prompt TEXT,
    ADD COLUMN IF NOT EXISTS bot_max_messages INTEGER,
    ADD COLUMN IF NOT EXISTS bot_typing_seconds_per_word NUMERIC(4,2),
    ADD COLUMN IF NOT EXISTS bot_farewell_messages TEXT;

ALTER TABLE fake_users
    DROP CONSTRAINT IF EXISTS valid_bot_max_messages;
ALTER TABLE fake_users
    ADD CONSTRAINT valid_bot_max_messages
    CHECK (bot_max_messages IS NULL OR (bot_max_messages >= 0 AND bot_max_messages <= 100));

ALTER TABLE fake_users
    DROP CONSTRAINT IF EXISTS valid_bot_typing_speed;
ALTER TABLE fake_users
    ADD CONSTRAINT valid_bot_typing_speed
    CHECK (bot_typing_seconds_per_word IS NULL OR (bot_typing_seconds_per_word >= 0 AND bot_typing_seconds_per_word <= 10));

CREATE INDEX IF NOT EXISTS idx_fake_users_bot_enabled ON fake_users(bot_enabled) WHERE bot_enabled = TRUE;

COMMENT ON COLUMN fake_users.bot_enabled IS 'Whether this fake user auto-replies to private messages via the LLM';
COMMENT ON COLUMN fake_users.bot_persona IS 'Extra personality/interests appended to the generated system prompt';
COMMENT ON COLUMN fake_users.bot_custom_prompt IS 'Full system prompt override; when set, replaces the generated prompt entirely';
COMMENT ON COLUMN fake_users.bot_max_messages IS 'Per-bot override of bot_max_messages_per_thread (NULL = use global setting)';
COMMENT ON COLUMN fake_users.bot_typing_seconds_per_word IS 'Per-bot override of bot_typing_seconds_per_word (NULL = use global setting)';
COMMENT ON COLUMN fake_users.bot_farewell_messages IS 'Per-bot goodbye variants, one per line, picked at random (NULL = use global setting)';

-- ============================================================================
-- PER-CONVERSATION BOT STATE
-- ============================================================================

CREATE TABLE IF NOT EXISTS bot_threads (
    id SERIAL PRIMARY KEY,
    fake_user_id INTEGER NOT NULL REFERENCES fake_users(id) ON DELETE CASCADE,
    peer_username VARCHAR(100) NOT NULL,
    messages_sent INTEGER NOT NULL DEFAULT 0,
    is_taken_over BOOLEAN NOT NULL DEFAULT FALSE,
    taken_over_at TIMESTAMPTZ,
    taken_over_by VARCHAR(100),
    farewell_sent_at TIMESTAMPTZ,
    last_reply_at TIMESTAMPTZ,
    last_error TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uniq_bot_thread UNIQUE (fake_user_id, peer_username)
);

CREATE INDEX IF NOT EXISTS idx_bot_threads_peer ON bot_threads(peer_username);

COMMENT ON TABLE bot_threads IS 'Per-conversation state for fake user auto-replies (message budget, admin takeover)';
COMMENT ON COLUMN bot_threads.messages_sent IS 'Messages the BOT authored in this thread (admin impersonation replies are not counted)';
COMMENT ON COLUMN bot_threads.is_taken_over IS 'TRUE once an admin impersonated this fake user in this thread - the bot stays silent';
COMMENT ON COLUMN bot_threads.farewell_sent_at IS 'When the graceful exit message was sent; the bot never replies again after this';

-- ============================================================================
-- DEFAULT SETTINGS
-- ============================================================================
-- The API key is managed from the admin panel like the other third-party keys.
-- SettingsService::getPublicSettings() strips every bot_* key, so it is never
-- part of the payload served to the public frontend.

INSERT INTO settings (setting_key, setting_value) VALUES
    ('bot_replies_enabled', 'false'),
    ('bot_llm_api_key', ''),
    ('bot_llm_base_url', 'https://api.deepseek.com'),
    ('bot_llm_model', 'deepseek-v4-flash'),
    ('bot_llm_temperature', '1.3'),
    ('bot_llm_max_tokens', '300'),
    ('bot_max_messages_per_thread', '4'),
    ('bot_typing_seconds_per_word', '1.5'),
    ('bot_typing_min_delay', '2'),
    ('bot_typing_max_delay', '45'),
    ('bot_read_delay_min', '2'),
    ('bot_read_delay_max', '8'),
    ('bot_history_limit', '20'),
    -- Closing instruction: the bot's last message is still written by the LLM
    -- so the goodbye fits the conversation instead of being a canned line.
    ('bot_farewell_prompt', ''),
    -- Fallback goodbye variants, one per line (used only when the closing LLM
    -- call fails); they also serve as tone examples for the closing message.
    -- Empty means "use the 50+ built-in variants in BotService::DEFAULT_FAREWELLS".
    ('bot_farewell_messages', '')
ON CONFLICT (setting_key) DO NOTHING;
