<?php

namespace RadioChatBox\Migrations;

use Pramnos\Database\Migration;

/**
 * Password-reset tokens: one row per issued reset. The token itself is only ever
 * stored HASHED (the plaintext lives solely in the emailed link); rows are
 * single-use and short-lived. Additive; after create_schema.
 */
final class CreatePasswordResets extends Migration
{
    public $description = 'password_resets table (self-service password reset)';
    public bool $transactional = true;
    public array $dependencies = ['create_schema'];

    public function up(): void
    {
        $s = $this->schema();
        if (!$s->hasTable('password_resets')) {
            $s->createTable('password_resets', function ($t) {
                $t->increments('id');
                $t->integer('userid');
                $t->string('token_hash', 64);   // sha256 hex of the token
                $t->timestampTz('expires_at');
                $t->timestampTz('used_at')->nullable();
                $t->timestampTz('created_at')->useCurrent();

                $t->unique(['token_hash'], 'uq_password_resets_token');
                $t->index(['userid'], 'idx_password_resets_user');
            });
        }
    }

    public function down(): void
    {
        $this->schema()->dropTableIfExists('password_resets');
    }
}
