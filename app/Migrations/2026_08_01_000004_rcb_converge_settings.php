<?php

namespace RadioChatBox\Migrations;

use Pramnos\Database\Migration;

/**
 * Stage 3 of the PF schema convergence: reshape RCB's `settings` table in place
 * to the framework shape (setting_id / setting / value / delete), so the
 * framework create_settings_table becomes a hasTable() skip and its
 * add_unique_constraint_to_settings_table (which needs a `setting` column) runs.
 *
 * RCB's `updated_at` / `created_at` columns are RETAINED (the framework tolerates
 * extra columns) so SettingsService::versionStamp()'s max(updated_at) fallback
 * keeps working with no code change; SettingsService's SQL is repointed to
 * setting/value in the same release.
 *
 * priority 5 keeps this ahead of the framework settings migrations (10/20/21).
 */
final class RcbConvergeSettings extends Migration
{
    public $description = 'Converge settings to the framework shape in place (setting_key→setting, +delete)';
    public bool $transactional = true;
    public int $priority = 5;
    public array $dependencies = ['create_schema'];

    public function up(): void
    {
        $s  = $this->schema();
        $db = $this->DB();

        // Idempotency: already converged.
        if ($s->hasColumn('settings', 'setting')) {
            return;
        }

        // Rename to framework columns (the serial/PK and the unique/plain indexes
        // follow the column rename automatically in PostgreSQL).
        $db->statement('ALTER TABLE settings RENAME COLUMN id            TO setting_id;');
        $db->statement('ALTER TABLE settings RENAME COLUMN setting_key   TO setting;');
        $db->statement('ALTER TABLE settings RENAME COLUMN setting_value TO value;');

        // Widen `setting` to the framework width and make `value` NOT NULL.
        $db->statement('ALTER TABLE settings ALTER COLUMN setting TYPE varchar(128);');
        $db->statement("UPDATE settings SET value = '' WHERE value IS NULL;");
        $db->statement('ALTER TABLE settings ALTER COLUMN value SET NOT NULL;');

        // Framework `delete` flag (reserved word → quoted). Framework default is 1.
        $db->statement('ALTER TABLE settings ADD COLUMN IF NOT EXISTS "delete" smallint NOT NULL DEFAULT 1;');

        // Drop RCB's old unique on the key; the framework's
        // add_unique_constraint_to_settings_table creates uq_settings_name.
        $db->statement('ALTER TABLE settings DROP CONSTRAINT IF EXISTS settings_setting_key_key;');
        $db->statement('DROP INDEX IF EXISTS idx_settings_key_value;');
    }

    public function down(): void
    {
        $s  = $this->schema();
        $db = $this->DB();
        if (!$s->hasColumn('settings', 'setting')) {
            return;
        }
        $db->statement('ALTER TABLE settings DROP COLUMN IF EXISTS "delete";');
        $db->statement('ALTER TABLE settings ALTER COLUMN value DROP NOT NULL;');
        $db->statement('ALTER TABLE settings RENAME COLUMN value   TO setting_value;');
        $db->statement('ALTER TABLE settings RENAME COLUMN setting TO setting_key;');
        $db->statement('ALTER TABLE settings RENAME COLUMN setting_id TO id;');
    }
}
