<?php

namespace RadioChatBox\Migrations;

use Pramnos\Database\Migration;

/**
 * Pinned messages: a moderator pins a public chat message so it stays visible in
 * a bar at the top of the chat. The message text is snapshotted so a pin
 * survives deletion of the original, and an optional expiry auto-unpins it.
 *
 * Additive; runs after the create_schema baseline.
 */
final class CreatePinnedMessages extends Migration
{
    public $description = 'pinned_messages table (moderator-pinned chat messages)';
    public bool $transactional = true;
    public array $dependencies = ['create_schema'];

    public function up(): void
    {
        $s = $this->schema();
        if ($s->hasTable('pinned_messages')) {
            return;
        }

        $s->createTable('pinned_messages', function ($t) {
            $t->increments('id');
            $t->string('message_id', 255);              // the pinned public message id
            $t->string('username', 50)->nullable();     // original author
            $t->text('content');                        // snapshot (survives deletion)
            $t->string('pinned_by', 100);
            $t->timestampTz('created_at')->useCurrent();
            $t->timestampTz('expires_at')->nullable();  // null = no expiry

            $t->unique(['message_id'], 'uq_pinned_messages_message');
            $t->index(['expires_at'], 'idx_pinned_messages_expires');
            $t->index(['created_at DESC'], 'idx_pinned_messages_created');
        });
    }

    public function down(): void
    {
        $this->schema()->dropTableIfExists('pinned_messages');
    }
}
