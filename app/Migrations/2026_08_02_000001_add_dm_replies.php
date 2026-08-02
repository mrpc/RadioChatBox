<?php

namespace RadioChatBox\Migrations;

use Pramnos\Database\Migration;

/**
 * Bring the public-chat reply feature to direct messages: a nullable
 * `reply_to_id` on private_messages pointing at another private_messages row.
 *
 * DM reactions need NO schema change — they reuse the existing `message_reactions`
 * table (string message_id, no FK) with a `pm_<id>` key, distinct from public
 * message ids.
 *
 * Additive and idempotent; runs after the create_schema baseline created the
 * table (dependency + later timestamp).
 */
final class AddDmReplies extends Migration
{
    public $description = 'DM replies: private_messages.reply_to_id (reactions reuse message_reactions)';
    public bool $transactional = true;
    public array $dependencies = ['create_schema'];

    public function up(): void
    {
        $s = $this->schema();
        if (!$s->hasColumn('private_messages', 'reply_to_id')) {
            $this->DB()->statement('ALTER TABLE private_messages ADD COLUMN reply_to_id integer;');
            $this->DB()->statement('CREATE INDEX IF NOT EXISTS idx_pm_reply_to ON private_messages(reply_to_id);');
        }
    }

    public function down(): void
    {
        $this->DB()->statement('DROP INDEX IF EXISTS idx_pm_reply_to;');
        $this->DB()->statement('ALTER TABLE private_messages DROP COLUMN IF EXISTS reply_to_id;');
    }
}
