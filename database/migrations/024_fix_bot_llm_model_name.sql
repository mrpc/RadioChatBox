-- Migration 024: Correct the default bot LLM model name
--
-- Migration 023 shipped 'deepseek-chat', which the API no longer accepts:
--   HTTP 400 - The supported API model names are deepseek-v4-pro or
--   deepseek-v4-flash, but you passed deepseek-chat
--
-- Every bot reply failed with that error and was retried until it gave up.
-- Only the stale value is replaced, so a deliberately configured model (for a
-- custom OpenAI-compatible endpoint) is left alone.
--
-- Safe to run multiple times.

UPDATE settings
SET setting_value = 'deepseek-v4-flash',
    updated_at = NOW()
WHERE setting_key = 'bot_llm_model'
  AND setting_value IN ('deepseek-chat', 'deepseek-reasoner', '');

-- Fill it in if the row is missing entirely (installs that skipped 023's insert).
INSERT INTO settings (setting_key, setting_value)
VALUES ('bot_llm_model', 'deepseek-v4-flash')
ON CONFLICT (setting_key) DO NOTHING;
