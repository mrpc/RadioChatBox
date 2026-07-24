-- Migration 019: Add tracks + track_plays tables
-- Description: Normalized radio play history. `tracks` holds each unique track
--              once (keyed by its display string); `track_plays` records every
--              time it played (one row per detected track change). This avoids
--              repeating the title/artist on every play and makes popularity
--              stats and per-track lookups clean.
-- Date: 2026-07-24

CREATE TABLE IF NOT EXISTS tracks (
    id SERIAL PRIMARY KEY,
    artist VARCHAR(300),
    title VARCHAR(300),
    -- Normalized "Artist - Title" string as shown by the stream. Unique key.
    display VARCHAR(500) NOT NULL,
    first_played_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_played_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- All-time convenience counter (period stats use track_plays instead).
    play_count INTEGER NOT NULL DEFAULT 0,
    CONSTRAINT uq_tracks_display UNIQUE (display)
);

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
