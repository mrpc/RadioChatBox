<?php

namespace RadioChatBox\Migrations;

use Pramnos\Database\Migration;

/**
 * "Active Users: 0" while the chat is busy.
 *
 * Every user-count in the statistics (active / guest / registered, at every
 * granularity) was derived from the PUBLIC messages table alone. On an install
 * whose traffic is direct messages — 6k DMs a day, no public messages — that
 * counts nobody: the hourly rollup wrote 0, the daily/weekly/monthly rollups
 * take MAX() of the level below, so the zero propagated everywhere and the
 * dashboard's Active Users line sat flat on the axis next to a Private Messages
 * curve in the thousands.
 *
 * The fix is one shared source of truth — `active_user_counts(start, end)` —
 * used by all five aggregation functions AND by the live dashboard summary, so
 * a period never disagrees with itself:
 *
 *   active     distinct people who SENT something in the window, public chat
 *              messages and DMs alike (a DM recipient did not act, and bots —
 *              the `fake_users` nicknames — are not visitors, so both are out)
 *   guest      those with no `users` row
 *   registered active - guest
 *
 * Because active users are people, not events, the higher granularities can no
 * longer be MAX()ed out of the level below (a week's distinct users is not its
 * busiest day's); each level now counts distinct over its own window. Message /
 * upload / registration totals keep summing the level below as before.
 *
 * Historical rows are wrong for the same reason, so the migration re-runs every
 * aggregation over the existing data. Idempotent — safe to re-run.
 */
final class StatsDmActiveUsers extends Migration
{
    public $description = 'Stats: count DM senders as active users (+ backfill)';
    public bool $transactional = true;
    public array $dependencies = ['create_schema'];

    public function up(): void
    {
        $db = $this->DB();

        // The aggregations and the live summary both scan private_messages by
        // created_at alone; the existing indexes all lead with from/to_username,
        // which a pure time-range scan cannot use. IF NOT EXISTS rather than a
        // pg_indexes probe: migrations auto-run per request, so two of them can
        // reach this line at once and a probe-then-create would lose that race.
        $db->statement(
            'CREATE INDEX IF NOT EXISTS idx_private_messages_created_at
             ON private_messages (created_at DESC);'
        );

        $db->statement($this->activeUserCountsSql());
        $db->statement($this->aggregateHourlyStatsSql('timestamp without time zone'));
        $db->statement($this->aggregateHourlyStatsSql('timestamp with time zone'));
        $db->statement($this->aggregateDailyStatsSql());
        $db->statement($this->aggregateWeeklyStatsSql());
        $db->statement($this->aggregateMonthlyStatsSql());
        $db->statement($this->aggregateYearlyStatsSql());

        $this->backfill();
    }

    public function down(): void
    {
        // Intentionally not reverted: rolling back would restore the counts that
        // report every DM-only day as "0 users". The function is additive and
        // the aggregations are CREATE OR REPLACE — re-running create_schema
        // restores the previous bodies if that is ever really wanted.
    }

    /**
     * Distinct senders in [start, end), split into guests / registered.
     * Bots (fake_users nicknames) are excluded — they are content, not audience.
     */
    private function activeUserCountsSql(): string
    {
        return <<<'SQL'
CREATE OR REPLACE FUNCTION public.active_user_counts(
    p_start timestamp with time zone,
    p_end timestamp with time zone
)
RETURNS TABLE(active_users integer, guest_users integer, registered_users integer)
LANGUAGE sql
STABLE
AS $function$
    WITH participants AS (
        SELECT DISTINCT p.username
        FROM (
            SELECT username
              FROM chat_messages
             WHERE created_at >= p_start AND created_at < p_end
               AND is_deleted = FALSE
            UNION ALL
            SELECT from_username AS username
              FROM private_messages
             WHERE created_at >= p_start AND created_at < p_end
        ) p
        WHERE p.username IS NOT NULL
          AND p.username <> ''
          AND NOT EXISTS (
              SELECT 1 FROM fake_users f WHERE lower(f.nickname) = lower(p.username)
          )
    )
    SELECT
        COUNT(*)::integer,
        COUNT(*) FILTER (
            WHERE NOT EXISTS (
                SELECT 1 FROM users u WHERE u.username = participants.username
            )
        )::integer,
        COUNT(*) FILTER (
            WHERE EXISTS (
                SELECT 1 FROM users u WHERE u.username = participants.username
            )
        )::integer
    FROM participants;
$function$
SQL;
    }

    /** Both overloads exist (timestamp / timestamptz); keep them in step. */
    private function aggregateHourlyStatsSql(string $tsType): string
    {
        return <<<SQL
CREATE OR REPLACE FUNCTION public.aggregate_hourly_stats(target_hour {$tsType})
 RETURNS void
 LANGUAGE plpgsql
AS \$function\$
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
    v_hour_start {$tsType};
    v_hour_end {$tsType};
BEGIN
    v_hour_start := date_trunc('hour', target_hour);
    v_hour_end := v_hour_start + INTERVAL '1 hour';

    SELECT c.active_users, c.guest_users, c.registered_users
      INTO v_active_users, v_guest_users, v_registered_users
      FROM active_user_counts(v_hour_start, v_hour_end) c;

    SELECT COUNT(*) INTO v_total_messages
    FROM chat_messages
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
\$function\$
SQL;
    }

