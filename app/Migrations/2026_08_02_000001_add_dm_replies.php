<?php

namespace RadioChatBox\Migrations;

use Pramnos\Database\Migration;

/**
 * Bring the public-chat reply + reaction features to direct messages:
 *  - a nullable `reply_to_id` on private_messages pointing at another DM row;
 *  - a dedicated `private_message_reactions` table (FK to private_messages) —
 *    the public `message_reactions` table has an FK to chat_messages, so DM
 *    reactions cannot reuse it and get their own, otherwise-identical, table.
 *
 * Additive and idempotent; runs after the create_schema baseline created the
 * tables (dependency + later timestamp).
 */
final class AddDmReplies extends Migration
{
    public $description = 'DM replies + reactions (private_messages.reply_to_id, private_message_reactions)';
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
        $s = $this->schema();
        $s->dropTableIfExists('private_message_reactions');
        if ($s->hasColumn('private_messages', 'reply_to_id')) {
            $s->table('private_messages', function ($t) {
                $t->dropColumn('reply_to_id');
            });
        }
    }
}
