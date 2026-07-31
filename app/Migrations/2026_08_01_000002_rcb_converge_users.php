<?php

namespace RadioChatBox\Migrations;

use Pramnos\Database\Migration;

/**
 * Stage 2a of the PF schema convergence: reshape RCB's `users` table in place to
 * the framework `users` shape, so the framework's own create_users_table becomes
 * a harmless hasTable() skip and every 2020 auth companion (userdetails,
 * usertokens, RBAC, 2FA, passkeys — all FK userid) can create cleanly.
 *
 * In place (not rename-to-legacy): the RCB `id SERIAL` is renamed to `userid` and
 * widened to bigint — every existing id is preserved. FK repointing + the Guest
 * (userid=1) reservation + admin remap live in the sibling 000003 migration,
 * which runs right after (priority 6).
 *
 * EXPAND phase of role→usertype: `usertype` is added and backfilled from the
 * `role` enum via the ladder root=99 / administrator=90 / moderator=50 /
 * simple_user=0, but `role` (and the RCB app columns display_name/is_active/
 * timestamps/created_by) are RETAINED so existing app code keeps working. The
 * `role` column + `user_role` type are dropped later in the finalize migration
 * (Stage 5), after the app code has moved to usertype/RBAC.
 *
 * priority 5 keeps this ahead of the framework create_users_table (priority 10)
 * in any batch where the baseline is already applied (production; test Phase B).
 */
final class RcbConvergeUsers extends Migration
{
    public $description = 'Converge users to the framework shape in place (id→userid, +usertype/framework columns)';
    public bool $transactional = true;
    public int $priority = 5;
    public array $dependencies = ['create_schema'];

