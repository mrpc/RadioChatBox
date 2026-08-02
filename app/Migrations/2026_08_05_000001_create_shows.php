<?php

namespace RadioChatBox\Migrations;

use Pramnos\Database\Migration;

/**
 * Radio show schedule: recurring (weekly on a given weekday at a time) or one-off
 * (a specific date) broadcasts, each with a host and description. Backs the
 * "upcoming shows" display and the admin schedule manager.
 *
 * Additive; runs after the create_schema baseline.
 */
final class CreateShows extends Migration
{
    public $description = 'shows table (radio show schedule)';
    public bool $transactional = true;
    public array $dependencies = ['create_schema'];

    public function up(): void
    {
        $s = $this->schema();

        if (!$s->hasTable('shows')) {
            $s->createTable('shows', function ($t) {
                $t->increments('id');
                $t->string('title', 200);
                $t->text('description')->nullable();
                $t->string('host', 150)->nullable();
                $t->boolean('is_recurring')->default(true);
                // Recurring: 0=Sunday .. 6=Saturday. One-off: NULL + show_date set.
                $t->integer('day_of_week')->nullable();
                $t->date('show_date')->nullable();      // one-off date
                $t->time('start_time');
                $t->time('end_time')->nullable();
                $t->boolean('is_active')->default(true);
                $t->timestampTz('created_at')->useCurrent();

                $t->index(['is_active'], 'idx_shows_active');
                $t->index(['day_of_week'], 'idx_shows_dow');
                $t->index(['show_date'], 'idx_shows_date');
            });
        }
    }

    public function down(): void
    {
        $this->schema()->dropTableIfExists('shows');
    }
}
