-- Migration 024: Periodic tasks run by the bot worker instead of crontab
--
-- The worker already has what scheduled work needs and cron does not: a
-- single-instance lock with a heartbeat (so a slow run cannot overlap itself),
-- graceful shutdown, structured logging and a health view. This table is the only
-- state it needs - when each task last ran and how it went - so a restart does not
-- re-run everything and the admin panel can show what is happening.
--
-- Opt-in: the worker only runs them with `run --schedule`, so an existing crontab
-- keeps working until it is removed by hand. Running both is harmless: a task will
-- not run twice inside its own interval.
--
-- Safe to run multiple times.

CREATE TABLE IF NOT EXISTS scheduled_tasks (
    task VARCHAR(50) PRIMARY KEY,
    last_run_at TIMESTAMPTZ,
    last_status VARCHAR(20),
    last_duration_ms INTEGER,
    last_error TEXT,
    runs BIGINT NOT NULL DEFAULT 0,
    failures BIGINT NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_scheduled_tasks_last_run ON scheduled_tasks(last_run_at DESC);

COMMENT ON TABLE scheduled_tasks IS 'Last run and outcome of each periodic task the bot worker runs (see src/Scheduler.php)';
COMMENT ON COLUMN scheduled_tasks.task IS 'Task name from Scheduler::TASKS';
COMMENT ON COLUMN scheduled_tasks.last_status IS 'ok or failed - a failed task is retried on the next interval, it does not stop the worker';

-- ============================================================================
-- SETTINGS
-- ============================================================================
-- How often the worker reads the stream. The current track has to be caught while
-- it is playing: bundled with the five-minute stats snapshot, a three-minute song
-- was missed entirely. Recording de-duplicates atomically, so a short interval
-- writes nothing until the track actually changes.

INSERT INTO settings (setting_key, setting_value) VALUES
    ('track_poll_seconds', '30')
ON CONFLICT (setting_key) DO NOTHING;
