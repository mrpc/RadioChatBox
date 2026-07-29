<?php

namespace RadioChatBox\Tests\Controllers;

use Pramnos\Framework\Testing\TestDatabase;

use PHPUnit\Framework\TestCase;
use Pramnos\Http\Response;
use Pramnos\Database\Database;
use RadioChatBox\Services\SettingsService;
use RadioChatBox\Controllers\SendController;

/**
 * Golden-contract test for the migrated POST /api/send endpoint (replaced
 * public/api/send.php). Verifies the exception -> HTTP status mapping that the
 * legacy endpoint had: empty/invalid body -> 400, public-chat-disabled -> 429.
 * (The success path mutates state + rate-limits, so it is exercised live/by the
 * frontend rather than asserted here.)
 */
class SendControllerTest extends TestCase
{
    private ?string $prevChatMode = null;

    protected function setUp(): void
    {
        $pdo = TestDatabase::connection();
        $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $stmt->execute(['chat_mode']);
        $v = $stmt->fetchColumn();
        $this->prevChatMode = $v === false ? null : (string) $v;
    }

    protected function tearDown(): void
    {
        $pdo = TestDatabase::connection();
        if ($this->prevChatMode === null) {
            $pdo->prepare('DELETE FROM settings WHERE setting_key = ?')->execute(['chat_mode']);
        } else {
            $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value')
                ->execute(['chat_mode', $this->prevChatMode]);
        }
        (new SettingsService())->invalidateCache();
        $_POST = [];
    }

    public function testEmptyBodyReturns400(): void
    {
        $_POST = [];
        $response = (new SendController())->store();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Invalid JSON', json_decode($response->getBody(), true)['error']);
    }

    public function testPublicChatDisabledReturns429(): void
    {
        TestDatabase::connection()->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
            ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value')
            ->execute(['chat_mode', 'private']);
        (new SettingsService())->invalidateCache();

        $_POST = ['username' => 'u', 'message' => 'hi', 'sessionId' => 's'];
        $response = (new SendController())->store();

        $this->assertSame(429, $response->getStatusCode());
    }
}
