<?php

namespace RadioChatBox\Migrations;

use Pramnos\Database\Migration;

/**
 * Per-bot "allow explicit" flag. When on, the bot won't treat sexual/anatomical
 * language as abuse — so an NSFW/sexting persona isn't wrongly "offended" and
 * doesn't block the user mid-roleplay (the abuse detector's sexual-insult
 * pattern also matches consensual dirty talk like "αρχίδια").
 *
 * Additive + nullable (default false); runs after the create_schema baseline.
 */
final class AddFakeUserAllowExplicit extends Migration
{
    public $description = 'fake_users.bot_allow_explicit (skip abuse blocking for NSFW personas)';
    public bool $transactional = true;
    public array $dependencies = ['create_schema'];

    public function up(): void
    {
        $s = $this->schema();
        if (!$s->hasTable('fake_users') || $s->hasColumn('fake_users', 'bot_allow_explicit')) {
            return;
        }
        $s->table('fake_users', function ($t) {
            $t->boolean('bot_allow_explicit')->nullable()->default(false);
        });
    }

    public function down(): void
    {
        $s = $this->schema();
        if ($s->hasTable('fake_users') && $s->hasColumn('fake_users', 'bot_allow_explicit')) {
            $s->table('fake_users', function ($t) {
                $t->dropColumn('bot_allow_explicit');
            });
        }
    }
}
