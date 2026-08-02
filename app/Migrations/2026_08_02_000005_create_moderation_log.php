<?php

namespace RadioChatBox\Migrations;

use Pramnos\Database\Migration;

/**
 * Moderator audit trail: one row per moderation action (kick, ban, timeout,
 * report resolution) so there is accountability for who did what and when.
 *
 * Additive; runs after the create_schema baseline.
 */
final class CreateModerationLog extends Migration
{
    public $description = 'moderation_log table (moderator audit trail)';
    public bool $transactional = true;
    public array $dependencies = ['create_schema'];

    public function up(): void
    {
        $s = $this->schema();
        if ($s->hasTable('moderation_log')) {
            return;
        }

        $s->createTable('moderation_log', function ($t) {
            $t->increments('id');
            $t->string('admin_username', 100)->nullable();
            $t->string('action', 50);                 // kick | ban_nickname | timeout | report_resolve | ...
            $t->string('target', 150)->nullable();    // affected user/nickname
            $t->text('details')->nullable();
            $t->timestampTz('created_at')->useCurrent();

            $t->index(['created_at DESC'], 'idx_moderation_log_created');
            $t->index(['action'], 'idx_moderation_log_action');
        });
    }

    public function down(): void
    {
        $this->schema()->dropTableIfExists('moderation_log');
    }
}
