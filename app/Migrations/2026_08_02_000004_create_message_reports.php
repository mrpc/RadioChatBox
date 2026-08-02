<?php

namespace RadioChatBox\Migrations;

use Pramnos\Database\Migration;

/**
 * User-driven abuse reporting: a user can flag a message/user, and admins work a
 * queue of pending reports with quick actions. One row per report; the reported
 * content is snapshotted so it survives deletion of the original message.
 *
 * Additive; runs after the create_schema baseline.
 */
final class CreateMessageReports extends Migration
{
    public $description = 'message_reports table (user abuse reports queue)';
    public bool $transactional = true;
    public array $dependencies = ['create_schema'];

    public function up(): void
    {
        $s = $this->schema();
        if ($s->hasTable('message_reports')) {
            return;
        }

        $s->createTable('message_reports', function ($t) {
            $t->increments('id');
            $t->string('message_id', 255)->nullable();
            $t->string('message_type', 20)->default('public'); // public | private
            $t->string('reported_username', 100)->nullable();
            $t->string('reporter_username', 100)->nullable();
            $t->string('reporter_session_id', 255)->nullable();
            $t->string('reason', 50);                          // category slug
            $t->text('details')->nullable();                   // optional free text
            $t->text('content_snapshot')->nullable();          // the reported message text
            $t->string('status', 20)->default('pending');      // pending | resolved | dismissed
            $t->string('resolved_by', 100)->nullable();
            $t->timestampTz('created_at')->useCurrent();
            $t->timestampTz('resolved_at')->nullable();

            $t->index(['status'], 'idx_message_reports_status');
            $t->index(['reported_username'], 'idx_message_reports_reported');
            $t->index(['created_at DESC'], 'idx_message_reports_created');
        });
    }

    public function down(): void
    {
        $this->schema()->dropTableIfExists('message_reports');
    }
}
