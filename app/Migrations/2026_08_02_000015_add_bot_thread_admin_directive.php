<?php

namespace RadioChatBox\Migrations;

use Pramnos\Database\Migration;

/**
 * Admin steering: a free-text directive an admin can attach to a bot thread
 * (e.g. "steer the conversation towards X"). It is injected into the system
 * prompt for that thread's replies until cleared. One column on bot_threads.
 *
 * Additive + nullable; runs after the create_schema baseline.
 */
final class AddBotThreadAdminDirective extends Migration
{
    public $description = 'bot_threads.admin_directive (admin steering)';
    public bool $transactional = true;
    public array $dependencies = ['create_schema'];

    public function up(): void
    {
        $s = $this->schema();
        if (!$s->hasTable('bot_threads') || $s->hasColumn('bot_threads', 'admin_directive')) {
            return;
        }
        $s->table('bot_threads', function ($t) {
            $t->text('admin_directive')->nullable();
        });
    }

    public function down(): void
    {
        $s = $this->schema();
        if ($s->hasTable('bot_threads') && $s->hasColumn('bot_threads', 'admin_directive')) {
            $s->table('bot_threads', function ($t) {
                $t->dropColumn('admin_directive');
            });
        }
    }
}
