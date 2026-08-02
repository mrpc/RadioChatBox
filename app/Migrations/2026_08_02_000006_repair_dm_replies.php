<?php

namespace RadioChatBox\Migrations;

use Pramnos\Database\Migration;

/**
 * REPAIR migration. On some databases the original add_dm_replies migration was
 * recorded as applied but `private_message_reactions` was never actually created
 * (the reply_to_id ALTER landed, the table did not), so DM reactions silently
 * failed to persist. Recreate anything missing, idempotently.
 *
 * Uses the schema builder with hasTable/hasColumn guards: where an object is
 * genuinely missing the guard is false and it gets created; where it already
 * exists the guard skips it, so this is safe to run on every database.
 */
final class RepairDmReplies extends Migration
{
    public $description = 'Repair: ensure private_message_reactions + reply columns exist';
    public bool $transactional = true;
    public array $dependencies = ['create_schema'];

    public function up(): void
    {
        $s = $this->schema();

        if (!$s->hasColumn('private_messages', 'reply_to_id')) {
            $s->table('private_messages', function ($t) {
                $t->integer('reply_to_id')->nullable();
                $t->index(['reply_to_id'], 'idx_pm_reply_to');
            });
        }
        if (!$s->hasColumn('private_messages', 'bot_original_message')) {
            $s->table('private_messages', function ($t) {
                $t->text('bot_original_message')->nullable();
            });
        }

        if (!$s->hasTable('private_message_reactions')) {
            $s->createTable('private_message_reactions', function ($t) {
                $t->increments('id');
                $t->integer('message_id');
                $t->string('username', 100);
                $t->string('session_id', 255)->nullable();
                $t->string('emoji', 16);
                $t->timestampTz('created_at')->useCurrent();
                $t->unique(['message_id', 'username'], 'uq_pm_reactions_user');
                $t->index(['message_id'], 'idx_pm_reactions_message');
                $t->foreign('message_id')->references('id')->on('private_messages')->onDelete('CASCADE');
            });
        }
    }

    public function down(): void
    {
        // No-op: this only ensures objects exist.
    }
}
