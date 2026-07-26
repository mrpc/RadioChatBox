<?php

namespace RadioChatBox\Migrations;

use Pramnos\Database\Migration;

/**
 * Migrated from database/migrations/023_add_bot_replies.sql.
 *
 * PostgreSQL-specific SQL (plpgsql functions / triggers / guarded DO blocks /
 * data backfills) that the schema-builder DSL cannot express, kept verbatim and
 * self-contained. Idempotent, so the tracked runner records it as applied on an
 * existing database and it builds the schema on a fresh one.
 */
final class AddBotReplies extends Migration
{
    public $description = 'Migrated: 023_add_bot_replies.sql';

    // The SQL manages its own transactions; do not double-wrap.
    public bool $transactional = false;

    public function up(): void
    {
        $sql = <<<'SQL'
-- Migration 023: Automatic LLM replies for fake users (bots)
--
-- Everything the auto-reply feature needs:
--   * per-fake-user bot configuration on fake_users
--   * bot_threads: per-conversation state (message budget, admin takeover,
--     rolling summary of what fell out of the history window)
--   * bot_llm_log: request/response of every LLM call, which is the only way to
--     tell a truncated reply from a bad one
--   * the bot_* settings
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
    ADD COLUMN IF NOT EXISTS bot_farewell_messages TEXT,
    -- Chance (%) that this bot ignores a new conversation outright
    ADD COLUMN IF NOT EXISTS bot_ignore_chance INTEGER,
    -- Per-bot provider and model override, so different bots can run on
    -- different LLMs at the same time (NULL = use the global setting)
    ADD COLUMN IF NOT EXISTS bot_llm_provider VARCHAR(30),
    ADD COLUMN IF NOT EXISTS bot_llm_model VARCHAR(100),
    -- Script to write in: NULL/'auto' mirrors whatever the peer uses
    ADD COLUMN IF NOT EXISTS bot_reply_language VARCHAR(20);

ALTER TABLE fake_users
    DROP CONSTRAINT IF EXISTS valid_bot_ignore_chance;
ALTER TABLE fake_users
    ADD CONSTRAINT valid_bot_ignore_chance
    CHECK (bot_ignore_chance IS NULL OR (bot_ignore_chance >= 0 AND bot_ignore_chance <= 100));

ALTER TABLE fake_users
    DROP CONSTRAINT IF EXISTS valid_bot_reply_language;
ALTER TABLE fake_users
    ADD CONSTRAINT valid_bot_reply_language
    CHECK (bot_reply_language IS NULL OR bot_reply_language IN ('auto', 'greek', 'greeklish', 'english'));

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
COMMENT ON COLUMN fake_users.bot_custom_prompt IS 'Full persona override; when set, replaces the generated persona';
COMMENT ON COLUMN fake_users.bot_max_messages IS 'Per-bot override of bot_max_messages_per_thread (NULL = use global setting)';
COMMENT ON COLUMN fake_users.bot_typing_seconds_per_word IS 'Per-bot override of bot_typing_seconds_per_word (NULL = use global setting)';
COMMENT ON COLUMN fake_users.bot_farewell_messages IS 'Per-bot goodbye variants, one per line, used only if the closing LLM call fails';
COMMENT ON COLUMN fake_users.bot_ignore_chance IS 'Per-bot chance (%) of ignoring a new conversation for good (NULL = use bot_ignore_chance setting)';
COMMENT ON COLUMN fake_users.bot_llm_provider IS 'Per-bot LLM provider override, e.g. deepseek or openai (NULL = use bot_llm_provider setting)';
COMMENT ON COLUMN fake_users.bot_llm_model IS 'Per-bot model override (NULL = use bot_llm_model setting)';
COMMENT ON COLUMN fake_users.bot_reply_language IS 'Script the bot writes in: auto (mirror the peer), greek, greeklish or english';

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
    -- Real people don't answer every stranger. Decided once, on the first inbound
    -- message of a thread, and then kept: silence has to be consistent.
    is_ignored BOOLEAN NOT NULL DEFAULT FALSE,
    ignore_decided_at TIMESTAMPTZ,
    -- Abuse: strikes counted per conversation, and when the bot blocked the peer
    insult_count INTEGER NOT NULL DEFAULT 0,
    last_insult_at TIMESTAMPTZ,
    blocked_at TIMESTAMPTZ,
    -- Rolling summary of the messages that fell out of the history window
    summary TEXT,
    summary_upto_id BIGINT,
    summary_updated_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uniq_bot_thread UNIQUE (fake_user_id, peer_username)
);

-- Added separately as well, so a database created by a pre-release run of this
-- file (before the summary existed) picks the columns up too.
ALTER TABLE bot_threads
    ADD COLUMN IF NOT EXISTS summary TEXT,
    ADD COLUMN IF NOT EXISTS summary_upto_id BIGINT,
    ADD COLUMN IF NOT EXISTS summary_updated_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS is_ignored BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS ignore_decided_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS insult_count INTEGER NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS last_insult_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS blocked_at TIMESTAMPTZ;

CREATE INDEX IF NOT EXISTS idx_bot_threads_peer ON bot_threads(peer_username);

COMMENT ON TABLE bot_threads IS 'Per-conversation state for fake user auto-replies (message budget, admin takeover, summary)';
COMMENT ON COLUMN bot_threads.messages_sent IS 'Messages the BOT authored in this thread (admin impersonation replies are not counted)';
COMMENT ON COLUMN bot_threads.is_taken_over IS 'TRUE once an admin impersonated this fake user in this thread - the bot stays silent';
COMMENT ON COLUMN bot_threads.farewell_sent_at IS 'When the closing message was sent; the bot never replies again after this';
COMMENT ON COLUMN bot_threads.insult_count IS 'Abusive messages received from this peer in this conversation';
COMMENT ON COLUMN bot_threads.blocked_at IS 'When the bot blocked this peer over repeated abuse (a real dm_blocks row, same as the DM block button)';
COMMENT ON COLUMN bot_threads.is_ignored IS 'TRUE when the bot decided to ignore this conversation from the start - it never replies in it';
COMMENT ON COLUMN bot_threads.ignore_decided_at IS 'When the ignore/reply decision was taken, so a burst of messages does not re-roll it';
COMMENT ON COLUMN bot_threads.summary IS 'Rolling summary of the messages that fell out of the history window';
COMMENT ON COLUMN bot_threads.summary_upto_id IS 'Highest private_messages.id covered by the summary';

-- ============================================================================
-- LLM CALL LOG
-- ============================================================================
-- Without this a bad reply is undiagnosable. It is what showed the whole token
-- budget being spent on reasoning (finish_reason "length", empty content),
-- which is why replies were arriving cut off mid-word.

CREATE TABLE IF NOT EXISTS bot_llm_log (
    id BIGSERIAL PRIMARY KEY,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Conversation the call belongs to (nullable: not every call is a reply)
    fake_nickname VARCHAR(50),
    peer_username VARCHAR(100),
    purpose VARCHAR(30) NOT NULL DEFAULT 'reply',
    -- Request
    provider VARCHAR(30),
    model VARCHAR(100) NOT NULL,
    endpoint VARCHAR(255),
    system_prompt TEXT,
    messages JSONB,
    max_tokens INTEGER,
    temperature NUMERIC(4,2),
    reasoning BOOLEAN NOT NULL DEFAULT FALSE,
    -- Response
    http_status INTEGER,
    finish_reason VARCHAR(40),
    reply TEXT,
    usage JSONB,
    duration_ms INTEGER,
    error TEXT,
    -- What the call cost, priced at write time so history survives a price change
    cost NUMERIC(14,8),
    currency VARCHAR(3)
);

ALTER TABLE bot_llm_log
    ADD COLUMN IF NOT EXISTS cost NUMERIC(14,8),
    ADD COLUMN IF NOT EXISTS currency VARCHAR(3),
    ADD COLUMN IF NOT EXISTS provider VARCHAR(30);

CREATE INDEX IF NOT EXISTS idx_bot_llm_log_created_at ON bot_llm_log(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_bot_llm_log_thread ON bot_llm_log(fake_nickname, peer_username, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_bot_llm_log_problems ON bot_llm_log(created_at DESC)
    WHERE error IS NOT NULL OR finish_reason <> 'stop';

COMMENT ON TABLE bot_llm_log IS 'Request/response log of every LLM call made for fake user auto-replies';
COMMENT ON COLUMN bot_llm_log.finish_reason IS 'stop = complete; length = the token budget ran out and the reply is truncated';
COMMENT ON COLUMN bot_llm_log.usage IS 'Token usage as reported by the provider, including reasoning_tokens';
COMMENT ON COLUMN bot_llm_log.cost IS 'Cost of this call from the bot_llm_prices unit prices, priced when it was made (NULL = no price configured for the model)';

-- ============================================================================
-- PROVIDER BALANCE SNAPSHOTS
-- ============================================================================
-- bot_llm_log.cost is an estimate: the provider exposes no pricing endpoint, so
-- the unit prices are configured (bot_llm_prices). GET /user/balance does return
-- real money, so periodic readings are kept here - the drop between two of them
-- is actual spend, and comparing that with the estimate shows when the configured
-- prices have gone stale.

CREATE TABLE IF NOT EXISTS bot_llm_balance (
    id BIGSERIAL PRIMARY KEY,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Balances are per provider, so spend is measured against the right account
    provider VARCHAR(30) NOT NULL DEFAULT 'deepseek',
    currency VARCHAR(3) NOT NULL,
    total_balance NUMERIC(14,4) NOT NULL,
    granted_balance NUMERIC(14,4),
    topped_up_balance NUMERIC(14,4)
);

ALTER TABLE bot_llm_balance
    ADD COLUMN IF NOT EXISTS provider VARCHAR(30) NOT NULL DEFAULT 'deepseek';

CREATE INDEX IF NOT EXISTS idx_bot_llm_balance_created_at ON bot_llm_balance(provider, created_at DESC);

COMMENT ON TABLE bot_llm_balance IS 'Periodic readings of the LLM provider balance (GET /user/balance); consecutive drops are real spend';

-- Price the calls that were logged before the cost column existed, so the admin
-- panel is not full of unpriced rows. Only NULL costs are touched, at the same
-- rates as src/LlmPricing.php, and a bucket split the provider did not report is
-- billed as uncached - which is how it was charged.
UPDATE bot_llm_log AS l
SET cost = (
        COALESCE((l.usage->>'prompt_cache_hit_tokens')::numeric, 0) * p.cache_hit
        + CASE
            WHEN COALESCE((l.usage->>'prompt_cache_hit_tokens')::numeric, 0) = 0
             AND COALESCE((l.usage->>'prompt_cache_miss_tokens')::numeric, 0) = 0
            THEN COALESCE((l.usage->>'prompt_tokens')::numeric, 0)
            ELSE COALESCE((l.usage->>'prompt_cache_miss_tokens')::numeric, 0)
          END * p.cache_miss
        + COALESCE((l.usage->>'completion_tokens')::numeric, 0) * p.output
    ) / 1000000,
    currency = 'USD'
FROM (VALUES
    ('deepseek-v4-flash', 0.0028::numeric, 0.14::numeric, 0.28::numeric),
    ('deepseek-v4-pro', 0.003625::numeric, 0.435::numeric, 0.87::numeric)
) AS p(model, cache_hit, cache_miss, output)
WHERE l.cost IS NULL AND l.usage IS NOT NULL AND l.model = p.model;

-- Same for the provider: everything logged before the column existed was DeepSeek,
-- which was the only provider at the time.
UPDATE bot_llm_log SET provider = 'deepseek'
WHERE provider IS NULL AND model LIKE 'deepseek%';

-- ============================================================================
-- DEFAULT SETTINGS
-- ============================================================================
-- The API key is managed from the admin panel like the other third-party keys.
-- SettingsService::getPublicSettings() strips every bot_* key, so it is never
-- part of the payload served to the public frontend.
--
-- Notes on the values:
--   bot_llm_model       deepseek-chat / deepseek-reasoner were deprecated on
--                       2026-07-24; the v4 names are the supported ones.
--   bot_llm_max_tokens  covers the model's internal reasoning as well as the
--                       answer. At 300 the reasoning consumed all of it and the
--                       reply arrived truncated.
--   bot_llm_reasoning   off: on a one-line chat reply it spent ~175 tokens for
--                       no visible benefit (16 tokens per reply with it off).
--   bot_llm_temperature 1.3 is DeepSeek's suggestion for casual chat, but with
--                       reasoning off it garbles Greek grammar.
--   bot_llm_provider    which API the bots call by default (see
--                       src/LlmProviders.php). Every provider has its OWN full
--                       parameter set - bot_llm_* for DeepSeek, bot_openai_* for
--                       OpenAI - so all of them stay configured at the same time
--                       and a fake user can point itself at any of them
--                       (fake_users.bot_llm_provider / bot_llm_model) without being
--                       affected by another provider's settings.
--   bot_openai_admin_key
--                       optional organisation admin key (sk-admin-...). OpenAI has
--                       no balance endpoint, but GET /organization/costs reports what
--                       was really spent - and only to an admin key, which is why it
--                       is separate from the key used for chat requests.
--   bot_llm_prices      unit prices per 1M tokens as JSON, because the provider
--                       has no pricing endpoint (/models returns ids only). Empty
--                       means the built-in table in src/LlmPricing.php; editing
--                       this setting is how a price change is applied, without a
--                       deploy. Real spend comes from GET /user/balance instead.
--   bot_ignore_chance   how often (%) a bot ignores a NEW conversation outright,
--                       because a real person does not answer every stranger.
--                       Reported reply rates on dating sites are far lower (a first
--                       message from a man is answered roughly a third of the time),
--                       but ignoring two out of three would throw away most of what
--                       the feature is for; 30 is the compromise. The decision is
--                       taken once, on the first inbound message, and stored on the
--                       thread - a bot never goes quiet mid-conversation.
--   bot_insult_block_threshold
--                       how many abusive messages a bot takes before it blocks the
--                       peer, using the same dm_blocks mechanism as the DM block
--                       button. 0 disables it. Repetition is required on purpose:
--                       in Greek chat a single "ρε μαλάκα" is often banter.
--   empty prompt/list   means "use the built-in one" (see src/BotService.php).

INSERT INTO settings (setting_key, setting_value) VALUES
    ('bot_replies_enabled', 'false'),
    ('bot_llm_provider', 'deepseek'),
    ('bot_llm_api_key', ''),
    ('bot_llm_base_url', 'https://api.deepseek.com'),
    ('bot_openai_api_key', ''),
    ('bot_openai_base_url', 'https://api.openai.com/v1'),
    ('bot_openai_model', 'gpt-5.4-mini'),
    ('bot_openai_temperature', '1.0'),
    ('bot_openai_max_tokens', '1000'),
    ('bot_openai_admin_key', ''),
    ('bot_llm_model', 'deepseek-v4-flash'),
    ('bot_llm_temperature', '1.0'),
    ('bot_llm_max_tokens', '1000'),
    ('bot_llm_reasoning', 'false'),
    ('bot_llm_log_enabled', 'true'),
    ('bot_llm_prices', ''),
    ('bot_llm_currency', 'USD'),
    ('bot_llm_log_retention_days', '7'),
    ('bot_max_messages_per_thread', '4'),
    ('bot_ignore_chance', '30'),
    ('bot_insult_block_threshold', '3'),
    ('bot_history_limit', '20'),
    ('bot_summary_enabled', 'true'),
    ('bot_summary_prompt', ''),
    ('bot_context_prompt', ''),
    ('bot_farewell_prompt', ''),
    ('bot_farewell_messages', ''),
    ('bot_typing_seconds_per_word', '1.5'),
    ('bot_typing_min_delay', '2'),
    ('bot_typing_max_delay', '45'),
    ('bot_read_delay_min', '2'),
    ('bot_read_delay_max', '8')
ON CONFLICT (setting_key) DO NOTHING;

-- Repair values left behind by a pre-release run of this migration. Only the
-- stale defaults are touched, so a deliberately configured value is kept.
UPDATE settings SET setting_value = 'deepseek-v4-flash', updated_at = NOW()
WHERE setting_key = 'bot_llm_model' AND setting_value IN ('deepseek-chat', 'deepseek-reasoner', '');

UPDATE settings SET setting_value = '1000', updated_at = NOW()
WHERE setting_key = 'bot_llm_max_tokens' AND setting_value = '300';

UPDATE settings SET setting_value = '1.0', updated_at = NOW()
WHERE setting_key = 'bot_llm_temperature' AND setting_value = '1.3';
SQL;
        $this->DB()->statement($sql);
    }

    public function down(): void
    {
        // Baselined migration: no automated rollback.
    }
}
