<?php

namespace RadioChatBox\Middleware;

use Pramnos\Http\MiddlewareInterface;
use Pramnos\Http\Request;
use Pramnos\Http\Response;
use RadioChatBox\AdminAuth;

/**
 * Admin authentication as a framework middleware.
 *
 * Wraps the existing RadioChatBox\AdminAuth (Bearer "username:password" +
 * Redis-backed session + per-IP rate limiting + RBAC) rather than replacing it,
 * so migrated admin controllers keep the exact auth behaviour. Short-circuits
 * with a 401 JSON response (matching AdminAuth::unauthorized()) when the request
 * is not authenticated; otherwise the request continues down the pipeline.
 *
 * Attach per route via #[Route(..., middleware: [AdminAuthMiddleware::class])].
 */
final class AdminAuthMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): mixed
    {
        if (!AdminAuth::verify()) {
            return Response::json(['error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
