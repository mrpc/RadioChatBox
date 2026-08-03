<?php

namespace RadioChatBox\Migrations;

use Pramnos\Database\Migration;

/**
 * Promo campaigns: scheduled promotional messages sent by fake users, either into
 * the public chat or as DMs to listeners, on an interval and within optional
 * active hours. DM campaigns respect a per-user cooldown and a per-run recipient
 * cap so people aren't spammed. Additive; after create_schema.
 */
final class CreatePromoCampaigns extends Migration
{
    public $description = 'promo_campaigns table (scheduled bot promo messages)';
    public bool $transactional = true;
    public array $dependencies = ['create_schema'];

    public function up(): void
    {
        $s = $this->schema();
        if (!$s->hasTable('promo_campaigns')) {
            $s->createTable('promo_campaigns', function ($t) {
                $t->increments('id');
                $t->string('name', 150);
                $t->text('message');
                $t->string('target', 10)->default('public');   // public | dm
                $t->integer('fake_user_id')->nullable();        // null = a random active bot
                $t->integer('interval_minutes')->default(60);
                $t->time('window_start')->nullable();           // active-hours window (local)
                $t->time('window_end')->nullable();
                $t->integer('cooldown_hours')->default(24);     // per-user DM cooldown
                $t->integer('max_recipients')->default(20);     // DM recipients per run
                $t->boolean('is_active')->default(true);
                $t->timestampTz('last_run_at')->nullable();
                $t->timestampTz('created_at')->useCurrent();

                $t->index(['is_active'], 'idx_promo_campaigns_active');
            });
        }
    }

    public function down(): void
    {
        $this->schema()->dropTableIfExists('promo_campaigns');
    }
}
