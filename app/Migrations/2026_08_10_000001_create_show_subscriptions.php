<?php

namespace RadioChatBox\Migrations;

use Pramnos\Database\Migration;

/**
 * Lets listeners subscribe to a show and get an in-app reminder shortly before it
 * airs. One row per (user, show). Additive; after create_schema and the shows
 * table.
 */
final class CreateShowSubscriptions extends Migration
{
    public $description = 'show_subscriptions table (favourite-show reminders)';
    public bool $transactional = true;
    public array $dependencies = ['create_schema'];

    public function up(): void
    {
        $s = $this->schema();
        if (!$s->hasTable('show_subscriptions')) {
            $s->createTable('show_subscriptions', function ($t) {
                $t->increments('id');
                $t->string('username', 100);
                $t->integer('show_id');
                $t->timestampTz('created_at')->useCurrent();

                $t->unique(['username', 'show_id'], 'uq_show_subscriptions_user_show');
                $t->index(['show_id'], 'idx_show_subscriptions_show');
            });
        }
    }

    public function down(): void
    {
        $this->schema()->dropTableIfExists('show_subscriptions');
    }
}
