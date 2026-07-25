-- Migration 026: LLM call log, reasoning switch and a workable token budget
--
-- Why: bot replies were arriving cut off mid-word and incoherent. The cause was
-- invisible without a log - deepseek-v4-* are reasoning models, and the whole
-- 300-token budget was being spent on reasoning:
--
--   finish_reason: "length", content: "",
--   usage.completion_tokens_details.reasoning_tokens: 300
--
-- So the visible answer was whatever few tokens squeezed out before the cap.
--
--   * bot_llm_reasoning  - off by default; sends thinking:{type:disabled}, which
--                          cut a reply from ~195 tokens to ~16 in testing
--   * bot_llm_max_tokens - 300 -> 1000, enough to cover reasoning when it is on
--   * bot_llm_log        - request/response of every call, so a truncated or
--                          nonsensical reply can be traced instead of guessed
--
-- Safe to run multiple times.

-- ============================================================================
-- SETTINGS
-- ============================================================================

INSERT INTO settings (setting_key, setting_value) VALUES
    ('bot_llm_reasoning', 'false'),
    ('bot_llm_log_enabled', 'true'),
    ('bot_llm_log_retention_days', '7')
ON CONFLICT (setting_key) DO NOTHING;

-- Only the untouched default is raised; a deliberately chosen value is kept.
UPDATE settings
SET setting_value = '1000', updated_at = NOW()
WHERE setting_key = 'bot_llm_max_tokens'
  AND setting_value = '300';

-- ============================================================================
-- LLM CALL LOG
-- ============================================================================

CREATE TABLE IF NOT EXISTS bot_llm_log (
    id BIGSERIAL PRIMARY KEY,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Conversation the call belongs to (nullable: not every call is a reply)
    fake_nickname VARCHAR(50),
    peer_username VARCHAR(100),
    purpose VARCHAR(30) NOT NULL DEFAULT 'reply',
    -- Request
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
    error TEXT
);

CREATE INDEX IF NOT EXISTS idx_bot_llm_log_created_at ON bot_llm_log(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_bot_llm_log_thread ON bot_llm_log(fake_nickname, peer_username, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_bot_llm_log_problems ON bot_llm_log(created_at DESC)
    WHERE error IS NOT NULL OR finish_reason <> 'stop';

COMMENT ON TABLE bot_llm_log IS 'Request/response log of every LLM call made for fake user auto-replies';
COMMENT ON COLUMN bot_llm_log.finish_reason IS 'stop = complete; length = the token budget ran out and the reply is truncated';
COMMENT ON COLUMN bot_llm_log.usage IS 'Token usage as reported by the provider, including reasoning_tokens';

-- 1.3 (DeepSeek's suggestion for their older chat model) garbles Greek with
-- reasoning off - wrong grammatical gender, mangled words. Lower the untouched
-- default; a deliberately chosen value is kept.
UPDATE settings
SET setting_value = '1.0', updated_at = NOW()
WHERE setting_key = 'bot_llm_temperature'
  AND setting_value = '1.3';
