<?php

/**
 * RadioChatBox HTTP kernel.
 *
 * The whole request lifecycle, kept OUT of the web root so `public/index.php`
 * can be a thin front controller (it only defines ROOT/PUBLIC_PATH, autoloads,
 * and requires this file). A single `public/.htaccess` routes every request that
 * is not a real file/dir here, so the app runs on plain shared hosting with no
 * VirtualHost-specific configuration.
 *
 * Responsibilities:
 *  - boot the framework (503 if unavailable);
 *  - run pending migrations (fingerprint fast-path; never throws);
 *  - dispatch attribute-routed controllers in src/Controllers through the
 *    framework Router + the middleware declared in app/app.php;
 *  - for an unmatched non-API GET, render the SPA shell (app/views/spa.php);
 *    an unmatched /api/* path (or any other unmatched request) is a 404 JSON.
 *
 * Expects ROOT, PUBLIC_PATH defined and vendor/autoload.php already loaded.
 */

declare(strict_types=1);

use Pramnos\Application\Application;
use Pramnos\Application\Container;
use Pramnos\Http\Request;
use Pramnos\Http\Response;
use Pramnos\Http\StreamedResponse;
use Pramnos\Routing\Router;

require ROOT . '/bootstrap/pramnos.php';

if (!radiochatbox_boot_pramnos()) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Service unavailable']);
    return;
}

// The app descriptor (app/app.php) is the single source of framework wiring:
// identity, global middleware and migrations.
$app = Application::getInstance();

// Auto-run any pending (post-baseline) migrations on every request. The
// framework fingerprint fast-path makes this a single indexed lookup when the
// schema is up to date, and migrate() never throws (failures are logged).
$app->migrate();

$container = new Container();
$router    = new Router($container);
$router->loadFromDirectory(ROOT . '/src/Controllers', 'RadioChatBox\\Controllers');

// Global HTTP middleware exactly as declared in app/app.php (CORS + JSON
// shaping). Passed through verbatim — instances or class-strings.
foreach ($app->getMiddleware() as $middleware) {
    $router->addGlobalMiddleware($middleware);
}

$request = Request::getInstance();

try {
    $response = $router->dispatch($request, ['*']);
} catch (\Throwable $e) {
    error_log('HTTP dispatch error: ' . $e->getMessage());
    Response::json(['error' => 'Internal server error'], 500)->send();
    return;
}

if ($response instanceof Response || $response instanceof StreamedResponse) {
    // StreamedResponse (SSE) streams for the life of the request inside send();
    // a buffered Response sends once. Both expose send().
    $response->send();
    return;
}

// No route matched. The .htaccess already served real files/dirs directly, so
// we are on a "virtual" path: serve the SPA shell for a normal page request,
// otherwise report not-found as JSON (keeps the API contract for /api/*).
$path   = Request::$requestUri; // framework-derived path, base already stripped
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$isApi  = $path === 'api' || str_starts_with($path, 'api/');

if ($method === 'GET' && !$isApi) {
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    require ROOT . '/app/views/spa.php'; // uses PUBLIC_PATH for asset cache-busting
    return;
}

Response::json(['error' => 'Not found'], 404)->send();
