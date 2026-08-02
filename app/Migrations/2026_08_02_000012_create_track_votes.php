<?php

namespace RadioChatBox\Migrations;

use Pramnos\Database\Migration;

/**
 * Now-playing voting: a listener gives the currently-playing track a thumbs
 * up/down. One vote per session per track (togglable), keyed by the track's
 * display string so it works even for tracks not yet in the catalog.
 *
 * Additive; runs after the create_schema baseline.
 */
final class CreateTrackVotes extends Migration
{
    public $description = 'track_votes table (now-playing thumbs up/down)';
    public bool $transactional = true;
    public array $dependencies = ['create_schema'];

    public function up(): void
    {
        $s = $this->schema();
        if ($s->hasTable('track_votes')) {
            return;
        }

        $s->createTable('track_votes', function ($t) {
            $t->increments('id');
            $t->string('track_display', 500);           // the now-playing display voted on
            $t->string('voter_session', 255);
            $t->string('voter_username', 50)->nullable();
            $t->integer('vote');                         // +1 up, -1 down
            $t->timestampTz('created_at')->useCurrent();
            $t->timestampTz('updated_at')->nullable();

            $t->unique(['track_display', 'voter_session'], 'uq_track_votes_track_session');
            $t->index(['track_display'], 'idx_track_votes_track');
        });
    }

    public function down(): void
    {
        $this->schema()->dropTableIfExists('track_votes');
    }
}
