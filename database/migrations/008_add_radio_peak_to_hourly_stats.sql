-- Migration: Add radio listener peak tracking to hourly statistics
-- Purpose: Replaces radio_listeners with radio_listeners_avg and radio_listeners_peak
--          to match the structure of daily/weekly/monthly/yearly stats tables
-- Date: January 2026

-- Add new radio listener columns to hourly stats table
ALTER TABLE stats_hourly 
ADD COLUMN IF NOT EXISTS radio_listeners_avg INTEGER DEFAULT 0,
ADD COLUMN IF NOT EXISTS radio_listeners_peak INTEGER DEFAULT 0;

-- Migrate existing data from radio_listeners to radio_listeners_avg
UPDATE stats_hourly 
SET radio_listeners_avg = radio_listeners,
    radio_listeners_peak = radio_listeners
WHERE radio_listeners IS NOT NULL;

-- Remove old radio_listeners column (replaced by avg and peak)
ALTER TABLE stats_hourly 
DROP COLUMN IF EXISTS radio_listeners;

-- Add column comments
COMMENT ON COLUMN stats_hourly.radio_listeners_avg IS 'Average radio listeners during this hour';
COMMENT ON COLUMN stats_hourly.radio_listeners_peak IS 'Peak radio listeners during the hour';

-- Update the hourly stats aggregation function to calculate both avg and peak
CREATE OR REPLACE FUNCTION aggregate_hourly_stats(target_hour TIMESTAMP)
RETURNS void AS $$
DECLARE
    v_active_users INTEGER;
    v_guest_users INTEGER;
    v_registered_users INTEGER;
    v_total_messages INTEGER;
    v_private_messages INTEGER;
    v_photo_uploads INTEGER;
    v_new_registrations INTEGER;
    v_radio_listeners_avg INTEGER;
    v_radio_listeners_peak INTEGER;
    v_peak_concurrent INTEGER;
    v_hour_start TIMESTAMP;
    v_hour_end TIMESTAMP;
BEGIN
    v_hour_start := date_trunc('hour', target_hour);
    v_hour_end := v_hour_start + INTERVAL '1 hour';
    
    SELECT COUNT(DISTINCT username) INTO v_active_users
    FROM messages
    WHERE created_at >= v_hour_start AND created_at < v_hour_end AND is_deleted = FALSE;
    
    SELECT COUNT(DISTINCT m.username) INTO v_guest_users
    FROM messages m LEFT JOIN users u ON m.username = u.username
    WHERE m.created_at >= v_hour_start AND m.created_at < v_hour_end
      AND m.is_deleted = FALSE AND u.id IS NULL;
    
    v_registered_users := COALESCE(v_active_users, 0) - COALESCE(v_guest_users, 0);
    
    SELECT COUNT(*) INTO v_total_messages
    FROM messages
    WHERE created_at >= v_hour_start AND created_at < v_hour_end AND is_deleted = FALSE;
    
    SELECT COUNT(*) INTO v_private_messages
    FROM private_messages
    WHERE created_at >= v_hour_start AND created_at < v_hour_end;
    
    SELECT COUNT(*) INTO v_photo_uploads
    FROM attachments
    WHERE uploaded_at >= v_hour_start AND uploaded_at < v_hour_end AND is_deleted = FALSE;
    
    SELECT COUNT(*) INTO v_new_registrations
    FROM users
    WHERE created_at >= v_hour_start AND created_at < v_hour_end;
    
    SELECT COALESCE(AVG(radio_listeners)::INTEGER, 0) INTO v_radio_listeners_avg
    FROM stats_snapshots
    WHERE snapshot_time >= v_hour_start AND snapshot_time < v_hour_end;
    
    SELECT COALESCE(MAX(radio_listeners), 0) INTO v_radio_listeners_peak
    FROM stats_snapshots
    WHERE snapshot_time >= v_hour_start AND snapshot_time < v_hour_end;
    
    SELECT COALESCE(MAX(concurrent_users), 0) INTO v_peak_concurrent
    FROM stats_snapshots
    WHERE snapshot_time >= v_hour_start AND snapshot_time < v_hour_end;
    
    INSERT INTO stats_hourly (
        stat_hour, active_users, guest_users, registered_users,
        total_messages, private_messages, photo_uploads,
        new_registrations, radio_listeners_avg, radio_listeners_peak, peak_concurrent_users
    )
    VALUES (
        v_hour_start,
        COALESCE(v_active_users, 0),
        COALESCE(v_guest_users, 0),
        COALESCE(v_registered_users, 0),
        COALESCE(v_total_messages, 0),
        COALESCE(v_private_messages, 0),
        COALESCE(v_photo_uploads, 0),
        COALESCE(v_new_registrations, 0),
        COALESCE(v_radio_listeners_avg, 0),
        COALESCE(v_radio_listeners_peak, 0),
        COALESCE(v_peak_concurrent, 0)
    )
    ON CONFLICT (stat_hour) DO UPDATE SET
        active_users = EXCLUDED.active_users,
        guest_users = EXCLUDED.guest_users,
        registered_users = EXCLUDED.registered_users,
        total_messages = EXCLUDED.total_messages,
        private_messages = EXCLUDED.private_messages,
        photo_uploads = EXCLUDED.photo_uploads,
        new_registrations = EXCLUDED.new_registrations,
        radio_listeners_avg = EXCLUDED.radio_listeners_avg,
        radio_listeners_peak = EXCLUDED.radio_listeners_peak,
        peak_concurrent_users = EXCLUDED.peak_concurrent_users;
END;
$$ LANGUAGE plpgsql;
