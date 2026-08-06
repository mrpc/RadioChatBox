<?php

namespace RadioChatBox\Migrations;

use Pramnos\Database\Migration;

/**
 * Tell "in the background" apart from "gone".
 *
 * Presence had one timestamp answering two different questions: who to show as
 * online, and how many people were here at once. A phone that goes into the
 * background freezes the page — timers and all — so the 60s heartbeat stops and
 * five minutes later the session is deleted, even though the person is still
 * there and will read their DMs when they unlock. The user list wants that
 * strictness (nobody wants ghosts in it); the concurrency count does not, and it
 * has been quietly under-reporting a mobile audience ever since.
 *
 * Widening the one window would have put those ghosts straight into the list, so
 * instead the two questions are separated. The client now SAYS it is leaving,
 * with a sendBeacon on visibilitychange (the one call that survives a page being
 * frozen), and that lands here as `state`:
 *
 *   active  beating normally → shown in the user list, deleted after 5 minutes
 *   away    backgrounded on purpose → NOT in the list, kept for 30 minutes and
 *           still counted as present for peak-concurrency
 *
 * So it is knowledge rather than inference: silence still means gone, while an
 * announced absence means away. An away session that never comes back still
 * expires — 30 minutes instead of 5 — because the beacon cannot reliably tell a
 * backgrounded tab from a closed one.
 *
 * Additive and idempotent; existing rows default to 'active'.
 */
final class PresenceAwayState extends Migration
{
    public $description = 'presence_sessions.state (active|away) + away-aware cleanup';
    public bool $transactional = true;
    public array $dependencies = ['create_schema'];

    /** How long a normally-beating session survives without a heartbeat. */
    private const ACTIVE_TTL = '5 minutes';

    /** How long a session that announced it was backgrounded is kept. */
    private const AWAY_TTL = '30 minutes';

    public function up(): void
    {
        $s = $this->schema();

        if ($s->hasTable('presence_sessions') && !$s->hasColumn('presence_sessions', 'state')) {
            $s->table('presence_sessions', function ($t) {
                $t->string('state', 10)->nullable()->default('active');
            });
        }

        // Partial index: the away rows are the ones read on their own (the
        // concurrency count and the longer expiry), and they are the minority.
        $this->DB()->statement(
            "CREATE INDEX IF NOT EXISTS idx_presence_sessions_away
             ON presence_sessions (last_heartbeat) WHERE state = 'away';"
        );

        $this->DB()->statement($this->cleanupSql());
    }

    public function down(): void
    {
        $db = $this->DB();
        $s  = $this->schema();

        // Back to one rule for everyone before the column disappears.
        $db->statement(<<<'SQL'
CREATE OR REPLACE FUNCTION public.cleanup_inactive_sessions()
 RETURNS integer
 LANGUAGE plpgsql
AS $function$
DECLARE
    deleted_count INTEGER;
BEGIN
    DELETE FROM presence_sessions
    WHERE last_heartbeat < NOW() - INTERVAL '5 minutes';

    GET DIAGNOSTICS deleted_count = ROW_COUNT;
    RETURN deleted_count;
END;
$function$
SQL);

        $db->statement('DROP INDEX IF EXISTS idx_presence_sessions_away;');

        if ($s->hasTable('presence_sessions') && $s->hasColumn('presence_sessions', 'state')) {
            $s->table('presence_sessions', function ($t) {
                $t->dropColumn('state');
            });
        }
    }

    /**
     * Two expiries in one pass. A row with no state (written before this
     * migration, or by a path that does not set one) is treated as active.
     */
    private function cleanupSql(): string
    {
        $active = self::ACTIVE_TTL;
        $away   = self::AWAY_TTL;

        return <<<SQL
CREATE OR REPLACE FUNCTION public.cleanup_inactive_sessions()
 RETURNS integer
 LANGUAGE plpgsql
AS \$function\$
DECLARE
    deleted_count INTEGER;
BEGIN
    DELETE FROM presence_sessions
    WHERE CASE
        WHEN state = 'away' THEN last_heartbeat < NOW() - INTERVAL '{$away}'
        ELSE last_heartbeat < NOW() - INTERVAL '{$active}'
    END;

    GET DIAGNOSTICS deleted_count = ROW_COUNT;
    RETURN deleted_count;
END;
\$function\$
SQL;
    }
}
