-- Migration 025: Add the bot conversation-context prompt
--
-- The generated prompt described who the bot is but not where it is talking, so
-- the model answered common openers literally: "είσαι ελεύθερη;" in a dating
-- chat asks about relationship status, and it replied about having time to chat.
--
-- Empty means "use BotService::DEFAULT_CONTEXT_PROMPT".
--
-- Safe to run multiple times.

INSERT INTO settings (setting_key, setting_value)
VALUES ('bot_context_prompt', '')
ON CONFLICT (setting_key) DO NOTHING;
