<?php

namespace RadioChatBox\Migrations;

use Pramnos\Database\Migration;

/**
 * Record the browser/device user agent on each presence session, so the admin
 * user dossier can show what device an active user is on (browser, OS, mobile
 * vs desktop). Captured on the heartbeat, which every live client sends.
 *
 * Additive + nullable (old rows and non-heartbeat paths simply carry NULL).
 */
final class AddSessionUserAgent extends Migration
{
    public $description = 'presence_sessions.user_agent';
    public bool $transactional = true;
    public array $dependencies = ['create_schema'];

    public function up(): void
    {
        $s = $this->schema();
        if (!$s->hasColumn('presence_sessions', 'user_agent')) {
            $s->table('presence_sessions', function ($t) {
                $t->text('user_agent')->nullable();
            });
        }
    }

    public function down(): void
    {
        $s = $this->schema();
        if ($s->hasColumn('presence_sessions', 'user_agent')) {
            $s->table('presence_sessions', function ($t) {
                $t->dropColumn('user_agent');
            });
        }
    }
}
