<?php

namespace RadioChatBox\Controllers;

use Pramnos\Http\Response;
use Pramnos\Routing\Attributes\Route;

/**
 * First attribute-routed controller (migration Phase 6).
 *
 * A trivial read-only endpoint that proves the framework HTTP path end to end:
 * attribute route discovery -> Router dispatch -> Route::execute() instantiating
 * this controller -> Http\Response, run through the native middleware pipeline.
 * Real endpoints are migrated onto this pattern one at a time; the legacy
 * public/api/*.php files keep serving until each is moved.
 */
final class StatusController
{
    #[Route('/api/status', methods: 'GET', name: 'status.show')]
    public function status(): Response
    {
        return Response::json([
            'status' => 'ok',
            'app'    => 'RadioChatBox',
        ]);
    }
}
