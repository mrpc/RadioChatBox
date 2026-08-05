<?php

namespace RadioChatBox\Migrations;

use Pramnos\Database\Migration;

/**
 * Persistent mood for the auto-reply bots.
 *
 * A bot carries a GLOBAL mood (on fake_users) that colours every conversation —
 * so if someone angers it in one chat it stays annoyed everywhere until it
 * decays or an admin resets it — plus a fast, per-conversation LOCAL modifier (on
 * bot_threads) that reacts to the current chat. The expressed mood blends the two.
 *
 * mood            current global mood category (see MoodService::MOODS)
 * mood_intensity  0-100, how strongly it is felt (decays toward 0 over time)
 * mood_baseline   the bot's resting mood (personality default) it returns to
 * mood_updated_at last change, for time-based decay
 *
 * bot_threads.mood_local(_intensity/_updated_at) mirror this for one conversation.
 *
 * Additive + nullable; runs after the create_schema baseline.
 */
final class AddBotMood extends Migration
{
    public $description = 'bot mood: fake_users global mood + bot_threads local mood';
    public bool $transactional = true;
    public array $dependencies = ['create_schema'];

    public function up(): void
    {
        $s = $this->schema();

        if ($s->hasTable('fake_users') && !$s->hasColumn('fake_users', 'mood')) {
            $s->table('fake_users', function ($t) {
                $t->string('mood', 20)->nullable();
                $t->integer('mood_intensity')->nullable()->default(0);
                $t->string('mood_baseline', 20)->nullable();
                $t->timestampTz('mood_updated_at')->nullable();
            });
        }

        if ($s->hasTable('bot_threads') && !$s->hasColumn('bot_threads', 'mood_local')) {
            $s->table('bot_threads', function ($t) {
                $t->string('mood_local', 20)->nullable();
                $t->integer('mood_local_intensity')->nullable()->default(0);
                $t->timestampTz('mood_local_updated_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        $s = $this->schema();

        if ($s->hasTable('fake_users') && $s->hasColumn('fake_users', 'mood')) {
            $s->table('fake_users', function ($t) {
                $t->dropColumn('mood');
                $t->dropColumn('mood_intensity');
                $t->dropColumn('mood_baseline');
                $t->dropColumn('mood_updated_at');
            });
        }

        if ($s->hasTable('bot_threads') && $s->hasColumn('bot_threads', 'mood_local')) {
            $s->table('bot_threads', function ($t) {
                $t->dropColumn('mood_local');
                $t->dropColumn('mood_local_intensity');
                $t->dropColumn('mood_local_updated_at');
            });
        }
    }
}
