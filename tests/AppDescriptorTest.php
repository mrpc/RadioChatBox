<?php

namespace RadioChatBox\Tests;

use Pramnos\Framework\Testing\TestDatabase;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Http\Middleware\CorsMiddleware;
use Pramnos\Http\Middleware\JsonResponseMiddleware;
use RadioChatBox\Database;

/**
 * Covers the #12 app descriptor + auto-migration wiring.
 *
 * app/app.php is the single place that declares RadioChatBox's framework wiring
 * (identity, global middleware, migrations). These tests pin (a) the descriptor
 * shape the front controller and console rely on, and (b) the critical
 * invariant that the standalone auto-migration NEVER runs the framework feature
 * migrations — whose legacy `sessions`/`settings` schema would collide with
 * RadioChatBox's own — because the descriptor opts out with framework => false.
 */
class AppDescriptorTest extends TestCase
{
    /**
     * @return array<string,mixed>
     */
    private function descriptor(): array
    {
        return require __DIR__ . '/../app/app.php';
    }

    /**
     * The descriptor identifies the app and enables NO framework features, so no
     * framework feature migrations (auth/messaging/queue/...) are ever in scope.
     */
    public function testIdentityAndNoFeatures(): void
    {
        $app = $this->descriptor();

        $this->assertSame('RadioChatBox', $app['namespace']);
        $this->assertSame([], $app['features'], 'No framework features may be enabled');
    }

    /**
     * The global middleware stack is declared here (not hard-coded in the front
     * controller): a configured CORS instance plus the JSON response shaper — and
     * crucially NO SessionTrackingMiddleware, since RadioChatBox owns its own
     * sessions table.
     */
    public function testMiddlewareStackIsDeclaredAndExcludesSessionTracking(): void
    {
        $middleware = $this->descriptor()['middleware'];

        $this->assertInstanceOf(CorsMiddleware::class, $middleware[0]);
        $this->assertContains(JsonResponseMiddleware::class, $middleware);

        foreach ($middleware as $entry) {
            $name = is_object($entry) ? $entry::class : (string) $entry;
            $this->assertStringNotContainsString(
                'SessionTracking',
                $name,
                'SessionTrackingMiddleware must never be wired (RCB owns its sessions table)'
            );
        }
    }

    /**
     * Auto-migration is scoped to the app's own Migrations directory with the
     * framework feature dirs switched off, and the cutoff is set to the baseline
     * timestamp so auto-run only ever applies genuinely new, post-baseline
     * migrations (the baseline itself is applied by the explicit `rcb migrate`).
     */
    public function testMigrationsAreAppScopedWithBaselineCutoff(): void
    {
        $app = $this->descriptor();

        $this->assertFalse($app['migrations']['framework'], 'Framework migration dirs must be off');
        $this->assertSame(
            [realpath(__DIR__ . '/../app/Migrations')],
            array_map('realpath', $app['migrations']['paths'])
        );
        $this->assertSame('2025-01-01 00:00:01', $app['migration_cutoff']);
    }

    /**
     * migrate() is safe to call on every execution: it never throws and is
     * idempotent, and — the load-bearing invariant — it does NOT create the
     * framework's legacy `sessions` schema. The app's own sessions table keeps
     * its columns (session_id/last_heartbeat), proving the framework
     * create_sessions migration never ran.
     */
    public function testMigrateIsSafeAndLeavesAppSessionsSchemaIntact(): void
    {
        $app = Application::getInstance();

        $app->migrate();
        $app->migrate(); // idempotent per instance — must not throw or change anything

        $pdo = TestDatabase::connection();
        $columns = $pdo->query(
            "SELECT column_name FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = 'sessions'"
        )->fetchAll(\PDO::FETCH_COLUMN);

        // App schema — present.
        $this->assertContains('session_id', $columns);
        $this->assertContains('last_heartbeat', $columns);
        // Framework legacy schema — must be absent (its create_sessions never ran).
        $this->assertNotContains('visitorid', $columns);
        $this->assertNotContains('sid', $columns);
    }
}
