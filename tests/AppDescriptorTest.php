<?php

namespace RadioChatBox\Tests;

use Pramnos\Framework\Testing\TestDatabase;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Http\Middleware\CorsMiddleware;
use Pramnos\Http\Middleware\JsonResponseMiddleware;
use Pramnos\Database\Database;

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
     * The descriptor identifies the app and enables the framework features adopted
     * by the schema convergence (auth/authserver/messaging/queue).
     */
    public function testIdentityAndFeatures(): void
    {
        $app = $this->descriptor();

        $this->assertSame('RadioChatBox', $app['namespace']);
        $this->assertSame(
            ['auth', 'authserver', 'messaging', 'queue'],
            $app['features'],
            'Schema convergence adopts the auth/authserver/messaging/queue features'
        );
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
     * Auto-migration includes the app's own Migrations directory plus the
     * framework feature dirs (the schema convergence enabled them), and the
     * cutoff is cleared so auto-run sees the baseline and its dependent
     * convergence migrations in the same batch — MigrationRunner's topological
     * sort then orders them by dependency rather than isolating a post-baseline
     * migration into its own batch.
     */
    public function testMigrationsAreAppScopedWithClearedCutoff(): void
    {
        $app = $this->descriptor();

        $this->assertTrue($app['migrations']['framework'], 'Framework migration dirs must be on (convergence)');
        $this->assertSame(
            [realpath(__DIR__ . '/../app/Migrations')],
            array_map('realpath', $app['migrations']['paths'])
        );
        $this->assertSame('', $app['migration_cutoff']);
    }

    /**
     * migrate() is safe to call on every execution: it never throws and is
     * idempotent, and — the load-bearing invariant — the app's presence table
     * keeps its own schema. Stage 1 of the convergence renamed the app's
     * `sessions` table to `presence_sessions` (freeing the `sessions` name for
     * the framework); that table keeps its columns (session_id/last_heartbeat),
     * and the framework's legacy visitor-tracking `sessions` schema
     * (visitorid/sid) is absent while framework migrations stay off.
     */
    public function testMigrateIsSafeAndLeavesAppSessionsSchemaIntact(): void
    {
        $app = Application::getInstance();

        $app->migrate();
        $app->migrate(); // idempotent per instance — must not throw or change anything

        $pdo = TestDatabase::connection();
        $columns = $pdo->query(
            "SELECT column_name FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = 'presence_sessions'"
        )->fetchAll(\PDO::FETCH_COLUMN);

        // App presence schema — present under the new name.
        $this->assertContains('session_id', $columns);
        $this->assertContains('last_heartbeat', $columns);
        // Framework legacy visitor-tracking schema — must be absent (framework off).
        $this->assertNotContains('visitorid', $columns);
        $this->assertNotContains('sid', $columns);
    }
}
