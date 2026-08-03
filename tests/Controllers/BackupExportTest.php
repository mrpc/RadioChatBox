<?php

namespace RadioChatBox\Tests\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Framework\Testing\TestDatabase;
use RadioChatBox\Controllers\AdminSystemController;
use RadioChatBox\Services\BackupService;
use RadioChatBox\Services\SettingsService;

/**
 * Configuration backup: the bundle includes the config tables, redacts secret
 * settings, and the admin endpoint serves it as a JSON download.
 */
class BackupExportTest extends TestCase
{
    protected function setUp(): void
    {
        TestDatabase::connection();
    }

    protected function tearDown(): void
    {
        TestDatabase::connection()->prepare("DELETE FROM settings WHERE setting IN ('backup_probe', 'bot_llm_api_key')")->execute();
        $_GET = [];
    }

    /** The bundle has the expected shape and includes the settings table. */
    public function testExportShape(): void
    {
        (new SettingsService())->set('backup_probe', 'hello');
        $bundle = (new BackupService())->export();

        $this->assertSame(1, $bundle['version']);
        $this->assertArrayHasKey('tables', $bundle);
        $this->assertArrayHasKey('settings', $bundle['tables']);
        $this->assertArrayHasKey('fake_users', $bundle['tables']);
        $this->assertArrayHasKey('promo_campaigns', $bundle['tables']);

        $settings = array_column($bundle['tables']['settings'], 'value', 'setting');
        $this->assertSame('hello', $settings['backup_probe'] ?? null);
    }

    /** Secret-bearing settings are redacted in the backup. */
    public function testSecretsRedacted(): void
    {
        (new SettingsService())->set('bot_llm_api_key', 'sk-supersecret');
        $bundle = (new BackupService())->export();
        $settings = array_column($bundle['tables']['settings'], 'value', 'setting');
        $this->assertSame('***REDACTED***', $settings['bot_llm_api_key'] ?? null);
    }

    /** The admin endpoint serves the bundle as a JSON download. */
    public function testEndpointServesDownload(): void
    {
        $response = (new AdminSystemController())->backupExport();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('application/json', (string) $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('attachment; filename="radiochatbox-config-', (string) $response->getHeaderLine('Content-Disposition'));
        $decoded = json_decode($response->getBody(), true);
        $this->assertNotNull($decoded['generated_at']);
        $this->assertArrayHasKey('settings', $decoded['tables']);
    }
}
