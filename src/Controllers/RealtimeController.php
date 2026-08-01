<?php

namespace RadioChatBox\Controllers;

use Pramnos\Broadcasting\RealtimeConfig;
use Pramnos\Http\Response;
use Pramnos\Routing\Attributes\Route;
use RadioChatBox\AdminAuth;
use RadioChatBox\ConsoleCommands\RadioChatBoxDaemons;
use RadioChatBox\Services\Authz;
use RadioChatBox\Services\RealtimeToken;
use RadioChatBox\Services\RealtimeSettings;

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
        $c = RealtimeSettings::resolve();

        if ($c['enabled'] && $c['publicHost'] !== '' && $this->wsWorkerHealthy()) {
            $cfg = RealtimeConfig::forClient([
                'transport' => 'websocket',
                'websocket' => [
                    'scheme'  => $c['scheme'],
                    'host'    => $c['publicHost'],
                    'port'    => $c['publicPort'],
                    'app_key' => $c['appKey'],
                ],
            ]);
            $cfg['authEndpoint'] = '/api/broadcasting/auth';
        } else {
            $cfg = RealtimeConfig::forClient(['transport' => 'sse']);
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

        $rt     = new RealtimeToken();
        $appKey = RealtimeSettings::resolve()['appKey'];
        $sign   = fn (): Response => Response::json(['auth' => $rt->pusherChannelAuth($appKey, $socketId, $channel)]);

        // The shared admin notifications channel: any authenticated admin may
        // subscribe (proven by the admin Bearer/session the fetch sends — unlike
        // EventSource, a fetch can carry the Authorization header).
        if ($channel === 'private-admin-notifications') {
            return $this->callerIsAdmin() ? $sign() : Response::json(['error' => 'Forbidden'], 403);
        }

        // A private DM channel: authorize its OWNER (proven by the realtime token)
        // OR any authenticated admin (moderation / fake-user impersonation, which
        // watches a fake user's own private-pm-<name> feed). Anyone else is refused,
        // so a DM never reaches a non-participant, non-admin client.
        if (str_starts_with($channel, 'private-pm-')) {
            $tokenUser = $token !== '' ? ($rt->verify($token)['u'] ?? null) : null;
            $isOwner   = $tokenUser !== null && $channel === 'private-pm-' . $tokenUser;
            if ($isOwner || $this->callerIsAdmin()) {
                return $sign();
            }
        }

        return Response::json(['error' => 'Forbidden'], 403);
    }

    /** Whether the request carries a valid admin Bearer/session (>= administrator). */
    private function callerIsAdmin(): bool
    {
        $admin = AdminAuth::verify() ? AdminAuth::getCurrentUser() : null;
        return is_array($admin) && Authz::usertypeForLabel($admin['role'] ?? '') >= Authz::ADMINISTRATOR;
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
