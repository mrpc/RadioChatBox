<?php

namespace RadioChatBox\Migrations;

use Pramnos\Database\Migration;

/**
 * Link now-playing votes to the tracked catalog: add a nullable track_id to
 * track_votes pointing at tracks.id (resolved from the display string). Votes
 * still key their uniqueness on (display, session), but the FK lets them join
 * the track-play stats (e.g. top-rated tracks).
 *
 * Additive; runs after create_track_votes.
 */
final class AddTrackIdToTrackVotes extends Migration
{
    public $description = 'track_votes.track_id -> tracks.id link';
    public bool $transactional = true;
    public array $dependencies = ['create_schema'];

    public function up(): void
    {
        $s = $this->schema();
        if (!$s->hasTable('track_votes') || $s->hasColumn('track_votes', 'track_id')) {
            return;
        }

        $s->table('track_votes', function ($t) {
            $t->integer('track_id')->nullable();
            $t->index(['track_id'], 'idx_track_votes_track_id');
        });
    }

    public function down(): void
    {
        $s = $this->schema();
        if ($s->hasTable('track_votes') && $s->hasColumn('track_votes', 'track_id')) {
            $s->table('track_votes', function ($t) {
                $t->dropColumn('track_id');
            });
        }
    }
}
