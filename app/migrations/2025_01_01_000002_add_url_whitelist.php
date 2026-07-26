<?php

namespace RadioChatBox\Migrations;

use Pramnos\Database\Migration;

/**
 * Migrated from database/migrations/004_add_url_whitelist.sql.
 *
 * PostgreSQL-specific SQL (plpgsql functions / triggers / guarded DO blocks /
 * data backfills) that the schema-builder DSL cannot express, kept verbatim and
 * self-contained. Idempotent, so the tracked runner records it as applied on an
 * existing database and it builds the schema on a fresh one.
 */
final class AddUrlWhitelist extends Migration
{
    public $description = 'Migrated: 004_add_url_whitelist.sql';

    // The SQL manages its own transactions; do not double-wrap.
    public bool $transactional = false;

    public function up(): void
    {
        $sql = <<<'SQL'
-- Migration: Add URL Whitelist Table
-- Description: Create table to store URL patterns that are allowed in public messages
-- Date: 2025-12-31

-- Create URL whitelist table
CREATE TABLE IF NOT EXISTS url_whitelist (
    id SERIAL PRIMARY KEY,
    pattern VARCHAR(500) UNIQUE NOT NULL,
    description TEXT,
    added_by VARCHAR(100) DEFAULT 'admin',
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Add index for faster pattern matching
CREATE INDEX IF NOT EXISTS idx_url_whitelist_pattern ON url_whitelist(pattern);

-- Add some default whitelisted patterns (popular social media and video platforms)
INSERT INTO url_whitelist (pattern, description, added_by) VALUES
    ('youtube.com', 'YouTube video platform', 'system'),
    ('youtu.be', 'YouTube short links', 'system'),
    ('twitter.com', 'Twitter social media', 'system'),
    ('x.com', 'X (Twitter) social media', 'system'),
    ('facebook.com', 'Facebook social media', 'system'),
    ('instagram.com', 'Instagram social media', 'system'),
    ('tiktok.com', 'TikTok video platform', 'system'),
    ('spotify.com', 'Spotify music streaming', 'system'),
    ('soundcloud.com', 'SoundCloud music platform', 'system'),
    ('twitch.tv', 'Twitch streaming platform', 'system')
ON CONFLICT (pattern) DO NOTHING;

-- Add comment to table
COMMENT ON TABLE url_whitelist IS 'Stores URL patterns that are allowed in public chat messages. URLs matching these patterns will not be replaced with ***.';
COMMENT ON COLUMN url_whitelist.pattern IS 'URL pattern (domain or wildcard like *.example.com)';
COMMENT ON COLUMN url_whitelist.description IS 'Human-readable description of this pattern';
COMMENT ON COLUMN url_whitelist.added_by IS 'Username who added this pattern';
COMMENT ON COLUMN url_whitelist.added_at IS 'Timestamp when pattern was added';
SQL;
        $this->DB()->statement($sql);
    }

    public function down(): void
    {
        // Baselined migration: no automated rollback.
    }
}
