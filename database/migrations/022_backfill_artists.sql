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
