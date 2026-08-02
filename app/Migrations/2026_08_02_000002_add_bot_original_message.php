<?php

namespace RadioChatBox\Migrations;

use Pramnos\Database\Migration;

/**
 * Keep the bot's original Greek text next to its peer-facing message.
 *
 * When a bot replies to a greeklish peer, the delivered `message` is
 * transliterated to greeklish (latin) for the reader — but that transliterated
 * text must never be fed back to the LLM as conversation history, or the model
 * learns to write greeklish itself. `bot_original_message` stores the untouched
 * Greek so buildHistory() can prefer it; it is NULL for peer messages and for
 * bot replies that were never transliterated (they equal `message`).
 *
 * Additive and idempotent; runs after the create_schema baseline.
 */
final class AddBotOriginalMessage extends Migration
{
    public $description = 'private_messages.bot_original_message (Greek source for bot history)';
    public bool $transactional = true;
    public array $dependencies = ['create_schema'];

    public function up(): void
    {
        $s = $this->schema();
        if (!$s->hasColumn('private_messages', 'bot_original_message')) {
            $s->table('private_messages', function ($t) {
                $t->text('bot_original_message')->nullable();
            });
        }
    }

    public function down(): void
    {
        $s = $this->schema();
        if ($s->hasColumn('private_messages', 'bot_original_message')) {
            $s->table('private_messages', function ($t) {
                $t->dropColumn('bot_original_message');
            });
        }
    }
}
