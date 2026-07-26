<?php

namespace RadioChatBox\Migrations;

use Pramnos\Database\Blueprint;
use Pramnos\Database\Migration;

/**
 * Let a public chat message pin the track that was playing when it was sent.
 *
 * Native SchemaBuilder rewrite of database/migrations/020_add_pinned_track_to_messages.sql.
 * (This column is not created by init.sql, so no existence guard is needed.)
 */
final class AddPinnedTrackToMessages extends Migration
{
    public $description = 'Add pinned_track to messages';

    public bool $transactional = false;

    public function up(): void
    {
        $this->schema()->table('messages', function (Blueprint $table) {
            $table->string('pinned_track', 500)->nullable()->default(null)
                ->comment('Snapshot of the now-playing track pinned to this message (NULL if none).');
        });
    }

    public function down(): void
    {
        $this->schema()->table('messages', function (Blueprint $table) {
            $table->dropColumn('pinned_track');
        });
    }
}
