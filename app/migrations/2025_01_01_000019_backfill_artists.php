<?php

namespace RadioChatBox\Migrations;

use Pramnos\Database\Migration;

/**
 * Migrated from database/migrations/022_backfill_artists.sql.
 *
 * PostgreSQL-specific SQL (plpgsql functions / triggers / guarded DO blocks /
 * data backfills) that the schema-builder DSL cannot express, kept verbatim and
 * self-contained. Idempotent, so the tracked runner records it as applied on an
 * existing database and it builds the schema on a fresh one.
 */
final class BackfillArtists extends Migration
{
    public $description = 'Migrated: 022_backfill_artists.sql';

    // The SQL manages its own transactions; do not double-wrap.
    public bool $transactional = false;

    public function up(): void
    {
        $sql = <<<'SQL'
-- Migration 022: Backfill the artists table from existing tracks and link them
-- Description: Tracks recorded before artist-linking existed have tracks.artist
--              (a string) but no artists row and no artist_id. That made them
--              appear in Top Artists (which groups by tracks.artist) but not in
--              the A–Z index (which reads the artists table), and they could
--              never get an image. Create the missing artist rows and link them.
-- Date: 2026-07-24
-- Idempotent.

INSERT INTO artists (name)
SELECT DISTINCT t.artist
FROM tracks t
WHERE t.artist IS NOT NULL AND t.artist <> ''
ON CONFLICT (name) DO NOTHING;

UPDATE tracks t
SET artist_id = ar.id
FROM artists ar
WHERE t.artist_id IS NULL AND t.artist = ar.name;
SQL;
        $this->DB()->statement($sql);
    }

    public function down(): void
    {
        // Baselined migration: no automated rollback.
    }
}
