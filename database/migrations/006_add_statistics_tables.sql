-- Statistics Tables Migration
-- Tracks hourly, daily, weekly, monthly, and yearly statistics
-- 
-- Metrics tracked:
-- - Active chatting users (authenticated + anonymous)
-- - Guest users (anonymous only)
-- - Total messages sent (public chat)
-- - Private messages sent
-- - Radio listeners (Shoutcast/Icecast)
-- - Photo uploads
-- - New user registrations
-- - Peak concurrent users

-- ============================================================================
-- HOURLY STATISTICS
-- ============================================================================

CREATE TABLE IF NOT EXISTS stats_hourly (
    id SERIAL PRIMARY KEY,
    stat_hour TIMESTAMP NOT NULL,
    active_users INTEGER DEFAULT 0,
    guest_users INTEGER DEFAULT 0,
    registered_users INTEGER DEFAULT 0,
    total_messages INTEGER DEFAULT 0,
    private_messages INTEGER DEFAULT 0,
    photo_uploads INTEGER DEFAULT 0,
    new_registrations INTEGER DEFAULT 0,
    radio_listeners INTEGER DEFAULT 0,
    peak_concurrent_users INTEGER DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(stat_hour)
);

CREATE INDEX idx_stats_hourly_stat_hour ON stats_hourly(stat_hour DESC);

COMMENT ON TABLE stats_hourly IS 'Hourly aggregated statistics - one row per hour';
COMMENT ON COLUMN stats_hourly.stat_hour IS 'Start of the hour (e.g., 2024-01-15 14:00:00)';
COMMENT ON COLUMN stats_hourly.active_users IS 'Total unique users who sent messages during this hour';
COMMENT ON COLUMN stats_hourly.guest_users IS 'Unique anonymous users who sent messages';
COMMENT ON COLUMN stats_hourly.registered_users IS 'Unique authenticated users who sent messages';
COMMENT ON COLUMN stats_hourly.total_messages IS 'Count of public chat messages sent';
COMMENT ON COLUMN stats_hourly.private_messages IS 'Count of private messages sent';
COMMENT ON COLUMN stats_hourly.photo_uploads IS 'Count of photos uploaded';
COMMENT ON COLUMN stats_hourly.new_registrations IS 'Count of new user accounts created';
COMMENT ON COLUMN stats_hourly.radio_listeners IS 'Average radio listeners during this hour';
COMMENT ON COLUMN stats_hourly.peak_concurrent_users IS 'Maximum concurrent users online at any moment';

-- ============================================================================
-- DAILY STATISTICS
-- ============================================================================

CREATE TABLE IF NOT EXISTS stats_daily (
    id SERIAL PRIMARY KEY,
    stat_date DATE NOT NULL,
    active_users INTEGER DEFAULT 0,
    guest_users INTEGER DEFAULT 0,
    registered_users INTEGER DEFAULT 0,
    total_messages INTEGER DEFAULT 0,
    private_messages INTEGER DEFAULT 0,
    photo_uploads INTEGER DEFAULT 0,
    new_registrations INTEGER DEFAULT 0,
    radio_listeners_avg INTEGER DEFAULT 0,
    radio_listeners_peak INTEGER DEFAULT 0,
    peak_concurrent_users INTEGER DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(stat_date)
);

CREATE INDEX idx_stats_daily_stat_date ON stats_daily(stat_date DESC);

COMMENT ON TABLE stats_daily IS 'Daily aggregated statistics - one row per day';
COMMENT ON COLUMN stats_daily.radio_listeners_avg IS 'Average radio listeners throughout the day';
COMMENT ON COLUMN stats_daily.radio_listeners_peak IS 'Peak radio listeners during the day';

-- ============================================================================
-- WEEKLY STATISTICS
-- ============================================================================

CREATE TABLE IF NOT EXISTS stats_weekly (
    id SERIAL PRIMARY KEY,
    stat_year INTEGER NOT NULL,
    stat_week INTEGER NOT NULL,
    week_start_date DATE NOT NULL,
    active_users INTEGER DEFAULT 0,
    guest_users INTEGER DEFAULT 0,
    registered_users INTEGER DEFAULT 0,
    total_messages INTEGER DEFAULT 0,
    private_messages INTEGER DEFAULT 0,
    photo_uploads INTEGER DEFAULT 0,
    new_registrations INTEGER DEFAULT 0,
    radio_listeners_avg INTEGER DEFAULT 0,
    radio_listeners_peak INTEGER DEFAULT 0,
    peak_concurrent_users INTEGER DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(stat_year, stat_week)
);

