<?php

namespace RadioChatBox\Controllers;

use Pramnos\Http\Response;
use Pramnos\Routing\Attributes\Route;

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
                'version'   => $this->version(),
                'timestamp' => time(),
            ]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            return Response::json(['success' => false, 'error' => 'Failed to get version'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * The deployed version: an explicit APP_VERSION when set, otherwise a
     * cache-busting stamp derived from the stylesheet's mtime (a new build ships
     * a new style.css), falling back to the current time. Matches the value the
     * retired RadioChatBox\Config produced.
     */
    private function version(): string
    {
        $explicit = envvar('APP_VERSION');
        if ($explicit !== null && $explicit !== '') {
            return (string) $explicit;
        }

        $cssFile = __DIR__ . '/../../public/css/style.css';
        if (is_file($cssFile)) {
            return (string) filemtime($cssFile);
        }

        return (string) time();
    }
}
