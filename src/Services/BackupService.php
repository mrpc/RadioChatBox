<?php

namespace RadioChatBox\Services;

use Pramnos\Database\Database as PramnosDatabase;

/**
 * Configuration backup: exports the station's operational configuration — the
 * settings plus the tables that are painful to recreate by hand (fake users,
 * show schedule, promo campaigns, URL allow/deny lists, bans, chat commands) —
 * into a single JSON bundle for safe-keeping / migration. Bulk data (messages,
 * stats) is intentionally excluded; this is a config snapshot, not a full dump.
 */
class BackupService
{
    /** Tables included in a configuration backup (in dependency-friendly order). */
    private const TABLES = [
        'settings',
        'fake_users',
        'shows',
        'promo_campaigns',
        'url_whitelist',
        'url_blacklist',
        'banned_nicknames',
        'banned_ips',
        'chat_commands',
    ];

    private PramnosDatabase $db;

    public function __construct()
    {
        $this->db = PramnosDatabase::getInstance();
    }

    /**
     * Build the configuration bundle. `generated_at` is left null for the caller
     * to stamp (services have no clock in tests).
     *
     * @return array{version:int, generated_at:?string, tables:array<string, array<int, array<string,mixed>>>}
     */
    public function export(): array
    {
        $tables = [];
        foreach (self::TABLES as $table) {
            $tables[$table] = $this->dumpTable($table);
        }
        return ['version' => 1, 'generated_at' => null, 'tables' => $tables];
    }

    /** @return array<int, array<string,mixed>> */
    private function dumpTable(string $table): array
    {
        try {
            if (!$this->tableExists($table)) {
                return [];
            }
            $rows = $this->db->queryBuilder()->from($table)->getAll();
            // Never export bot API keys or similar secrets verbatim in the settings
            // table — redact obvious secret-bearing keys.
            if ($table === 'settings') {
                foreach ($rows as &$row) {
                    $key = (string) ($row['setting'] ?? '');
                    if ($this->isSecretSetting($key)) {
                        $row['value'] = '***REDACTED***';
                    }
                }
                unset($row);
            }
            return $rows;
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log("BackupService: could not dump {$table}: " . $e->getMessage(), 'radiochatbox');
            return [];
        }
        // @codeCoverageIgnoreEnd
    }

    private function tableExists(string $table): bool
    {
        try {
            $this->db->query('SELECT 1 FROM ' . $table . ' LIMIT 1');
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Whether a settings key holds a secret that must be redacted from a backup. */
    private function isSecretSetting(string $key): bool
    {
        $key = strtolower($key);
        foreach (['api_key', 'password', 'secret', 'token', 'admin_key'] as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }
        return false;
    }
}
