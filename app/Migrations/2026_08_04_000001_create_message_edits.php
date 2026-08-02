<?php

namespace RadioChatBox\Migrations;

use Pramnos\Database\Migration;

/**
 * Edit history for public chat messages: each time a message is edited within the
 * edit window, its previous text is snapshotted here before being overwritten, so
 * moderators can see what a message said before it was changed.
 *
 * Additive; runs after the create_schema baseline.
 */
final class CreateMessageEdits extends Migration
{
    public $description = 'message_edits table (public message edit history)';
    public bool $transactional = true;
    public array $dependencies = ['create_schema'];

    public function up(): void
    {
        $s = $this->schema();

        if (!$s->hasTable('message_edits')) {
            $s->createTable('message_edits', function ($t) {
                $t->increments('id');
                $t->string('message_id', 255);
                $t->text('old_message');            // the text BEFORE this edit
                $t->string('edited_by', 100)->nullable();
                $t->timestampTz('created_at')->useCurrent();

                $t->index(['message_id'], 'idx_message_edits_message');
                $t->index(['created_at DESC'], 'idx_message_edits_created');
            });
        }
    }

    public function down(): void
    {
        $this->schema()->dropTableIfExists('message_edits');
    }
}
