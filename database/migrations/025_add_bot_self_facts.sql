-- Migration: A bot's self-facts "canon".
-- Purpose: stable facts the bot has improvised about itself (appearance, small
--          personal details) so it stays consistent within and ACROSS
--          conversations — e.g. always giving the same description of itself.
-- Bot-level (one per fake user), unlike bot_threads.summary which is per peer.

ALTER TABLE fake_users
    ADD COLUMN IF NOT EXISTS bot_self_facts TEXT;

COMMENT ON COLUMN fake_users.bot_self_facts IS
    'Canon of stable self-facts the bot has committed to (appearance, personal details), injected into every reply so it stays consistent across conversations.';
