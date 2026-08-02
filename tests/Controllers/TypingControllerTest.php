<?php

namespace RadioChatBox\Tests\Controllers;

use PDO;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\FlatCache;
use Pramnos\Framework\Testing\TestDatabase;
use RadioChatBox\Controllers\TypingController;
use RadioChatBox\Services\SettingsService;

/**
 * Contract tests for the typing-indicator ping endpoint: the feature gate,
 * required fields, and session verification. The broadcast itself is a
 * best-effort side effect and is not asserted here.
 */
class TypingControllerTest extends TestCase
{
    private PDO $pdo;
    private string $user;
    private string $session;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::connection();
        $suffix = substr(bin2hex(random_bytes(5)), 0, 8);
        $this->user = 'typer_' . $suffix;
        $this->session = 'tsess_' . $suffix;

        $this->pdo->prepare(
            'INSERT INTO presence_sessions (username, session_id, ip_address, last_heartbeat, joined_at)
             VALUES (?, ?, ?, NOW(), NOW())'
        )->execute([$this->user, $this->session, '127.0.0.1']);
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare('DELETE FROM presence_sessions WHERE username = ?')->execute([$this->user]);
        $this->pdo->prepare("DELETE FROM settings WHERE setting = 'typing_indicators_enabled'")->execute();
        FlatCache::default()->clear();
        $_POST = [];
    }

    private function enable(): void
    {
        (new SettingsService())->set('typing_indicators_enabled', 'true');
    }

    /** Off by default -> 404. */
    public function testDisabledReturns404(): void
    {
        $_POST = ['username' => $this->user, 'session_id' => $this->session];
        $this->assertSame(404, (new TypingController())->ping()->getStatusCode());
    }

    /** Missing fields -> 400. */
    public function testMissingFieldsReturn400(): void
    {
        $this->enable();
        $_POST = ['username' => $this->user];
        $this->assertSame(400, (new TypingController())->ping()->getStatusCode());
    }

    /** A session that is not the caller's -> 403. */
    public function testInvalidSessionReturns403(): void
    {
        $this->enable();
        $_POST = ['username' => $this->user, 'session_id' => 'not-mine'];
        $this->assertSame(403, (new TypingController())->ping()->getStatusCode());
    }

    /** A verified session -> 200. */
    public function testValidPingSucceeds(): void
    {
        $this->enable();
        $_POST = ['username' => $this->user, 'session_id' => $this->session, 'is_typing' => 'true'];

        $response = (new TypingController())->ping();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue(json_decode($response->getBody(), true)['success']);
    }
}
