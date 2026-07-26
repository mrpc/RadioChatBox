<?php

namespace RadioChatBox\Migrations;

use Pramnos\Database\Migration;

/**
 * Baselined from database/migrations/001_add_reply_to_messages.sql.
 *
 * Runs the original, idempotent RadioChatBox SQL verbatim (the file remains the
 * single source of truth). On an existing database every statement is a no-op
 * (guarded with IF [NOT] EXISTS / ON CONFLICT), so the first `migrate` run simply
 * records it in schemaversion; on a fresh database it builds the schema.
 */
final class AddReplyToMessages extends Migration
{
    public $description = 'Baselined: 001_add_reply_to_messages.sql';

    // The SQL files manage their own BEGIN/COMMIT and mix DDL that Postgres will
    // not run inside a wrapping transaction, so do not double-wrap here.
    public bool $transactional = false;

    public function up(): void
    {
        $root = defined('ROOT') ? ROOT : dirname(__DIR__, 2);
        $sql = (string) file_get_contents($root . '/database/migrations/001_add_reply_to_messages.sql');
        if (trim($sql) !== '') {
            $this->DB()->statement($sql);
        }
    }

    public function down(): void
    {
        // Baselined migration: no automated rollback. The original SQL is
        // additive/idempotent and predates the framework runner.
    }
}
