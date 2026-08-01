<?php

namespace RadioChatBox\Migrations;

use Pramnos\Database\Migration;

/**
 * Stage 2b: widen and repoint every FK that referenced RCB `users.id` (now
 * `users.userid` bigint), reserve `userid = 1` for the framework Guest by
 * remapping the admin off it, and recreate the FKs with their exact original
 * names against `users(userid)`.
 *
 * Runs after 000002 (priority 6 + dependency). The framework reserves userid=1
 * for a Guest row; RCB seeded the admin at id=1, so the admin is moved to a new
 * id and that change is propagated through every child column (incl. the seeded
 * user_activity.user_id=1) and the self-reference, all inside one transaction.
 */
final class RcbRepointUserFks extends Migration
{
    public $description = 'Widen/repoint user FKs to users(userid), reserve Guest userid=1, remap admin';
    public bool $transactional = true;
    public int $priority = 6;
    public array $dependencies = ['create_schema', 'rcb_converge_users'];

    public function up(): void
    {
        $s  = $this->schema();
        $db = $this->DB();

        // Requires the in-place users convergence.
        if (!$s->hasColumn('users', 'userid')) {
            return;
        }
        // Idempotency: Guest already reserved → done.
        $guest = $db->query("SELECT 1 FROM users WHERE userid = 1 AND username = 'Guest'");
        if ($guest && $guest->numRows > 0) {
            return;
        }

        // (a)+(b) Name-agnostically drop EVERY FK on the user-referencing columns,
        //     then widen the parent PK + created_by + each child to bigint.
        //     Dropping by CATALOG LOOKUP (not hard-coded names) is essential: the
        //     production schema drifted — e.g. sessions_user_id_fkey /
        //     user_activity_user_id_fkey / admin_users_created_by_fkey instead of
        //     the create_schema names (the users table was once `admin_users`). A
        //     column referenced by an FK cannot be retyped, so every FK must go
        //     before the type change. The user_stats view SELECTs
        //     user_activity.user_id → drop it first, recreate after.
        $db->statement('DROP VIEW IF EXISTS user_stats;');
        $db->statement(<<<'SQL'
DO $$
DECLARE
    targets text[][] := ARRAY[
        ['dm_blocks','blocker_user_id'], ['dm_blocks','blocked_user_id'],
        ['chat_messages','user_id'],
        ['private_messages','from_user_id'], ['private_messages','to_user_id'],
        ['presence_sessions','user_id'], ['user_activity','user_id'],
        ['users','created_by']
    ];
    i int; t text; c text; fk record;
BEGIN
    -- Drop every FK on each (table, column), whatever its name.
    FOR i IN 1 .. array_length(targets, 1) LOOP
        t := targets[i][1]; c := targets[i][2];
        FOR fk IN
            SELECT con.conname
            FROM pg_constraint con
            WHERE con.contype = 'f'
              AND con.conrelid = to_regclass('public.' || t)
              AND c = ANY (
                  SELECT a.attname FROM pg_attribute a
                  WHERE a.attrelid = con.conrelid AND a.attnum = ANY (con.conkey)
              )
        LOOP
            EXECUTE format('ALTER TABLE %I DROP CONSTRAINT %I', t, fk.conname);
        END LOOP;
    END LOOP;

    -- Widen the parent PK + self-ref column, then every child column.
    EXECUTE 'ALTER TABLE users ALTER COLUMN userid TYPE bigint';
    EXECUTE 'ALTER TABLE users ALTER COLUMN created_by TYPE bigint';
    FOR i IN 1 .. array_length(targets, 1) LOOP
        t := targets[i][1]; c := targets[i][2];
        IF t <> 'users' THEN
            EXECUTE format('ALTER TABLE %I ALTER COLUMN %I TYPE bigint', t, c);
        END IF;
    END LOOP;
END $$;
SQL);

        // (c) Reserve userid=1 for Guest: move whoever holds userid=1 to a fresh id
        //     and propagate through all children + self-ref, then insert Guest.
        //     All in one PL/pgSQL block so newAdminId is computed once.
        $db->statement(<<<'SQL'
DO $$
DECLARE
    new_admin_id bigint;
BEGIN
    IF EXISTS (SELECT 1 FROM users WHERE userid = 1) THEN
        SELECT COALESCE(MAX(userid), 1) + 1 INTO new_admin_id FROM users;

        UPDATE users             SET userid          = new_admin_id WHERE userid          = 1;
        UPDATE chat_messages     SET user_id         = new_admin_id WHERE user_id         = 1;
        UPDATE private_messages  SET from_user_id    = new_admin_id WHERE from_user_id    = 1;
        UPDATE private_messages  SET to_user_id      = new_admin_id WHERE to_user_id      = 1;
        UPDATE presence_sessions SET user_id         = new_admin_id WHERE user_id         = 1;
        UPDATE user_activity     SET user_id         = new_admin_id WHERE user_id         = 1;
        UPDATE dm_blocks         SET blocker_user_id = new_admin_id WHERE blocker_user_id = 1;
        UPDATE dm_blocks         SET blocked_user_id = new_admin_id WHERE blocked_user_id = 1;
        UPDATE users             SET created_by      = new_admin_id WHERE created_by      = 1;
    END IF;

    INSERT INTO users (userid, username, password, email, usertype, active, validated,
                       regdate, modified, role, is_active, created_at, updated_at)
    VALUES (1, 'Guest', '', '', 0, 1, 1,
            EXTRACT(EPOCH FROM now())::int, EXTRACT(EPOCH FROM now())::int,
            'simple_user', true, now(), now())
    ON CONFLICT (userid) DO NOTHING;
END $$;
SQL);

        // (d) Keep the sequence ahead of max(userid).
        $db->statement("SELECT setval(pg_get_serial_sequence('users','userid'), (SELECT MAX(userid) FROM users), true);");

        // Recreate the user_stats view dropped in (b) (verbatim from the baseline).
        $db->statement(<<<'SQL'
CREATE OR REPLACE VIEW user_stats AS
 SELECT username, ip_address, first_seen, last_seen, message_count,
        is_banned, is_moderator, user_id
   FROM user_activity
  ORDER BY last_seen DESC;
SQL);

        // (e) Recreate the FKs (deduped) referencing users(userid).
        $db->statement('ALTER TABLE dm_blocks        ADD CONSTRAINT dm_blocks_blocker_user_id_fkey FOREIGN KEY (blocker_user_id) REFERENCES users(userid) ON DELETE CASCADE;');
        $db->statement('ALTER TABLE dm_blocks        ADD CONSTRAINT dm_blocks_blocked_user_id_fkey FOREIGN KEY (blocked_user_id) REFERENCES users(userid) ON DELETE CASCADE;');
        $db->statement('ALTER TABLE chat_messages    ADD CONSTRAINT fk_messages_user                FOREIGN KEY (user_id)         REFERENCES users(userid) ON DELETE SET NULL;');
        $db->statement('ALTER TABLE private_messages ADD CONSTRAINT private_messages_to_user_id_fkey   FOREIGN KEY (to_user_id)   REFERENCES users(userid) ON DELETE SET NULL;');
        $db->statement('ALTER TABLE private_messages ADD CONSTRAINT private_messages_from_user_id_fkey FOREIGN KEY (from_user_id) REFERENCES users(userid) ON DELETE SET NULL;');
        $db->statement('ALTER TABLE presence_sessions ADD CONSTRAINT fk_sessions_user               FOREIGN KEY (user_id)         REFERENCES users(userid) ON DELETE SET NULL;');
        $db->statement('ALTER TABLE user_activity     ADD CONSTRAINT fk_user_activity_user          FOREIGN KEY (user_id)         REFERENCES users(userid) ON DELETE SET NULL;');
        $db->statement('ALTER TABLE users             ADD CONSTRAINT users_created_by_fkey          FOREIGN KEY (created_by)      REFERENCES users(userid);');
    }

    public function down(): void
    {
        // Forward-only in practice; real rollback = restore from the pre-cutover dump.
    }
}
