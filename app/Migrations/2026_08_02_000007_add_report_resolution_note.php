<?php

namespace RadioChatBox\Migrations;

use Pramnos\Database\Migration;

/**
 * Let a moderator leave a note when resolving/dismissing an abuse report
 * (what was decided / what action was taken).
 *
 * Additive; runs after create_message_reports.
 */
final class AddReportResolutionNote extends Migration
{
    public $description = 'message_reports.resolution_note';
    public bool $transactional = true;
    public array $dependencies = ['create_schema'];

    public function up(): void
    {
        $s = $this->schema();
        if (!$s->hasColumn('message_reports', 'resolution_note')) {
            $s->table('message_reports', function ($t) {
                $t->text('resolution_note')->nullable();
            });
        }
    }

    public function down(): void
    {
        $s = $this->schema();
        if ($s->hasColumn('message_reports', 'resolution_note')) {
            $s->table('message_reports', function ($t) {
                $t->dropColumn('resolution_note');
            });
        }
    }
}
