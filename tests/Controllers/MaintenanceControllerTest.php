<?php

namespace RadioChatBox\Tests\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Http\Response;
use RadioChatBox\Controllers\MaintenanceController;

/**
 * Golden-contract tests for the migrated Maintenance endpoints (replaced
 * public/api/health.php and public/api/cron/cleanup.php). Cover the health
 * payload shape / status semantics and the cron token gate. The cron success
 * path is intentionally NOT exercised because runAll() performs destructive
 * deletes against the shared dev DB; only the deterministic 401 path is tested.
 */
class MaintenanceControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];
    }

    /**
     * GET /api/health returns a Response whose body carries the exact contract
     * keys (status, timestamp, services{redis,postgresql,php}), reports php as up,
     * and whose HTTP status matches the overall health flag (200 healthy /
     * 503 unhealthy). This is a read-only probe so it is safe to assert live.
     */
    public function testHealthReturnsContractShape(): void
    {
        $response = (new MaintenanceController())->health();

        $this->assertInstanceOf(Response::class, $response);
        $body = json_decode($response->getBody(), true);

        $this->assertArrayHasKey('status', $body);
        $this->assertArrayHasKey('timestamp', $body);
        $this->assertArrayHasKey('services', $body);
        $this->assertArrayHasKey('redis', $body['services']);
        $this->assertArrayHasKey('postgresql', $body['services']);
        $this->assertArrayHasKey('php', $body['services']);

        // PHP is always reported up with a version string.
        $this->assertSame('up', $body['services']['php']['status']);
        $this->assertSame(PHP_VERSION, $body['services']['php']['version']);

        // Overall status flag drives the HTTP status code.
        $this->assertContains($body['status'], ['healthy', 'unhealthy']);
        $expectedCode = $body['status'] === 'healthy' ? 200 : 503;
        $this->assertSame($expectedCode, $response->getStatusCode());
    }

    /**
     * GET /api/cron/cleanup with a token that does not match CRON_TOKEN is
     * rejected with 401 {"error":"Unauthorized"} and never runs the cleanup.
     * A deliberately bogus token guarantees a mismatch regardless of environment.
     */
    public function testCronCleanupRejectsBadToken(): void
    {
        $_GET = ['token' => 'definitely-not-the-cron-token-' . uniqid()];

        $response = (new MaintenanceController())->cronCleanup();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Unauthorized', json_decode($response->getBody(), true)['error']);
    }

    /**
     * A missing token query param is treated as an empty string, which cannot
     * match the configured CRON_TOKEN, so the endpoint also returns 401.
     */
    public function testCronCleanupMissingTokenReturns401(): void
    {
        $_GET = [];

        $response = (new MaintenanceController())->cronCleanup();

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Unauthorized', json_decode($response->getBody(), true)['error']);
    }
}
