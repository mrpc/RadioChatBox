<?php

namespace RadioChatBox\Migrations;

use Pramnos\Database\Migration;

/**
 * Song requests + dedications: a chat user asks for a track (optionally with a
 * dedication/shout-out message), and admins work a queue — approve, mark played,
 * or reject. One row per request; the dedication rides on the same row so a
 * request and its shout-out are one thing.
 *
 * Additive; runs after the create_schema baseline.
 */
final class CreateSongRequests extends Migration
{
    public $description = 'song_requests table (listener song requests + dedications)';
    public bool $transactional = true;
    public array $dependencies = ['create_schema'];

    public function up(): void
    {
        $s = $this->schema();
        if ($s->hasTable('song_requests')) {
            return;
        }

        $s->createTable('song_requests', function ($t) {
            $t->increments('id');
            $t->string('requester_username', 100);
            $t->string('requester_session_id', 255)->nullable();
            $t->string('song_title', 300);
            $t->string('artist', 300)->nullable();
            $t->text('dedication')->nullable();               // optional shout-out
            $t->string('status', 20)->default('pending');     // pending|approved|played|rejected
            $t->string('handled_by', 100)->nullable();        // admin who actioned it
            $t->string('ip_address', 45)->nullable();
            $t->timestampTz('created_at')->useCurrent();
            $t->timestampTz('updated_at')->nullable();

            $t->index(['status'], 'idx_song_requests_status');
            $t->index(['created_at DESC'], 'idx_song_requests_created');
            $t->index(['requester_username'], 'idx_song_requests_requester');
        });
    }

    public function down(): void
    {
        $this->schema()->dropTableIfExists('song_requests');
    }
}