    private function aggregateDailyStatsSql(): string
    {
        return <<<'SQL'
CREATE OR REPLACE FUNCTION public.aggregate_daily_stats(target_date date)
 RETURNS void
 LANGUAGE plpgsql
AS $function$
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
    v_day_start TIMESTAMPTZ;
    v_day_end TIMESTAMPTZ;
BEGIN
    v_day_start := target_date::TIMESTAMPTZ;
    v_day_end := v_day_start + INTERVAL '1 day';

    -- Distinct people over the whole day (not the busiest hour's count).
    SELECT c.active_users, c.guest_users, c.registered_users
      INTO v_active_users, v_guest_users, v_registered_users
      FROM active_user_counts(v_day_start, v_day_end) c;

    -- Volume metrics still roll up from the hourly rows.
    SELECT
        SUM(total_messages),
        SUM(private_messages),
        SUM(photo_uploads),
        SUM(new_registrations),
        AVG(radio_listeners_avg)::INTEGER,
        MAX(radio_listeners_peak),
        MAX(peak_concurrent_users)
    INTO
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
$function$
SQL;
    }

    private function aggregateWeeklyStatsSql(): string
    {
        return <<<'SQL'
CREATE OR REPLACE FUNCTION public.aggregate_weekly_stats(target_date date)
 RETURNS void
 LANGUAGE plpgsql
AS $function$
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
    v_week_end := (v_week_start + INTERVAL '1 week')::DATE;

    SELECT c.active_users, c.guest_users, c.registered_users
      INTO v_active_users, v_guest_users, v_registered_users
      FROM active_user_counts(v_week_start::TIMESTAMPTZ, v_week_end::TIMESTAMPTZ) c;

    SELECT
        SUM(total_messages),
        SUM(private_messages),
        SUM(photo_uploads),
        SUM(new_registrations),
        AVG(radio_listeners_avg)::INTEGER,
        MAX(radio_listeners_peak),
        MAX(peak_concurrent_users)
    INTO
        v_total_messages, v_private_messages, v_photo_uploads,
        v_new_registrations, v_radio_avg, v_radio_peak, v_peak_concurrent
    FROM stats_daily
    WHERE stat_date >= v_week_start AND stat_date < v_week_end;

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
$function$
SQL;
    }

    private function aggregateMonthlyStatsSql(): string
    {
        return <<<'SQL'
CREATE OR REPLACE FUNCTION public.aggregate_monthly_stats(target_date date)
 RETURNS void
 LANGUAGE plpgsql
AS $function$
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

    SELECT c.active_users, c.guest_users, c.registered_users
      INTO v_active_users, v_guest_users, v_registered_users
      FROM active_user_counts(v_month_start::TIMESTAMPTZ, v_month_end::TIMESTAMPTZ) c;

    SELECT
        SUM(total_messages),
        SUM(private_messages),
        SUM(photo_uploads),
        SUM(new_registrations),
        AVG(radio_listeners_avg)::INTEGER,
        MAX(radio_listeners_peak),
        MAX(peak_concurrent_users)
    INTO
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
$function$
SQL;
    }

    private function aggregateYearlyStatsSql(): string
    {
        return <<<'SQL'
CREATE OR REPLACE FUNCTION public.aggregate_yearly_stats(target_year integer)
 RETURNS void
 LANGUAGE plpgsql
AS $function$
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

    SELECT c.active_users, c.guest_users, c.registered_users
      INTO v_active_users, v_guest_users, v_registered_users
      FROM active_user_counts(v_year_start::TIMESTAMPTZ, v_year_end::TIMESTAMPTZ) c;

    SELECT
        SUM(total_messages),
        SUM(private_messages),
        SUM(photo_uploads),
        SUM(new_registrations),
        AVG(radio_listeners_avg)::INTEGER,
        MAX(radio_listeners_peak),
        MAX(peak_concurrent_users)
    INTO
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
$function$
SQL;
    }

    /**
     * Recompute the stored history with the corrected definitions — every hour
     * that has activity or an existing row, then the levels above it (each reads
     * the one below, so the order matters).
     */
    private function backfill(): void
    {
        $this->DB()->statement(<<<'SQL'
DO $$
DECLARE
    v_hour TIMESTAMPTZ;
    v_date DATE;
    v_year INTEGER;
BEGIN
    FOR v_hour IN
        SELECT DISTINCT date_trunc('hour', t) AS h
        FROM (
            SELECT stat_hour AS t FROM stats_hourly
            UNION ALL
            SELECT created_at FROM chat_messages
            UNION ALL
            SELECT created_at FROM private_messages
        ) s
        WHERE t IS NOT NULL
        ORDER BY 1
    LOOP
        PERFORM aggregate_hourly_stats(v_hour);
    END LOOP;

    FOR v_date IN
        SELECT DISTINCT stat_hour::DATE FROM stats_hourly ORDER BY 1
    LOOP
        PERFORM aggregate_daily_stats(v_date);
    END LOOP;

    FOR v_date IN
        SELECT DISTINCT date_trunc('week', stat_date)::DATE FROM stats_daily ORDER BY 1
    LOOP
        PERFORM aggregate_weekly_stats(v_date);
    END LOOP;

    FOR v_date IN
        SELECT DISTINCT date_trunc('month', stat_date)::DATE FROM stats_daily ORDER BY 1
    LOOP
        PERFORM aggregate_monthly_stats(v_date);
    END LOOP;

    FOR v_year IN
        SELECT DISTINCT EXTRACT(YEAR FROM stat_date)::INTEGER FROM stats_daily ORDER BY 1
    LOOP
        PERFORM aggregate_yearly_stats(v_year);
    END LOOP;
END
$$;
SQL);
    }
}
