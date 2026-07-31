<?php

namespace RadioChatBox\Migrations;

use Pramnos\Database\Migration;

/**
 * Stage 5 (contract) of the PF schema convergence: drop the now-unused RCB
 * `users.role` enum column and the `user_role` type.
 *
 * The expand/contract sequence: the baseline still CREATES `role`/`user_role`
 * (fresh installs) and rcb_converge_users backfills `usertype` FROM `role`, so
 * `role` must exist through convergence. Once the app reads authorization from
 * `usertype` (via the Authz facade) and nothing reads the column, this drops it.
 * Running it in every environment yields an identical end state (no `role`
 * column) whether the DB started fresh or from production.
 *
 * High priority + dependency on rcb_converge_users guarantees it runs AFTER the
 * backfill. Also asserts the core convergence invariants and throws if broken,
 * so a half-converged schema is recorded as a failed migration rather than
 * silently shipping.
 */
final class RcbFinalize extends Migration
{
    public $description = 'Drop the legacy users.role column and user_role type (convergence contract)';
    public bool $transactional = true;
    public int $priority = 100;
    public array $dependencies = ['create_schema', 'rcb_converge_users'];

    public function up(): void
    {
        $s  = $this->schema();
        $db = $this->DB();

        // Invariants: the convergence must have happened before we contract.
        if (!$s->hasColumn('users', 'userid') || !$s->hasColumn('users', 'usertype')) {
            throw new \RuntimeException(
                'rcb_finalize: users.userid/usertype missing — run the convergence migrations first'
            );
        }

        // Drop the legacy role column + enum type (idempotent).
        if ($s->hasColumn('users', 'role')) {
            $db->statement('ALTER TABLE users DROP COLUMN role;');
        }
        $db->statement('DROP TYPE IF EXISTS user_role;');
    }

    public function down(): void
    {
        // Forward-only; restoring the enum + backfilling from usertype is a
        // data-recovery task, not a schema rollback. Real rollback = restore from
        // the pre-cutover dump.
    }
}
