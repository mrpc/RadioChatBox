<?php

namespace RadioChatBox\Controllers;

use Pramnos\Application\Application;
use Pramnos\Broadcasting\RealtimeConfig;
use Pramnos\Http\Response;
use Pramnos\Routing\Attributes\Route;
use RadioChatBox\ConsoleCommands\RadioChatBoxDaemons;
use RadioChatBox\Services\RealtimeToken;
use RadioChatBox\Services\SettingsService;

/**
 * Realtime transport discovery + WS channel authorization.
 *
 *  - GET  /api/realtime-config   — tells the client which transport to use. It
 *    advertises WebSocket ONLY when the admin enabled it, a public WS host is
 *    configured and the realtime:serve worker is actually healthy; otherwise it
 *    advertises the SSE fallback. So a down/absent worker transparently degrades
 *    every client to SSE with no client-side change.
 *  - POST /api/broadcasting/auth — signs a Pusher private-channel subscription,
 *    but ONLY the caller's own `private-pm-<username>` (proven by the realtime
 *    token). Any other channel is refused, so a DM can never reach a client that
 *    is not one of its participants.
 */
final class RealtimeController
{
    #[Route('/api/realtime-config', methods: 'GET', name: 'realtime.config')]
    public function config(): Response
    {
        $broadcasting = $this->broadcastingConfig();
        $wsEnabled    = (new SettingsService())->get('realtime_websocket_enabled', 'false') === 'true';
        $publicHost   = (string) ($broadcasting['websocket']['host'] ?? '');

        if ($wsEnabled && $publicHost !== '' && $this->wsWorkerHealthy()) {
            $cfg = RealtimeConfig::forClient(['transport' => 'websocket'] + $broadcasting);
            $cfg['authEndpoint'] = '/api/broadcasting/auth';
        } else {
            $cfg = RealtimeConfig::forClient(['transport' => 'sse'] + $broadcasting);
        }

        return Response::json($cfg);
    }

    #[Route('/api/broadcasting/auth', methods: 'POST', name: 'realtime.auth')]
    public function auth(): Response
    {
        // Framework merges a POST JSON (or form) body into $_POST.
        $socketId = (string) ($_POST['socket_id'] ?? '');
        $channel  = (string) ($_POST['channel_name'] ?? '');
        $token    = (string) ($_POST['token'] ?? '');

        if ($socketId === '' || $channel === '') {
            return Response::json(['error' => 'socket_id and channel_name are required'], 400);
        }

        $rt       = new RealtimeToken();
        $claims   = $token !== '' ? $rt->verify($token) : null;
        $username = $claims['u'] ?? null;

        // A client may authorize ONLY its own private channel. Anything else —
        // notably another user's private-pm-* — is refused.
        if ($username === null || $channel !== 'private-pm-' . $username) {
            return Response::json(['error' => 'Forbidden'], 403);
        }

        $appKey = (string) ($this->broadcastingConfig()['websocket']['app_key'] ?? 'radiochatbox');

        return Response::json(['auth' => $rt->pusherChannelAuth($appKey, $socketId, $channel)]);
    }

    /** @return array<string,mixed> */
    private function broadcastingConfig(): array
    {
        $app = Application::getInstance();
        return is_array($app->applicationInfo['broadcasting'] ?? null) ? $app->applicationInfo['broadcasting'] : [];
    }

    /** Whether the realtime:serve worker is currently supervised and running. */
    private function wsWorkerHealthy(): bool
    {
        try {
            foreach ((new RadioChatBoxDaemons())->status()['daemons'] ?? [] as $d) {
                if (($d['id'] ?? null) === 'realtime') {
                    return (bool) ($d['running'] ?? false);
                }
            }
        } catch (\Throwable) {
            // treat any status error as "not healthy" → SSE
        }
        return false;
    }
}
