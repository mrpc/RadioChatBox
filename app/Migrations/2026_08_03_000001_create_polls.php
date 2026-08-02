<?php

namespace RadioChatBox\Migrations;

use Pramnos\Database\Migration;

/**
 * Live chat polls: a moderator posts a multiple-choice question; users vote once
 * (per session) and see live results. One `polls` row per poll (options stored
 * as a JSON array), one `poll_votes` row per voter.
 *
 * Additive; runs after the create_schema baseline.
 */
final class CreatePolls extends Migration
{
    public $description = 'polls + poll_votes tables (live chat polls)';
    public bool $transactional = true;
    public array $dependencies = ['create_schema'];

    public function up(): void
    {
        $s = $this->schema();

        if (!$s->hasTable('polls')) {
            $s->createTable('polls', function ($t) {
                $t->increments('id');
                $t->text('question');
                $t->jsonb('options');                       // JSON array of option labels
                $t->string('created_by', 100)->nullable();
                $t->boolean('is_active')->default(true);
                $t->timestampTz('created_at')->useCurrent();
                $t->timestampTz('closed_at')->nullable();
                $t->timestampTz('expires_at')->nullable();   // null = no auto-close

                $t->index(['is_active'], 'idx_polls_active');
                $t->index(['created_at DESC'], 'idx_polls_created');
            });
        }

        if (!$s->hasTable('poll_votes')) {
            $s->createTable('poll_votes', function ($t) {
                $t->increments('id');
                $t->integer('poll_id');
                $t->integer('option_index');
                $t->string('voter_session', 255);
                $t->string('voter_username', 50)->nullable();
                $t->timestampTz('created_at')->useCurrent();

                $t->unique(['poll_id', 'voter_session'], 'uq_poll_votes_poll_session');
                $t->index(['poll_id'], 'idx_poll_votes_poll');
            });
        }
    }

    public function down(): void
    {
        $this->schema()->dropTableIfExists('poll_votes');
        $this->schema()->dropTableIfExists('polls');
    }
}
