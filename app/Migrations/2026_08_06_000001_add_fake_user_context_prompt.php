<?php

namespace RadioChatBox\Migrations;

use Pramnos\Database\Migration;

/**
 * Per-bot override for the "context" prompt — the block that tells the bot where
 * the conversation happens (no photos, no meeting up, single, etc.). Until now
 * that came only from the global bot_context_prompt setting; a bot with a very
 * different setting (e.g. an escort-service promo persona) needs its own.
 *
 * When set, fake_users.bot_context_prompt supersedes the global setting for that
 * bot; empty/NULL falls back to the global one. Additive + nullable; runs after
 * the create_schema baseline.
 */
final class AddFakeUserContextPrompt extends Migration
{
    public $description = 'fake_users.bot_context_prompt (per-bot context override)';
    public bool $transactional = true;
    public array $dependencies = ['create_schema'];

    public function up(): void
    {
        $s = $this->schema();
        if (!$s->hasTable('fake_users') || $s->hasColumn('fake_users', 'bot_context_prompt')) {
            return;
        }
        $s->table('fake_users', function ($t) {
            $t->text('bot_context_prompt')->nullable();
        });
    }

    public function down(): void
    {
        $s = $this->schema();
        if ($s->hasTable('fake_users') && $s->hasColumn('fake_users', 'bot_context_prompt')) {
            $s->table('fake_users', function ($t) {
                $t->dropColumn('bot_context_prompt');
            });
        }
    }
}
