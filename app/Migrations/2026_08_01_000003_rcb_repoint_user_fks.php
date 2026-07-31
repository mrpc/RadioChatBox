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

        // (a) Drop the 8 FKs by exact name (the duplicate messages FK will NOT be recreated).
        $db->statement('ALTER TABLE dm_blocks         DROP CONSTRAINT IF EXISTS dm_blocks_blocker_user_id_fkey;');
        $db->statement('ALTER TABLE dm_blocks         DROP CONSTRAINT IF EXISTS dm_blocks_blocked_user_id_fkey;');
        $db->statement('ALTER TABLE chat_messages     DROP CONSTRAINT IF EXISTS fk_messages_user;');
        $db->statement('ALTER TABLE chat_messages     DROP CONSTRAINT IF EXISTS messages_user_id_fkey;');
        $db->statement('ALTER TABLE private_messages  DROP CONSTRAINT IF EXISTS private_messages_to_user_id_fkey;');
        $db->statement('ALTER TABLE private_messages  DROP CONSTRAINT IF EXISTS private_messages_from_user_id_fkey;');
        $db->statement('ALTER TABLE presence_sessions DROP CONSTRAINT IF EXISTS fk_sessions_user;');
        $db->statement('ALTER TABLE user_activity     DROP CONSTRAINT IF EXISTS fk_user_activity_user;');
        $db->statement('ALTER TABLE users             DROP CONSTRAINT IF EXISTS users_created_by_fkey;');

        // (b) Widen every child FK column int4 → int8 to match users.userid.
        //     The user_stats view SELECTs user_activity.user_id, which blocks the
        //     column type change, so drop it first and recreate it verbatim after.
        $db->statement('DROP VIEW IF EXISTS user_stats;');
        $db->statement('ALTER TABLE dm_blocks         ALTER COLUMN blocker_user_id TYPE bigint;');
        $db->statement('ALTER TABLE dm_blocks         ALTER COLUMN blocked_user_id TYPE bigint;');
        $db->statement('ALTER TABLE chat_messages     ALTER COLUMN user_id         TYPE bigint;');
        $db->statement('ALTER TABLE private_messages  ALTER COLUMN from_user_id    TYPE bigint;');
        $db->statement('ALTER TABLE private_messages  ALTER COLUMN to_user_id      TYPE bigint;');
        $db->statement('ALTER TABLE presence_sessions ALTER COLUMN user_id         TYPE bigint;');
        $db->statement('ALTER TABLE user_activity     ALTER COLUMN user_id         TYPE bigint;');

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
