<?php

namespace RadioChatBox\Migrations;

use Pramnos\Database\Migration;

/**
 * Moderator warnings: an escalating record against a user. Warnings can expire,
 * and once a user accumulates enough ACTIVE warnings the app auto-timeouts them
 * (threshold + duration are settings). One row per warning.
 *
 * Additive; runs after the create_schema baseline.
 */
final class CreateUserWarnings extends Migration
{
    public $description = 'user_warnings table (escalating moderator warnings)';
    public bool $transactional = true;
    public array $dependencies = ['create_schema'];

    public function up(): void
    {
        $s = $this->schema();
        if ($s->hasTable('user_warnings')) {
            return;
        }

        $s->createTable('user_warnings', function ($t) {
            $t->increments('id');
            $t->string('username', 50);
            $t->string('moderator', 100);
            $t->text('reason')->nullable();
            $t->timestampTz('created_at')->useCurrent();
            $t->timestampTz('expires_at')->nullable();  // null = never expires

            $t->index(['username'], 'idx_user_warnings_username');
            $t->index(['expires_at'], 'idx_user_warnings_expires');
            $t->index(['created_at DESC'], 'idx_user_warnings_created');
        });
    }

    public function down(): void
    {
        $this->schema()->dropTableIfExists('user_warnings');
    }
}
