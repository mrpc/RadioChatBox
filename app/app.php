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

    // No framework features are enabled: RadioChatBox provides its own auth,
    // sessions, messaging and queue schemas, so none of the framework feature
    // migrations (auth/authserver/messaging/queue/...) should run here.
    'features'  => [],

    // Global HTTP middleware, applied by public/_dispatch.php around every route.
    // CORS reflects the request Origin against ALLOWED_ORIGINS (comma-separated;
    // '*' by default); JSON response shaping matches the legacy behaviour. NOTE:
    // NO SessionTrackingMiddleware — RadioChatBox owns its own `sessions` table
    // (username/session_id/last_heartbeat), which is incompatible with the
    // framework's legacy sessions schema, so session tracking is deliberately
    // NOT wired (see docs/pramnos-migration/05-schema-convergence.md).
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
    // framework feature dirs, whose legacy sessions/settings schema would
    // collide with RadioChatBox's. The baseline is applied in every environment
    // through the explicit `rcb migrate`, so the cutoff (set to the baseline
    // timestamp) keeps auto-run to genuinely new, post-baseline migrations.
    'migrations' => [
        'paths'     => [__DIR__ . '/Migrations'],
        'framework' => false,
    ],
    'migration_cutoff' => '2025-01-01 00:00:01',
];
