<?php

namespace RadioChatBox\Controllers;

use InvalidArgumentException;
use Pramnos\Http\Response;
use Pramnos\Routing\Attributes\Route;
use RadioChatBox\Services\ChatService;
use RadioChatBox\Http\Validate;
use RadioChatBox\Services\StatsService;

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

            $chatService = new ChatService();

            // Balance fake users first, then update the heartbeat (which publishes
            // the user update carrying the correct count).
            $chatService->balanceFakeUsers();
            $chatService->updateHeartbeat($username, $sessionId);

            $sessionInfo = $chatService->getSessionInfo($username, $sessionId);

            // Fallback stats recording for when cron isn't available; rate-limited
            // internally to once per 5 minutes. Never fail the heartbeat over it.
            try {
                (new StatsService())->recordSnapshot();
            } catch (\Exception $e) {
                \RadioChatBox\Log::write('Stats snapshot recording failed: ' . $e->getMessage());
            }

            return Response::json([
                'success'     => true,
                'activeUsers' => count($chatService->getAllUsers()),
                'user_id'     => $sessionInfo['user_id'] ?? null,
                'user_role'   => $sessionInfo['user_role'] ?? null,
            ]);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            \RadioChatBox\Log::write($e->getMessage());
            return Response::json(['error' => 'Internal server error'], 500);
        }
    }
}
