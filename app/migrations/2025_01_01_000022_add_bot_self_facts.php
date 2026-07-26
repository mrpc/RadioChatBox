<?php

namespace RadioChatBox\Migrations;

use Pramnos\Database\Blueprint;
use Pramnos\Database\Migration;

/**
 * A bot's stable self-facts "canon" (one per fake user).
 *
 * Native SchemaBuilder rewrite of database/migrations/025_add_bot_self_facts.sql.
 * (This column is not created by init.sql, so no existence guard is needed.)
 */
final class AddBotSelfFacts extends Migration
{
    public $description = 'Add bot_self_facts to fake_users';

    public bool $transactional = false;

    public function up(): void
    {
        $this->schema()->table('fake_users', function (Blueprint $table) {
            $table->text('bot_self_facts')->nullable()
                ->comment('Canon of stable self-facts the bot has committed to (appearance, personal details), injected into every reply so it stays consistent across conversations.');
        });
    }

    public function down(): void
    {
        $this->schema()->table('fake_users', function (Blueprint $table) {
            $table->dropColumn('bot_self_facts');
        });
    }
}
