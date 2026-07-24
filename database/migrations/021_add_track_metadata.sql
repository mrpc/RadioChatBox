-- Migration 021: Rich metadata for tracks, plus artists and albums tables
-- Description: Normalizes artists and albums into their own tables, links tracks
--              to them, and adds metadata columns (genre, cover file, release
--              date, external ids, free-form meta). Adds an exclude-from-stats
--              flag on tracks and artists (e.g. to hide jingles) — excluded
--              items still appear in the play log, just not in the stats.
-- Date: 2026-07-24
-- Idempotent + self-healing (safe to re-run).

-- ---- artists -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS artists (
    id SERIAL PRIMARY KEY,
    name VARCHAR(300) NOT NULL,
    source VARCHAR(20),
    external_id VARCHAR(100),
    external_url VARCHAR(1000),
    genre VARCHAR(200),
    image_file VARCHAR(500),
    meta JSONB,
    excluded_from_stats BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);
ALTER TABLE artists ADD COLUMN IF NOT EXISTS source VARCHAR(20);
ALTER TABLE artists ADD COLUMN IF NOT EXISTS external_id VARCHAR(100);
ALTER TABLE artists ADD COLUMN IF NOT EXISTS external_url VARCHAR(1000);
ALTER TABLE artists ADD COLUMN IF NOT EXISTS genre VARCHAR(200);
ALTER TABLE artists ADD COLUMN IF NOT EXISTS image_file VARCHAR(500);
ALTER TABLE artists ADD COLUMN IF NOT EXISTS meta JSONB;
ALTER TABLE artists ADD COLUMN IF NOT EXISTS excluded_from_stats BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE artists ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE artists ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP;

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'uq_artists_name') THEN
        ALTER TABLE artists ADD CONSTRAINT uq_artists_name UNIQUE (name);
    END IF;
END$$;

-- ---- albums --------------------------------------------------------------
CREATE TABLE IF NOT EXISTS albums (
    id SERIAL PRIMARY KEY,
    title VARCHAR(400) NOT NULL,
    artist_id INTEGER REFERENCES artists(id) ON DELETE SET NULL,
    source VARCHAR(20),
    external_id VARCHAR(100),
    external_url VARCHAR(1000),
    cover_file VARCHAR(500),
    release_date DATE,
    genre VARCHAR(200),
    meta JSONB,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);
ALTER TABLE albums ADD COLUMN IF NOT EXISTS artist_id INTEGER REFERENCES artists(id) ON DELETE SET NULL;
ALTER TABLE albums ADD COLUMN IF NOT EXISTS source VARCHAR(20);
ALTER TABLE albums ADD COLUMN IF NOT EXISTS external_id VARCHAR(100);
ALTER TABLE albums ADD COLUMN IF NOT EXISTS external_url VARCHAR(1000);
ALTER TABLE albums ADD COLUMN IF NOT EXISTS cover_file VARCHAR(500);
ALTER TABLE albums ADD COLUMN IF NOT EXISTS release_date DATE;
ALTER TABLE albums ADD COLUMN IF NOT EXISTS genre VARCHAR(200);
ALTER TABLE albums ADD COLUMN IF NOT EXISTS meta JSONB;
ALTER TABLE albums ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE albums ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP;

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'uq_albums_title_artist') THEN
        ALTER TABLE albums ADD CONSTRAINT uq_albums_title_artist UNIQUE (title, artist_id);
    END IF;
END$$;

CREATE INDEX IF NOT EXISTS idx_albums_artist ON albums(artist_id);

-- ---- tracks metadata -----------------------------------------------------
ALTER TABLE tracks ADD COLUMN IF NOT EXISTS artist_id INTEGER REFERENCES artists(id) ON DELETE SET NULL;
ALTER TABLE tracks ADD COLUMN IF NOT EXISTS album_id INTEGER REFERENCES albums(id) ON DELETE SET NULL;
ALTER TABLE tracks ADD COLUMN IF NOT EXISTS genre VARCHAR(200);
ALTER TABLE tracks ADD COLUMN IF NOT EXISTS cover_file VARCHAR(500);
ALTER TABLE tracks ADD COLUMN IF NOT EXISTS release_date DATE;
ALTER TABLE tracks ADD COLUMN IF NOT EXISTS source VARCHAR(20);
ALTER TABLE tracks ADD COLUMN IF NOT EXISTS external_id VARCHAR(100);
ALTER TABLE tracks ADD COLUMN IF NOT EXISTS external_url VARCHAR(1000);
ALTER TABLE tracks ADD COLUMN IF NOT EXISTS meta JSONB;
ALTER TABLE tracks ADD COLUMN IF NOT EXISTS excluded_from_stats BOOLEAN NOT NULL DEFAULT FALSE;
-- When enrichment (external metadata lookup) last ran; NULL = not yet enriched.
ALTER TABLE tracks ADD COLUMN IF NOT EXISTS enriched_at TIMESTAMPTZ;

CREATE INDEX IF NOT EXISTS idx_tracks_artist ON tracks(artist_id);
CREATE INDEX IF NOT EXISTS idx_tracks_album ON tracks(album_id);
CREATE INDEX IF NOT EXISTS idx_tracks_genre ON tracks(genre);
CREATE INDEX IF NOT EXISTS idx_tracks_enriched ON tracks(enriched_at);

COMMENT ON TABLE artists IS 'Unique artists with metadata; excluded_from_stats hides them from stats (still logged).';
COMMENT ON TABLE albums IS 'Albums with metadata, linked to an artist.';
COMMENT ON COLUMN tracks.excluded_from_stats IS 'When true, the track is hidden from stats (e.g. jingles) but still appears in the play log.';
COMMENT ON COLUMN tracks.enriched_at IS 'Timestamp of last external metadata enrichment; NULL means pending.';