    public function up(): void
    {
        $s  = $this->schema();
        $db = $this->DB();

        // Idempotency: if already converged (userid present), do nothing.
        if ($s->hasColumn('users', 'userid')) {
            return;
        }

        // 1. Drop RCB-only constraints/indexes that block the reshape.
        //    email='' would violate the valid_email regex CHECK; the framework
        //    uses a NON-unique idx_users_email, and '' would break a unique one.
        $db->statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS valid_email;');
        $db->statement('DROP INDEX IF EXISTS idx_users_email_unique;');

        // 2. PK column id → userid, widen to bigint (sequence users_id_seq stays).
        $db->statement('ALTER TABLE users RENAME COLUMN id TO userid;');
        $db->statement('ALTER TABLE users ALTER COLUMN userid TYPE bigint;');

        // 3. Secret column + created_by widening.
        $db->statement('ALTER TABLE users RENAME COLUMN password_hash TO password;');
        $db->statement('ALTER TABLE users ALTER COLUMN created_by TYPE bigint;');

        // 4. email: the framework declares it NOT NULL DEFAULT '', but RCB treats
        //    email as OPTIONAL and writes an explicit NULL when absent. Keep it
        //    NULLABLE (framework reads tolerate NULL) and only add the default so
        //    framework-side inserts that omit the column still work. Forcing
        //    NOT NULL here would break UserService::createUser (email => null).
        $db->statement("ALTER TABLE users ALTER COLUMN email SET DEFAULT '';");

        // 5. Framework columns, backfilled from RCB data where meaningful.
        $db->statement('ALTER TABLE users ADD COLUMN IF NOT EXISTS usertype smallint NOT NULL DEFAULT 0;');
        $db->statement(
            "UPDATE users SET usertype = CASE role
                WHEN 'root'          THEN 99
                WHEN 'administrator' THEN 90
                WHEN 'moderator'     THEN 50
                ELSE 0 END;"
        );
        $db->statement('ALTER TABLE users ADD COLUMN IF NOT EXISTS regdate integer NOT NULL DEFAULT 0;');
        $db->statement('UPDATE users SET regdate = COALESCE(EXTRACT(EPOCH FROM created_at)::int, 0);');
        $db->statement('ALTER TABLE users ADD COLUMN IF NOT EXISTS lastlogin integer NOT NULL DEFAULT 0;');
        $db->statement('UPDATE users SET lastlogin = COALESCE(EXTRACT(EPOCH FROM last_login)::int, 0);');
        $db->statement('ALTER TABLE users ADD COLUMN IF NOT EXISTS modified integer NOT NULL DEFAULT 0;');
        $db->statement('UPDATE users SET modified = COALESCE(EXTRACT(EPOCH FROM updated_at)::int, 0);');
        $db->statement('ALTER TABLE users ADD COLUMN IF NOT EXISTS active smallint NOT NULL DEFAULT 1;');
        $db->statement('UPDATE users SET active = CASE WHEN is_active THEN 1 ELSE 0 END;');
        $db->statement('ALTER TABLE users ADD COLUMN IF NOT EXISTS validated smallint NOT NULL DEFAULT 1;');
        $db->statement("ALTER TABLE users ADD COLUMN IF NOT EXISTS lastname   varchar(128) NOT NULL DEFAULT '';");
        $db->statement("ALTER TABLE users ADD COLUMN IF NOT EXISTS firstname  varchar(128) NOT NULL DEFAULT '';");
        $db->statement("ALTER TABLE users ADD COLUMN IF NOT EXISTS language   varchar(50)  NOT NULL DEFAULT '';");
        $db->statement("ALTER TABLE users ADD COLUMN IF NOT EXISTS timezone   char(3)      NOT NULL DEFAULT '';");
        $db->statement("ALTER TABLE users ADD COLUMN IF NOT EXISTS dateformat varchar(15)  NOT NULL DEFAULT 'd/m/Y H:i';");
        $db->statement('ALTER TABLE users ADD COLUMN IF NOT EXISTS sex        smallint NOT NULL DEFAULT 0;');
        $db->statement('ALTER TABLE users ADD COLUMN IF NOT EXISTS birthdate  bigint   NOT NULL DEFAULT 0;');
        $db->statement('ALTER TABLE users ADD COLUMN IF NOT EXISTS photo      integer;');
        $db->statement("ALTER TABLE users ADD COLUMN IF NOT EXISTS phone   varchar(50)  NOT NULL DEFAULT '';");
        $db->statement("ALTER TABLE users ADD COLUMN IF NOT EXISTS fax     varchar(50)  NOT NULL DEFAULT '';");
        $db->statement("ALTER TABLE users ADD COLUMN IF NOT EXISTS mobile  varchar(50)  NOT NULL DEFAULT '';");
        $db->statement("ALTER TABLE users ADD COLUMN IF NOT EXISTS vat     varchar(15)  NOT NULL DEFAULT '';");
        $db->statement("ALTER TABLE users ADD COLUMN IF NOT EXISTS website varchar(255) NOT NULL DEFAULT '';");
        $db->statement('ALTER TABLE users ADD COLUMN IF NOT EXISTS fbauth  bigint;');
        $db->statement('ALTER TABLE users ADD COLUMN IF NOT EXISTS regcompletion   integer;');
        $db->statement('ALTER TABLE users ADD COLUMN IF NOT EXISTS lasttermsagreed integer;');

        // 6. Framework indexes (idempotent).
        $db->statement('CREATE INDEX IF NOT EXISTS idx_users_email            ON users(email);');
        $db->statement('CREATE INDEX IF NOT EXISTS idx_users_active_validated ON users(active, validated);');
        $db->statement('CREATE INDEX IF NOT EXISTS idx_users_usertype         ON users(usertype);');
    }

    public function down(): void
    {
        $s  = $this->schema();
        $db = $this->DB();
        if (!$s->hasColumn('users', 'userid')) {
            return;
        }
        // Best-effort reversal (real rollback = restore from pre-cutover dump).
        foreach ([
            'idx_users_usertype', 'idx_users_active_validated', 'idx_users_email',
        ] as $idx) {
            $db->statement("DROP INDEX IF EXISTS {$idx};");
        }
        foreach ([
            'usertype', 'regdate', 'lastlogin', 'modified', 'active', 'validated',
            'lastname', 'firstname', 'language', 'timezone', 'dateformat', 'sex',
            'birthdate', 'photo', 'phone', 'fax', 'mobile', 'vat', 'website',
            'fbauth', 'regcompletion', 'lasttermsagreed',
        ] as $col) {
            $db->statement("ALTER TABLE users DROP COLUMN IF EXISTS {$col};");
        }
        $db->statement('ALTER TABLE users ALTER COLUMN email DROP NOT NULL;');
        $db->statement('ALTER TABLE users RENAME COLUMN password TO password_hash;');
        $db->statement('ALTER TABLE users ALTER COLUMN userid TYPE integer;');
        $db->statement('ALTER TABLE users RENAME COLUMN userid TO id;');
    }
}