CREATE INDEX idx_stats_weekly_year_week ON stats_weekly(stat_year DESC, stat_week DESC);
CREATE INDEX idx_stats_weekly_week_start ON stats_weekly(week_start_date DESC);

COMMENT ON TABLE stats_weekly IS 'Weekly aggregated statistics - one row per week (ISO week numbering)';
COMMENT ON COLUMN stats_weekly.stat_week IS 'ISO week number (1-53)';
COMMENT ON COLUMN stats_weekly.week_start_date IS 'Monday of the week';

-- ============================================================================
-- MONTHLY STATISTICS
-- ============================================================================

CREATE TABLE IF NOT EXISTS stats_monthly (
    id SERIAL PRIMARY KEY,
    stat_year INTEGER NOT NULL,
    stat_month INTEGER NOT NULL,
    active_users INTEGER DEFAULT 0,
    guest_users INTEGER DEFAULT 0,
    registered_users INTEGER DEFAULT 0,
    total_messages INTEGER DEFAULT 0,
    private_messages INTEGER DEFAULT 0,
    photo_uploads INTEGER DEFAULT 0,
    new_registrations INTEGER DEFAULT 0,
    radio_listeners_avg INTEGER DEFAULT 0,
    radio_listeners_peak INTEGER DEFAULT 0,
    peak_concurrent_users INTEGER DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(stat_year, stat_month)
);

CREATE INDEX idx_stats_monthly_year_month ON stats_monthly(stat_year DESC, stat_month DESC);

COMMENT ON TABLE stats_monthly IS 'Monthly aggregated statistics - one row per month';

-- ============================================================================
-- YEARLY STATISTICS
-- ============================================================================

