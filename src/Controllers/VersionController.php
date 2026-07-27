<?php

namespace RadioChatBox\Controllers;

use Pramnos\Http\Response;
use Pramnos\Routing\Attributes\Route;
use RadioChatBox\Config;

/**
 * GET /api/version — the deployed app version plus a server timestamp.
 *
 * Migrated from public/api/version.php; payload unchanged
 * ({success, version, timestamp}).
 */
final class VersionController
{
    #[Route('/api/version', methods: 'GET', name: 'version.show')]
    public function show(): Response
    {
        try {
            return Response::json([
                'success'   => true,
                'version'   => Config::get('version'),
                'timestamp' => time(),
            ]);
        } catch (\Throwable $e) {
            return Response::json(['success' => false, 'error' => 'Failed to get version'], 500);
        }
    }
}
