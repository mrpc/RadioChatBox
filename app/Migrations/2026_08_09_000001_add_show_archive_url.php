<?php

namespace RadioChatBox\Migrations;

use Pramnos\Database\Migration;

/**
 * Optional link to a show's past-episode archive (e.g. a Mixcloud/YouTube page),
 * shown next to the show in the schedule. Additive; after create_schema.
 */
final class AddShowArchiveUrl extends Migration
{
    public $description = 'shows.archive_url';
    public bool $transactional = true;
    public array $dependencies = ['create_schema'];

    public function up(): void
    {
        $s = $this->schema();
        if ($s->hasTable('shows') && !$s->hasColumn('shows', 'archive_url')) {
            $s->table('shows', function ($t) {
                $t->string('archive_url', 500)->nullable();
            });
        }
    }

    public function down(): void
    {
        $s = $this->schema();
        if ($s->hasTable('shows') && $s->hasColumn('shows', 'archive_url')) {
            $s->table('shows', function ($t) {
                $t->dropColumn('archive_url');
            });
        }
    }
}
