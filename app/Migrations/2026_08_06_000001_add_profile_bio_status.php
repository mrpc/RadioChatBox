<?php

namespace RadioChatBox\Migrations;

use Pramnos\Database\Migration;

/**
 * Adds an optional short bio and a custom status message to user profiles, shown
 * on the public profile card. Additive; runs after the create_schema baseline.
 */
final class AddProfileBioStatus extends Migration
{
    public $description = 'user_profiles.bio + status_message';
    public bool $transactional = true;
    public array $dependencies = ['create_schema'];

    public function up(): void
    {
        $s = $this->schema();
        if ($s->hasTable('user_profiles')) {
            if (!$s->hasColumn('user_profiles', 'bio')) {
                $s->table('user_profiles', function ($t) {
                    $t->string('bio', 300)->nullable();
                });
            }
            if (!$s->hasColumn('user_profiles', 'status_message')) {
                $s->table('user_profiles', function ($t) {
                    $t->string('status_message', 120)->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        $s = $this->schema();
        if ($s->hasTable('user_profiles')) {
            $s->table('user_profiles', function ($t) {
                $t->dropColumn('bio');
                $t->dropColumn('status_message');
            });
        }
    }
}
