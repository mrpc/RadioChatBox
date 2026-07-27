<?php

namespace RadioChatBox\Tests\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Container;
use Pramnos\Http\MiddlewareInterface;
use Pramnos\Http\Request;
use Pramnos\Http\Response;
use Pramnos\Routing\Router;
use RadioChatBox\Controllers\StatusController;

/**
 * Functional proof of the Phase 6 HTTP path: a request runs through the Router,
 * the global middleware pipeline, and into an attribute-routed controller method,
 * coming back as an Http\Response.
 *
 * This exercises the Route::execute() controller-dispatch fix end to end (a
 * [Controller, method] action is instantiated and invoked) plus the native
 * middleware pipeline. The route is registered directly here (attribute discovery
 * is covered by the framework's own tests and used by the real front controller,
 * public/_dispatch.php).
 */
class StatusControllerTest extends TestCase
{
    private function router(): Router
    {
        $router = new Router(new Container());
        $router->get('/api/status', [StatusController::class, 'status']);
        return $router;
    }

    /**
     * Dispatching GET /api/status reaches the controller and returns a 200
     * JSON Response with the expected payload.
     */
    public function testStatusEndpointDispatchesToController(): void
    {
        $response = $this->router()->dispatch(Request::create('/api/status', 'GET'), ['*']);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);
        $this->assertSame('ok', $body['status']);
        $this->assertSame('RadioChatBox', $body['app']);
    }

    /**
     * Global middleware wraps the controller: a middleware added to the router
     * sees and can decorate the controller's Response (proving the pipeline runs
     * around the dispatched action).
     */
    public function testGlobalMiddlewareWrapsTheControllerResponse(): void
    {
        $tagging = new class implements MiddlewareInterface {
            public function handle(Request $request, callable $next): mixed
            {
                $response = $next($request);
                return $response instanceof Response
                    ? $response->withHeader('X-Mw', 'ran')
                    : $response;
            }
        };

        $router = $this->router();
        $router->addGlobalMiddleware($tagging);

        $response = $router->dispatch(Request::create('/api/status', 'GET'), ['*']);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame('ran', $response->getHeaderLine('X-Mw'));
        $this->assertSame(200, $response->getStatusCode());
    }
}
