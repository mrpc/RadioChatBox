<?php

namespace RadioChatBox\Migrations;

use Pramnos\Database\Blueprint;
use Pramnos\Database\Migration;

/**
 * Track when a public message was last edited by its author.
 *
 * Native SchemaBuilder rewrite of database/migrations/016_add_edited_at_to_messages.sql.
 * (This column is not created by init.sql, so no existence guard is needed.)
 */
final class AddEditedAtToMessages extends Migration
{
    public $description = 'Add edited_at to messages';

    public bool $transactional = false;

    public function up(): void
    {
        $this->schema()->table('messages', function (Blueprint $table) {
            $table->timestampTz('edited_at')->nullable()->default(null)
                ->comment('Timestamp of last edit by the message author. NULL means never edited.');
        });
    }

    public function down(): void
    {
        $this->schema()->table('messages', function (Blueprint $table) {
            $table->dropColumn('edited_at');
        });
    }
}
