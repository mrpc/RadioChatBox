<?php

namespace RadioChatBox\Migrations;

use Pramnos\Database\Migration;

/**
 * Stage 1 of the PF schema convergence: free the `messages` and `sessions`
 * table names so the framework can create its own (unrelated) canonical tables.
 *
 * RCB's public chat feed `messages` → `chat_messages`, and RCB's presence/
 * heartbeat `sessions` → `presence_sessions`. In PostgreSQL, FKs, indexes,
 * triggers and views survive a table rename (they bind by OID), so only the
 * plpgsql FUNCTION bodies that name the tables as text must be redefined:
 * `aggregate_hourly_stats` (both overloads) read `FROM messages`, and
 * `cleanup_inactive_sessions` does `DELETE FROM sessions`.
 *
 * The hourly-stats functions are additionally future-proofed against the
 * upcoming `users.id`→`userid` rename (Stage 2) by testing `u.username IS NULL`
 * instead of `u.id IS NULL` to detect guest authors — an equivalent, PK-name-
 * agnostic check.
 *
 * Priority 5 keeps this ahead of the framework's create_sessions_table (10) /
 * create_messages_table (30) once framework migrations are enabled in Stage 2.
 */
final class RcbRenameCollidingTables extends Migration
{
    public $description = 'Rename messages→chat_messages and sessions→presence_sessions (free framework names)';
    public bool $transactional = true;
    public int $priority = 5;
    public array $dependencies = ['create_schema'];

    public function up(): void
    {
        $s = $this->schema();

        if ($s->hasTable('messages') && !$s->hasTable('chat_messages')) {
            $this->DB()->statement('ALTER TABLE messages RENAME TO chat_messages;');
        }
        if ($s->hasTable('sessions') && !$s->hasTable('presence_sessions')) {
            $this->DB()->statement('ALTER TABLE sessions RENAME TO presence_sessions;');
        }

        // Redefine the functions that reference the tables by name.
        $this->DB()->statement($this->aggregateHourlyStatsSql('chat_messages'));
        $this->DB()->statement($this->cleanupInactiveSessionsSql('presence_sessions'));
    }

    public function down(): void
    {
        $s = $this->schema();

        if ($s->hasTable('chat_messages') && !$s->hasTable('messages')) {
            $this->DB()->statement('ALTER TABLE chat_messages RENAME TO messages;');
        }
        if ($s->hasTable('presence_sessions') && !$s->hasTable('sessions')) {
            $this->DB()->statement('ALTER TABLE presence_sessions RENAME TO sessions;');
        }

        $this->DB()->statement($this->aggregateHourlyStatsSql('messages'));
        $this->DB()->statement($this->cleanupInactiveSessionsSql('sessions'));
    }

    /**
     * Both aggregate_hourly_stats overloads (timestamp / timestamptz), emitted
     * against the given messages-table name. Guest detection uses
     * `u.username IS NULL` so it is independent of the users PK column name.
     */
    private function aggregateHourlyStatsSql(string $messagesTable): string
    {
        $body = static function (string $tsType) use ($messagesTable): string {
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

    SELECT COUNT(DISTINCT username) INTO v_active_users
    FROM {$messagesTable}
    WHERE created_at >= v_hour_start AND created_at < v_hour_end AND is_deleted = FALSE;

    SELECT COUNT(DISTINCT m.username) INTO v_guest_users
    FROM {$messagesTable} m LEFT JOIN users u ON m.username = u.username
    WHERE m.created_at >= v_hour_start AND m.created_at < v_hour_end
      AND m.is_deleted = FALSE AND u.username IS NULL;

    v_registered_users := COALESCE(v_active_users, 0) - COALESCE(v_guest_users, 0);

    SELECT COUNT(*) INTO v_total_messages
    FROM {$messagesTable}
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
\$function\$;
SQL;
        };

        return $body('timestamp without time zone') . "\n" . $body('timestamp with time zone');
    }

    private function cleanupInactiveSessionsSql(string $sessionsTable): string
    {
        return <<<SQL
CREATE OR REPLACE FUNCTION public.cleanup_inactive_sessions()
 RETURNS integer
 LANGUAGE plpgsql
AS \$function\$
DECLARE
    deleted_count INTEGER;
BEGIN
    DELETE FROM {$sessionsTable}
    WHERE last_heartbeat < NOW() - INTERVAL '5 minutes';

    GET DIAGNOSTICS deleted_count = ROW_COUNT;
    RETURN deleted_count;
END;
\$function\$;
SQL;
    }
}
