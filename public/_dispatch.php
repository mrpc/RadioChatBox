<?php

/**
 * RadioChatBox API front controller (migration Phase 6).
 *
 * Dispatches attribute-routed controllers in src/Http/Controllers through
 * PramnosFramework's Router + native middleware pipeline. Apache serves any
 * /api/* request that is NOT an existing file here via FallbackResource
 * (apache/site.conf), so migrated endpoints use clean paths while the remaining
 * legacy public/api/*.php files are served directly — the strangler pattern.
 *
 * It deliberately lives at the DOCUMENT ROOT (not under /api): the framework's
 * Request derives the app base path from the front controller's directory, so a
 * root-level dispatcher keeps the full request path (e.g. `api/status`) for the
 * router instead of stripping the `/api` segment.
 */

declare(strict_types=1);

use Pramnos\Application\Container;
use Pramnos\Http\Request;
use Pramnos\Http\Response;
use Pramnos\Http\Middleware\CorsMiddleware;
use Pramnos\Http\Middleware\JsonResponseMiddleware;
use Pramnos\Routing\Router;
use RadioChatBox\Config;

if (!defined('ROOT')) {
    define('ROOT', dirname(__DIR__)); // public/_dispatch.php -> project root
}

require ROOT . '/vendor/autoload.php';
require ROOT . '/bootstrap/pramnos.php';

if (!radiochatbox_boot_pramnos()) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Service unavailable']);
    return;
}

$container = new Container();
$router    = new Router($container);
$router->loadFromDirectory(ROOT . '/src/Http/Controllers', 'RadioChatBox\\Http\\Controllers');

// CORS parity with the legacy CorsHandler: reflect the request Origin against the
// configured allow-list, credentials on, GET/POST/OPTIONS + Content-Type.
$router->addGlobalMiddleware(new CorsMiddleware(
    (array) Config::get('allowed_origins', ['*']),
    ['GET', 'POST', 'OPTIONS'],
    ['Content-Type'],
    true
));
$router->addGlobalMiddleware(JsonResponseMiddleware::class);

$request = Request::getInstance();

try {
    $response = $router->dispatch($request, ['*']);
} catch (\Throwable $e) {
    error_log('API dispatch error: ' . $e->getMessage());
    Response::json(['error' => 'Internal server error'], 500)->send();
    return;
}

if ($response instanceof Response) {
    $response->send();
    return;
}

// No route matched.
Response::json(['error' => 'Not found'], 404)->send();
