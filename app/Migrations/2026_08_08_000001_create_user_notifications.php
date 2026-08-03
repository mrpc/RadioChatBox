<?php

namespace RadioChatBox\Migrations;

use Pramnos\Database\Migration;

/**
 * Per-user notification inbox: a persistent history of events directed at a user
 * (someone reacted to your message, mentioned you, etc.) with read/unread state.
 * Distinct from the admin-only `admin_notifications`. Additive; after
 * create_schema.
 */
final class CreateUserNotifications extends Migration
{
    public $description = 'user_notifications table (per-user notification inbox)';
    public bool $transactional = true;
    public array $dependencies = ['create_schema'];

    public function up(): void
    {
        $s = $this->schema();
        if (!$s->hasTable('user_notifications')) {
            $s->createTable('user_notifications', function ($t) {
                $t->increments('id');
                $t->string('username', 100);        // recipient
                $t->string('type', 40);             // reaction | mention | system | ...
                $t->string('title', 200);
                $t->string('body', 500)->nullable();
                $t->string('link', 255)->nullable(); // e.g. a message_id to jump to
                $t->boolean('is_read')->default(false);
                $t->timestampTz('created_at')->useCurrent();

                $t->index(['username', 'is_read'], 'idx_user_notifications_user_read');
                $t->index(['username', 'created_at DESC'], 'idx_user_notifications_user_created');
            });
        }
    }

    public function down(): void
    {
        $this->schema()->dropTableIfExists('user_notifications');
    }
}
