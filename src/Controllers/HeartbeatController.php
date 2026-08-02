<?php

namespace RadioChatBox\Controllers;

use InvalidArgumentException;
use Pramnos\Http\Response;
use Pramnos\Routing\Attributes\Route;
use RadioChatBox\Services\ChatService;
use RadioChatBox\Http\Validate;
use RadioChatBox\Services\StatsService;
use RadioChatBox\Services\RealtimeToken;
use RadioChatBox\Services\RadioStatusService;

/**
 * POST /api/heartbeat — keep a session alive and return the live user count.
 *
 * Migrated from public/api/heartbeat.php. Rebalances fake users, updates the
 * heartbeat, opportunistically records a stats snapshot (best-effort, never
 * fails the request) and returns {success, activeUsers, user_id, user_role}.
 * The framework Request has already decoded the JSON body into $_POST; empty
 * body or missing username/sessionId -> 400.
 */
final class HeartbeatController
{
    #[Route('/api/heartbeat', methods: 'POST', name: 'heartbeat.store')]
    public function store(): Response
    {
        try {
            $input = $_POST;

            if (empty($input)) {
                throw new InvalidArgumentException('Invalid JSON');
            }

            $error = Validate::check($input, [
                'username'  => 'required',
                'sessionId' => 'required',
            ], [
                'username.required'  => 'Username and session ID are required',
                'sessionId.required' => 'Username and session ID are required',
            ]);
            if ($error) {
                return $error;
            }

            $username  = (string) $input['username'];
            $sessionId = (string) $input['sessionId'];
            $ipAddress = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
            $userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');

            $chatService = new ChatService();

            // Balance fake users first, then update the heartbeat (which publishes
            // the user update carrying the correct count) and records the device UA.
            $chatService->balanceFakeUsers();
            $chatService->updateHeartbeat($username, $sessionId, $ipAddress, $userAgent);

            $sessionInfo = $chatService->getSessionInfo($username, $sessionId);

            // Fallback stats recording for when cron isn't available; rate-limited
            // internally to once per 5 minutes. Never fail the heartbeat over it.
            try {
                (new StatsService())->recordSnapshot();
            // @codeCoverageIgnoreStart
            } catch (\Exception $e) {
                \Pramnos\Logs\Logger::log('Stats snapshot recording failed: ' . $e->getMessage(), 'radiochatbox');
            }
            // @codeCoverageIgnoreEnd

            return Response::json([
                'success'     => true,
                'activeUsers' => count($chatService->getAllUsers()),
                'user_id'     => $sessionInfo['user_id'] ?? null,
                'user_role'   => $sessionInfo['user_role'] ?? null,
                // Short-lived signed token that proves this username for realtime
                // delivery — the SSE stream (?token=) and the WS channel-auth both
                // verify it. Refreshed on every heartbeat so it never goes stale.
                'realtime_token' => (new RealtimeToken())->issue($username, $sessionId),
                // Redundancy for the now-playing widget: the track is normally pushed
                // over the socket, but a lost push (a brief disconnect while the track
                // changed) left the widget stuck on the old song. Piggyback the cached
                // current track here — the heartbeat runs every 60s on both transports —
                // so the client re-syncs within a minute without any extra request.
                // Cache-only read: never blocks the heartbeat on the radio's endpoint.
                'now_playing' => (new RadioStatusService())->getCachedNowPlaying(),
            ]);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log($e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }
}
