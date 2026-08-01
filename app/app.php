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

    // Framework schema features: adopt the framework's users/auth/settings/
    // messaging/queue schema. `core` is always-on; these add the auth stack
    // (users companions, usertokens, RBAC, 2FA/passkey), messaging and queue.
    // The create_schema baseline builds RCB's tables in the framework-converged
    // shape (users(userid,usertype), chat_messages/presence_sessions, settings),
    // so the framework's own create_users/settings/messages/sessions become
    // hasTable() skips and its auth/messaging/queue companions attach to
    // users(userid).
    'features'  => ['auth', 'authserver', 'messaging', 'queue'],

    // Realtime transport. The client asks /api/realtime-config which advertises
    // WebSocket ONLY when the admin enabled it (realtime_websocket_enabled),
    // REALTIME_WS_PUBLIC_HOST is configured and the realtime:serve worker is
    // healthy — otherwise it advertises SSE (/api/stream). The `websocket` block
    // here holds the PUBLIC address a browser dials (behind the TLS reverse proxy),
    // distinct from the worker's local bind (REALTIME_WS_HOST/PORT).
    'broadcasting' => [
        'transport' => 'sse',
        'sse'       => ['url' => '/api/stream'],
        'websocket' => [
            'scheme'  => (string) envvar('REALTIME_WS_PUBLIC_SCHEME', 'wss'),
            'host'    => (string) envvar('REALTIME_WS_PUBLIC_HOST', ''),
            'port'    => (int) envvar('REALTIME_WS_PUBLIC_PORT', '443'),
            'app_key' => (string) envvar('REALTIME_APP_KEY', 'radiochatbox'),
        ],
    ],

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
    // fingerprint fast-path. `framework => true` runs the framework feature
    // migrations (auth/messaging/queue) alongside the app's create_schema
    // baseline. The cutoff is kept empty (not a floor) because the framework
    // migrations are dated 2020 — any post-baseline floor would filter them out.
    // On a brand-new database run the baseline first, then the framework set
    // (see tests/bootstrap.php's two-phase build), so the framework create_*
    // migrations see the baseline's tables and hasTable()-skip them.
    'migrations' => [
        'paths'     => [__DIR__ . '/Migrations'],
        'framework' => true,
    ],
    'migration_cutoff' => '',
];
