<?php

/**
 * PramnosFramework application descriptor.
 *
 * The single place that declares RadioChatBox's framework wiring: its identity,
 * the global HTTP middleware applied around every route, and how migrations
 * auto-run. Read by the framework via Application::getInstance(); RadioChatBox
 * ships no Application subclass, so the framework instantiates its base kernel
 * (Pramnos\Application\Application). The HTTP kernel (bootstrap/http.php) applies
 * the declared middleware and triggers the auto-migration on every request.
 *
 * @return array<string,mixed>
 */

return [
    'name'      => 'RadioChatBox',
    'namespace' => 'RadioChatBox',

    // Schema convergence (Phase B): adopt the framework's users/auth/settings/
    // messaging/queue schema. `core` is always-on; these add the auth stack
    // (users companions, usertokens, RBAC, 2FA/passkey), messaging and queue.
    // RCB's colliding tables are renamed (messages→chat_messages,
    // sessions→presence_sessions) or converged in place (users, settings) by the
    // app migrations, which run at priority<10 so they precede the framework
    // create_* migrations (see app/Migrations/2026_08_01_*).
    'features'  => ['auth', 'authserver', 'messaging', 'queue'],

    // Global HTTP middleware, applied by bootstrap/http.php around every route.
    // CORS reflects the request Origin against ALLOWED_ORIGINS (comma-separated;
    // '*' by default); JSON response shaping matches the legacy behaviour. NOTE:
    // NO SessionTrackingMiddleware — RadioChatBox owns its own `sessions` table
    // (username/session_id/last_heartbeat), which is incompatible with the
    // framework's legacy sessions schema, so session tracking is deliberately
    // NOT wired (see docs/ARCHITECTURE.md §3).
    'middleware' => [
        new \Pramnos\Http\Middleware\CorsMiddleware(
            explode(',', (string) envvar('ALLOWED_ORIGINS', '*')),
            ['GET', 'POST', 'OPTIONS'],
            ['Content-Type'],
            true
        ),
        \Pramnos\Http\Middleware\JsonResponseMiddleware::class,
    ],

    // Auto-migration on every execution (web + console), via the framework
    // fingerprint fast-path. Scan ONLY the app's own migrations — NOT the
    // framework feature dirs (Stage 2 of the schema convergence flips this on).
    // The cutoff is cleared: the schema-convergence migrations declare
    // dependencies on the `create_schema` baseline, and MigrationRunner::sort()
    // honours dependencies within a batch (Kahn's algorithm) over priority — but
    // only when the baseline is in the SAME batch. A floor cutoff would isolate
    // a post-baseline migration into its own auto-run batch (baseline excluded),
    // where its dependency is unresolved and it can run against a not-yet-created
    // schema. Keeping the cutoff empty lets auto-run see the baseline + all
    // convergence migrations together, so they always run in dependency order.
    'migrations' => [
        'paths'     => [__DIR__ . '/Migrations'],
        'framework' => true,
    ],
    'migration_cutoff' => '',
];
