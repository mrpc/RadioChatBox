<?php

namespace RadioChatBox\Migrations;

use Pramnos\Database\Migration;

/**
 * Custom chat commands: an admin defines slash-commands (e.g. /rules, /tip) that
 * reply with canned text. When a user types a matching command, the server
 * answers them directly (a private system reply, not a broadcast message). The
 * built-in /help is synthesised from the active commands and needs no row.
 *
 * Additive; runs after the create_schema baseline.
 */
final class CreateChatCommands extends Migration
{
    public $description = 'chat_commands table (admin-defined slash commands)';
    public bool $transactional = true;
    public array $dependencies = ['create_schema'];

    public function up(): void
    {
        $s = $this->schema();
        if ($s->hasTable('chat_commands')) {
            return;
        }

        $s->createTable('chat_commands', function ($t) {
            $t->increments('id');
            $t->string('command', 50);                  // stored WITHOUT the leading slash, lowercase
            $t->text('response');
            $t->string('description', 255)->nullable(); // shown in /help
            $t->boolean('is_active')->default(true);
            $t->timestampTz('created_at')->useCurrent();
            $t->timestampTz('updated_at')->nullable();

            $t->unique(['command'], 'uq_chat_commands_command');
            $t->index(['is_active'], 'idx_chat_commands_active');
        });
    }

    public function down(): void
    {
        $this->schema()->dropTableIfExists('chat_commands');
    }
}