CREATE TABLE IF NOT EXISTS stats_yearly (
    id SERIAL PRIMARY KEY,
    stat_year INTEGER NOT NULL UNIQUE,
    active_users INTEGER DEFAULT 0,
    guest_users INTEGER DEFAULT 0,
    registered_users INTEGER DEFAULT 0,
    total_messages INTEGER DEFAULT 0,
    private_messages INTEGER DEFAULT 0,
    photo_uploads INTEGER DEFAULT 0,
    new_registrations INTEGER DEFAULT 0,
    radio_listeners_avg INTEGER DEFAULT 0,
    radio_listeners_peak INTEGER DEFAULT 0,
    peak_concurrent_users INTEGER DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_stats_yearly_year ON stats_yearly(stat_year DESC);

COMMENT ON TABLE stats_yearly IS 'Yearly aggregated statistics - one row per year';

-- ============================================================================
-- REAL-TIME SNAPSHOTS (for tracking radio listeners over time)
-- ============================================================================

CREATE TABLE IF NOT EXISTS stats_snapshots (
    id SERIAL PRIMARY KEY,
    snapshot_time TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    concurrent_users INTEGER DEFAULT 0,
    radio_listeners INTEGER DEFAULT 0,
    active_sessions INTEGER DEFAULT 0
);

CREATE INDEX idx_stats_snapshots_time ON stats_snapshots(snapshot_time DESC);

COMMENT ON TABLE stats_snapshots IS 'Real-time snapshots taken every 5-15 minutes for calculating averages and peaks. Note: concurrent_users and radio_listeners are SEPARATE services - someone can listen to radio without being in chat, and vice versa.';
COMMENT ON COLUMN stats_snapshots.concurrent_users IS 'Number of CHAT users in sessions table at snapshot time (excludes radio-only listeners)';
COMMENT ON COLUMN stats_snapshots.radio_listeners IS 'Number of radio stream listeners from Shoutcast/Icecast API (excludes chat-only users)';
COMMENT ON COLUMN stats_snapshots.active_sessions IS 'Total active chat sessions (includes duplicates from multiple tabs)';

-- ============================================================================
-- HELPER FUNCTIONS
-- ============================================================================

-- Function to aggregate hourly stats from snapshots and raw data
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
    v_radio_listeners INTEGER;
    v_peak_concurrent INTEGER;
    v_hour_start TIMESTAMP;
    v_hour_end TIMESTAMP;
BEGIN
    -- Normalize to hour start
    v_hour_start := date_trunc('hour', target_hour);
    v_hour_end := v_hour_start + INTERVAL '1 hour';
    
    -- Count unique active users (from messages)
    SELECT COUNT(DISTINCT username)
    INTO v_active_users
    FROM messages
    WHERE created_at >= v_hour_start AND created_at < v_hour_end
      AND is_deleted = FALSE;
    
    -- Count guest users (users not in users table)
    SELECT COUNT(DISTINCT m.username)
    INTO v_guest_users
    FROM messages m
    LEFT JOIN users u ON m.username = u.username
    WHERE m.created_at >= v_hour_start AND m.created_at < v_hour_end
      AND m.is_deleted = FALSE
      AND u.id IS NULL;
    
    -- Registered users = active - guests
    v_registered_users := COALESCE(v_active_users, 0) - COALESCE(v_guest_users, 0);
    
    -- Count messages
    SELECT COUNT(*)
    INTO v_total_messages
    FROM messages
    WHERE created_at >= v_hour_start AND created_at < v_hour_end
      AND is_deleted = FALSE;
    
    -- Count private messages
    SELECT COUNT(*)
    INTO v_private_messages
    FROM private_messages
    WHERE created_at >= v_hour_start AND created_at < v_hour_end;
    
    -- Count photo uploads
    SELECT COUNT(*)
    INTO v_photo_uploads
    FROM attachments
    WHERE uploaded_at >= v_hour_start AND uploaded_at < v_hour_end
      AND is_deleted = FALSE;
    
    -- Count new registrations (if users table has created_at)
    SELECT COUNT(*)
    INTO v_new_registrations
    FROM users
    WHERE created_at >= v_hour_start AND created_at < v_hour_end;
    
    -- Average radio listeners from snapshots
    SELECT COALESCE(AVG(radio_listeners)::INTEGER, 0)
    INTO v_radio_listeners
    FROM stats_snapshots
    WHERE snapshot_time >= v_hour_start AND snapshot_time < v_hour_end;
    
    -- Peak concurrent users from snapshots
    SELECT COALESCE(MAX(concurrent_users), 0)
    INTO v_peak_concurrent
    FROM stats_snapshots
    WHERE snapshot_time >= v_hour_start AND snapshot_time < v_hour_end;
    
    -- Insert or update hourly stats
    INSERT INTO stats_hourly (
        stat_hour, active_users, guest_users, registered_users,
        total_messages, private_messages, photo_uploads,
        new_registrations, radio_listeners, peak_concurrent_users
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
        COALESCE(v_radio_listeners, 0),
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
        radio_listeners = EXCLUDED.radio_listeners,
        peak_concurrent_users = EXCLUDED.peak_concurrent_users;
END;
$$ LANGUAGE plpgsql;

COMMENT ON FUNCTION aggregate_hourly_stats(TIMESTAMP) IS 'Aggregate statistics for a specific hour from raw data and snapshots';

-- Function to aggregate daily stats from hourly stats
CREATE OR REPLACE FUNCTION aggregate_daily_stats(target_date DATE)
RETURNS void AS $$
DECLARE
    v_active_users INTEGER;
    v_guest_users INTEGER;
    v_registered_users INTEGER;
    v_total_messages INTEGER;
    v_private_messages INTEGER;
    v_photo_uploads INTEGER;
    v_new_registrations INTEGER;
    v_radio_avg INTEGER;
    v_radio_peak INTEGER;
    v_peak_concurrent INTEGER;
    v_day_start TIMESTAMP;
    v_day_end TIMESTAMP;
BEGIN
    v_day_start := target_date::TIMESTAMP;
    v_day_end := v_day_start + INTERVAL '1 day';
    
    -- Sum from hourly stats (more efficient than raw data)
    SELECT
        MAX(active_users),
        MAX(guest_users),
        MAX(registered_users),
        SUM(total_messages),
        SUM(private_messages),
        SUM(photo_uploads),
        SUM(new_registrations),
        AVG(radio_listeners_avg)::INTEGER,
        MAX(radio_listeners_peak),
        MAX(peak_concurrent_users)
    INTO
        v_active_users, v_guest_users, v_registered_users,
        v_total_messages, v_private_messages, v_photo_uploads,
        v_new_registrations, v_radio_avg, v_radio_peak, v_peak_concurrent
    FROM stats_hourly
    WHERE stat_hour >= v_day_start AND stat_hour < v_day_end;
    
    INSERT INTO stats_daily (
        stat_date, active_users, guest_users, registered_users,
        total_messages, private_messages, photo_uploads,
        new_registrations, radio_listeners_avg, radio_listeners_peak,
        peak_concurrent_users
    )
    VALUES (
        target_date,
        COALESCE(v_active_users, 0),
        COALESCE(v_guest_users, 0),
        COALESCE(v_registered_users, 0),
        COALESCE(v_total_messages, 0),
        COALESCE(v_private_messages, 0),
        COALESCE(v_photo_uploads, 0),
        COALESCE(v_new_registrations, 0),
        COALESCE(v_radio_avg, 0),
        COALESCE(v_radio_peak, 0),
        COALESCE(v_peak_concurrent, 0)
    )
    ON CONFLICT (stat_date) DO UPDATE SET
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

-- Similar functions for weekly, monthly, yearly
CREATE OR REPLACE FUNCTION aggregate_weekly_stats(target_date DATE)
RETURNS void AS $$
DECLARE
    v_year INTEGER;
    v_week INTEGER;
    v_week_start DATE;
    v_week_end DATE;
    v_active_users INTEGER;
    v_guest_users INTEGER;
    v_registered_users INTEGER;
    v_total_messages INTEGER;
    v_private_messages INTEGER;
    v_photo_uploads INTEGER;
    v_new_registrations INTEGER;
    v_radio_avg INTEGER;
    v_radio_peak INTEGER;
    v_peak_concurrent INTEGER;
BEGIN
    -- Get ISO year and week
    v_year := EXTRACT(ISOYEAR FROM target_date);
    v_week := EXTRACT(WEEK FROM target_date);
    v_week_start := date_trunc('week', target_date)::DATE;
    v_week_end := v_week_start + INTERVAL '1 week';
    
    SELECT
        MAX(active_users),
        MAX(guest_users),
        MAX(registered_users),
        SUM(total_messages),
        SUM(private_messages),
        SUM(photo_uploads),
        SUM(new_registrations),
        AVG(radio_listeners_avg)::INTEGER,
        MAX(radio_listeners_peak),
        MAX(peak_concurrent_users)
    INTO
        v_active_users, v_guest_users, v_registered_users,
        v_total_messages, v_private_messages, v_photo_uploads,
        v_new_registrations, v_radio_avg, v_radio_peak, v_peak_concurrent
    FROM stats_daily
    WHERE stat_date >= v_week_start AND stat_date < v_week_end::DATE;
    
    INSERT INTO stats_weekly (
        stat_year, stat_week, week_start_date,
        active_users, guest_users, registered_users,
        total_messages, private_messages, photo_uploads,
        new_registrations, radio_listeners_avg, radio_listeners_peak,
        peak_concurrent_users
    )
    VALUES (
        v_year, v_week, v_week_start,
        COALESCE(v_active_users, 0),
        COALESCE(v_guest_users, 0),
        COALESCE(v_registered_users, 0),
        COALESCE(v_total_messages, 0),
        COALESCE(v_private_messages, 0),
        COALESCE(v_photo_uploads, 0),
        COALESCE(v_new_registrations, 0),
        COALESCE(v_radio_avg, 0),
        COALESCE(v_radio_peak, 0),
        COALESCE(v_peak_concurrent, 0)
    )
    ON CONFLICT (stat_year, stat_week) DO UPDATE SET
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

CREATE OR REPLACE FUNCTION aggregate_monthly_stats(target_date DATE)
RETURNS void AS $$
DECLARE
    v_year INTEGER;
    v_month INTEGER;
    v_month_start DATE;
    v_month_end DATE;
    v_active_users INTEGER;
    v_guest_users INTEGER;
    v_registered_users INTEGER;
    v_total_messages INTEGER;
    v_private_messages INTEGER;
    v_photo_uploads INTEGER;
    v_new_registrations INTEGER;
    v_radio_avg INTEGER;
    v_radio_peak INTEGER;
    v_peak_concurrent INTEGER;
BEGIN
    v_year := EXTRACT(YEAR FROM target_date);
    v_month := EXTRACT(MONTH FROM target_date);
    v_month_start := date_trunc('month', target_date)::DATE;
    v_month_end := (v_month_start + INTERVAL '1 month')::DATE;
    
    SELECT
        MAX(active_users),
        MAX(guest_users),
        MAX(registered_users),
        SUM(total_messages),
        SUM(private_messages),
        SUM(photo_uploads),
        SUM(new_registrations),
        AVG(radio_listeners_avg)::INTEGER,
        MAX(radio_listeners_peak),
        MAX(peak_concurrent_users)
    INTO
        v_active_users, v_guest_users, v_registered_users,
        v_total_messages, v_private_messages, v_photo_uploads,
        v_new_registrations, v_radio_avg, v_radio_peak, v_peak_concurrent
    FROM stats_daily
    WHERE stat_date >= v_month_start AND stat_date < v_month_end;
    
    INSERT INTO stats_monthly (
        stat_year, stat_month,
        active_users, guest_users, registered_users,
        total_messages, private_messages, photo_uploads,
        new_registrations, radio_listeners_avg, radio_listeners_peak,
        peak_concurrent_users
    )
    VALUES (
        v_year, v_month,
        COALESCE(v_active_users, 0),
        COALESCE(v_guest_users, 0),
        COALESCE(v_registered_users, 0),
        COALESCE(v_total_messages, 0),
        COALESCE(v_private_messages, 0),
        COALESCE(v_photo_uploads, 0),
        COALESCE(v_new_registrations, 0),
        COALESCE(v_radio_avg, 0),
        COALESCE(v_radio_peak, 0),
        COALESCE(v_peak_concurrent, 0)
    )
    ON CONFLICT (stat_year, stat_month) DO UPDATE SET
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

CREATE OR REPLACE FUNCTION aggregate_yearly_stats(target_year INTEGER)
RETURNS void AS $$
DECLARE
    v_year_start DATE;
    v_year_end DATE;
    v_active_users INTEGER;
    v_guest_users INTEGER;
    v_registered_users INTEGER;
    v_total_messages INTEGER;
    v_private_messages INTEGER;
    v_photo_uploads INTEGER;
    v_new_registrations INTEGER;
    v_radio_avg INTEGER;
    v_radio_peak INTEGER;
    v_peak_concurrent INTEGER;
BEGIN
    v_year_start := (target_year || '-01-01')::DATE;
    v_year_end := (target_year + 1 || '-01-01')::DATE;
    
    SELECT
        MAX(active_users),
        MAX(guest_users),
        MAX(registered_users),
        SUM(total_messages),
        SUM(private_messages),
        SUM(photo_uploads),
        SUM(new_registrations),
        AVG(radio_listeners_avg)::INTEGER,
        MAX(radio_listeners_peak),
        MAX(peak_concurrent_users)
    INTO
        v_active_users, v_guest_users, v_registered_users,
        v_total_messages, v_private_messages, v_photo_uploads,
        v_new_registrations, v_radio_avg, v_radio_peak, v_peak_concurrent
    FROM stats_daily
    WHERE stat_date >= v_year_start AND stat_date < v_year_end;
    
    INSERT INTO stats_yearly (
        stat_year,
        active_users, guest_users, registered_users,
        total_messages, private_messages, photo_uploads,
        new_registrations, radio_listeners_avg, radio_listeners_peak,
        peak_concurrent_users
    )
    VALUES (
        target_year,
        COALESCE(v_active_users, 0),
        COALESCE(v_guest_users, 0),
        COALESCE(v_registered_users, 0),
        COALESCE(v_total_messages, 0),
        COALESCE(v_private_messages, 0),
        COALESCE(v_photo_uploads, 0),
        COALESCE(v_new_registrations, 0),
        COALESCE(v_radio_avg, 0),
        COALESCE(v_radio_peak, 0),
        COALESCE(v_peak_concurrent, 0)
    )
    ON CONFLICT (stat_year) DO UPDATE SET
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

-- Cleanup old snapshots (keep only last 30 days)
CREATE OR REPLACE FUNCTION cleanup_old_snapshots()
RETURNS void AS $$
BEGIN
    DELETE FROM stats_snapshots
    WHERE snapshot_time < NOW() - INTERVAL '30 days';
END;
$$ LANGUAGE plpgsql;

COMMENT ON FUNCTION cleanup_old_snapshots() IS 'Remove snapshots older than 30 days to keep table size manageable';
