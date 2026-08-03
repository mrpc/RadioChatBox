<?php

namespace RadioChatBox\Migrations;

use Pramnos\Database\Migration;

/**
 * Email verification for accounts: a nullable `email_verified_at` on users plus a
 * single-use, short-lived verification-token table (token stored hashed, like the
 * password-reset tokens). Additive; after create_schema.
 */
final class AddEmailVerification extends Migration
{
    public $description = 'users.email_verified_at + email_verifications table';
    public bool $transactional = true;
    public array $dependencies = ['create_schema'];

    public function up(): void
    {
        $s = $this->schema();

        if ($s->hasTable('users') && !$s->hasColumn('users', 'email_verified_at')) {
            $s->table('users', function ($t) {
                $t->timestampTz('email_verified_at')->nullable();
            });
        }

        if (!$s->hasTable('email_verifications')) {
            $s->createTable('email_verifications', function ($t) {
                $t->increments('id');
                $t->integer('userid');
                $t->string('token_hash', 64);
                $t->timestampTz('expires_at');
                $t->timestampTz('used_at')->nullable();
                $t->timestampTz('created_at')->useCurrent();

                $t->unique(['token_hash'], 'uq_email_verifications_token');
                $t->index(['userid'], 'idx_email_verifications_user');
            });
        }
    }

    public function down(): void
    {
        $s = $this->schema();
        $s->dropTableIfExists('email_verifications');
        if ($s->hasTable('users') && $s->hasColumn('users', 'email_verified_at')) {
            $s->table('users', function ($t) {
                $t->dropColumn('email_verified_at');
            });
        }
    }
}
