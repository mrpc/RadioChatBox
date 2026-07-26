<?php

namespace RadioChatBox\Migrations;

use Pramnos\Database\Migration;

/**
 * Migrated from database/migrations/019_add_track_plays.sql.
 *
 * PostgreSQL-specific SQL (plpgsql functions / triggers / guarded DO blocks /
 * data backfills) that the schema-builder DSL cannot express, kept verbatim and
 * self-contained. Idempotent, so the tracked runner records it as applied on an
 * existing database and it builds the schema on a fresh one.
 */
final class AddTrackPlays extends Migration
{
    public $description = 'Migrated: 019_add_track_plays.sql';

    // The SQL manages its own transactions; do not double-wrap.
    public bool $transactional = false;

    public function up(): void
    {
        $sql = <<<'SQL'
-- Migration 019: Add tracks + track_plays tables
-- Description: Normalized radio play history. `tracks` holds each unique track
--              once (keyed by its display string); `track_plays` records every
--              play (one row per detected track change).
-- Date: 2026-07-24
--
-- Self-healing: this migration is safe to re-run and repairs a `tracks` table
-- that was created by an earlier/partial run missing columns (ADD COLUMN IF
-- NOT EXISTS fills in anything absent).

CREATE TABLE IF NOT EXISTS tracks (
    id SERIAL PRIMARY KEY,
    artist VARCHAR(300),
    title VARCHAR(300),
    display VARCHAR(500),
    first_played_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_played_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    play_count INTEGER NOT NULL DEFAULT 0
);

-- Backfill any missing columns on an already-created tracks table.
ALTER TABLE tracks ADD COLUMN IF NOT EXISTS artist VARCHAR(300);
ALTER TABLE tracks ADD COLUMN IF NOT EXISTS title VARCHAR(300);
ALTER TABLE tracks ADD COLUMN IF NOT EXISTS display VARCHAR(500);
ALTER TABLE tracks ADD COLUMN IF NOT EXISTS first_played_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE tracks ADD COLUMN IF NOT EXISTS last_played_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE tracks ADD COLUMN IF NOT EXISTS play_count INTEGER NOT NULL DEFAULT 0;

-- Unique key on display (grouping key). Added idempotently.
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'uq_tracks_display') THEN
        ALTER TABLE tracks ADD CONSTRAINT uq_tracks_display UNIQUE (display);
    END IF;
END$$;

CREATE TABLE IF NOT EXISTS track_plays (
    id SERIAL PRIMARY KEY,
    track_id INTEGER NOT NULL REFERENCES tracks(id) ON DELETE CASCADE,
    listeners INTEGER,
    played_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_track_plays_played_at ON track_plays(played_at);
CREATE INDEX IF NOT EXISTS idx_track_plays_track ON track_plays(track_id);

COMMENT ON TABLE tracks IS 'Unique tracks seen on the radio stream (keyed by display string).';
COMMENT ON TABLE track_plays IS 'One row per track play (detected now-playing change), referencing tracks.';
SQL;
        $this->DB()->statement($sql);
    }

    public function down(): void
    {
        // Baselined migration: no automated rollback.
    }
}
