<?php

namespace RadioChatBox\Controllers;

use InvalidArgumentException;
use Pramnos\Http\Request;
use Pramnos\Http\Response;
use Pramnos\Routing\Attributes\Route;
use RadioChatBox\AdminAuth;
use RadioChatBox\Middleware\AdminAuthMiddleware;
use RadioChatBox\Services\ChatService;
use RadioChatBox\Services\SettingsService;
use RadioChatBox\Services\SongRequestService;

/**
 * Listener song requests + dedications: a public endpoint for chat users to
 * request a track (with an optional shout-out), and admin endpoints to work the
 * queue. Gated by the `song_requests_enabled` setting so a station opts in.
 */
class SongRequestController
{
    /** Max pending requests one session may file in the flood window. */
    private const FLOOD_LIMIT = 3;
    private const FLOOD_MINUTES = 10;

    /**
     * POST /api/songs/request — a chat user files a request. Body:
     * {username, session_id, song_title, artist?, dedication?}. The session is
     * verified so a request cannot be filed as someone else. 200 {success,
     * request_id}; feature off -> 404; bad input -> 400; invalid session -> 403;
     * too many pending -> 429.
     */
    #[Route('/api/songs/request', methods: 'POST', name: 'songs.request.create')]
    public function create(): Response
    {
        try {
            if (!$this->isEnabled()) {
                return Response::json(['success' => false, 'error' => 'Song requests are disabled'], 404);
            }

            $input = $_POST;
            $username = trim((string) ($input['username'] ?? ''));
            $sessionId = trim((string) ($input['session_id'] ?? ''));
            $songTitle = trim((string) ($input['song_title'] ?? ''));

            if ($username === '' || $songTitle === '') {
                return Response::json(['error' => 'username and song_title are required'], 400);
            }

            // Verify the caller owns this session (no requesting as someone else).
            if ((new ChatService())->getSessionInfo($username, $sessionId) === null) {
                return Response::json(['error' => 'Invalid session'], 403);
            }

            $service = new SongRequestService();

            // Cheap flood guard: cap pending requests per session.
            if ($service->recentCountForSession($sessionId, self::FLOOD_MINUTES) >= self::FLOOD_LIMIT) {
                return Response::json(
                    ['error' => 'You have several pending requests already. Please wait for them to be handled.'],
                    429
                );
            }

            $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;

            $requestId = $service->create(
                $username,
                $sessionId,
                $songTitle,
                isset($input['artist']) ? (string) $input['artist'] : null,
                isset($input['dedication']) ? (string) $input['dedication'] : null,
                $ipAddress
            );

            // Live cue for the admin Song Requests badge/queue (no polling).
            $this->signalAdmin();

            return Response::json(['success' => true, 'request_id' => $requestId]);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('SongRequestController::create failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * GET /api/shoutouts?limit=10 — recent public shout-outs (approved/played
     * requests that carry a dedication). Public; gated by song_requests_enabled.
     * 200 {success, shoutouts}.
     */
    #[Route('/api/shoutouts', methods: 'GET', name: 'shoutouts.list')]
    public function shoutouts(): Response
    {
        try {
            if (!$this->isEnabled()) {
                return Response::json(['success' => true, 'shoutouts' => []]);
            }
            $limit = (int) Request::getInstance()->get('limit', 10, 'get');
            return Response::json([
                'success'   => true,
                'shoutouts' => (new SongRequestService())->shoutouts($limit),
            ]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('SongRequestController::shoutouts failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * GET /api/admin/song-requests?status=pending&page=1 — the request queue for
     * admins, plus a pending count for the badge. 200 {success, requests,
     * pending_count, pagination}.
     */
    #[Route('/api/admin/song-requests', methods: 'GET', name: 'admin.song-requests.list', middleware: [AdminAuthMiddleware::class])]
    public function adminList(): Response
    {
        try {
            $request = Request::getInstance();
            $status = (string) $request->get('status', 'pending', 'get');
            $page = max(1, (int) $request->get('page', 1, 'get'));
            $limit = min((int) $request->get('limit', 50, 'get'), 200);
            $offset = ($page - 1) * $limit;

            $service = new SongRequestService();
            $requests = $service->list($status, $limit, $offset);
            $total = $service->count($status);

            return Response::json([
                'success'       => true,
                'requests'      => $requests,
                'pending_count' => $service->count('pending'),
                'pagination'    => [
                    'page'        => $page,
                    'limit'       => $limit,
                    'total'       => $total,
                    'total_pages' => (int) ceil($total / max(1, $limit)),
                ],
            ]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('SongRequestController::adminList failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * POST /api/admin/song-requests/update — {id, action:approve|played|reject|pending}.
     * Moves a request through the queue. 200 {success}; bad input -> 400.
     */
    #[Route('/api/admin/song-requests/update', methods: 'POST', name: 'admin.song-requests.update', middleware: [AdminAuthMiddleware::class])]
    public function adminUpdate(): Response
    {
        try {
            $input = $_POST;
            $id = (int) ($input['id'] ?? 0);
            $action = (string) ($input['action'] ?? '');
            $status = match ($action) {
                'approve'          => 'approved',
                'played'           => 'played',
                'reject'           => 'rejected',
                'pending'          => 'pending',
                default            => '',
            };

            if ($id <= 0 || $status === '') {
                return Response::json(['error' => 'id and a valid action (approve|played|reject|pending) are required'], 400);
            }

            $admin = AdminAuth::getCurrentUser();
            $adminName = (string) ($admin['username'] ?? 'admin');

            (new SongRequestService())->setStatus($id, $status, $adminName);
            $this->signalAdmin();

            return Response::json(['success' => true]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('SongRequestController::adminUpdate failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /** Whether the feature is switched on in settings. */
    private function isEnabled(): bool
    {
        $value = (new SettingsService())->get('song_requests_enabled');
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes'], true);
    }

    /** Best-effort live cue for the admin queue/badge (no polling). */
    private function signalAdmin(): void
    {
        try {
            \Pramnos\Broadcasting\BroadcastingManager::instance()->broadcast(
                'chat:admin_notifications',
                'song_requests_changed',
                ['signal' => 'song_requests_changed']
            );
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('song_requests_changed signal failed: ' . $e->getMessage(), 'radiochatbox');
        }
        // @codeCoverageIgnoreEnd
    }
}
