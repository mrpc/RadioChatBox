<?php

namespace RadioChatBox\Migrations;

use Pramnos\Database\Migration;

/**
 * Speed up the admin photo list. The gallery reads the newest non-deleted
 * attachments ("WHERE is_deleted IS NOT TRUE ORDER BY uploaded_at DESC"); on a
 * long-lived, soft-delete-bloated table the plain uploaded_at index still has to
 * filter deleted rows. A partial index that already excludes deleted rows serves
 * the list directly, and — paired with a cached total in PhotoService — keeps the
 * page responsive as the table grows.
 *
 * Additive and idempotent; runs after the create_schema baseline.
 */
final class AttachmentsActiveIndex extends Migration
{
    public $description = 'Partial index on attachments(uploaded_at DESC) WHERE is_deleted IS NOT TRUE';
    public bool $transactional = true;
    public array $dependencies = ['create_schema'];

    public function up(): void
    {
        $s = $this->schema();
        $s->table('attachments', function ($t) {
            $t->index(['uploaded_at DESC'], 'idx_attachments_active_uploaded')
                ->where('is_deleted IS NOT TRUE');
        });
    }

    public function down(): void
    {
        $s = $this->schema();
        $s->table('attachments', function ($t) {
            $t->dropIndex('idx_attachments_active_uploaded');
        });
    }
}
