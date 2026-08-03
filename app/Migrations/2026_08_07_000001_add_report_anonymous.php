<?php

namespace RadioChatBox\Migrations;

use Pramnos\Database\Migration;

/**
 * Lets a reporter file a report anonymously: when set, the admin queue shows
 * "anonymous" instead of the reporter's name (the name is still stored so repeat
 * abuse of the report system can be detected). Additive; after create_schema.
 */
final class AddReportAnonymous extends Migration
{
    public $description = 'message_reports.is_anonymous';
    public bool $transactional = true;
    public array $dependencies = ['create_schema'];

    public function up(): void
    {
        $s = $this->schema();
        if ($s->hasTable('message_reports') && !$s->hasColumn('message_reports', 'is_anonymous')) {
            $s->table('message_reports', function ($t) {
                $t->boolean('is_anonymous')->default(false);
            });
        }
    }

    public function down(): void
    {
        $s = $this->schema();
        if ($s->hasTable('message_reports') && $s->hasColumn('message_reports', 'is_anonymous')) {
            $s->table('message_reports', function ($t) {
                $t->dropColumn('is_anonymous');
            });
        }
    }
}
