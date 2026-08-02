<?php

namespace RadioChatBox\Controllers;

use InvalidArgumentException;
use Pramnos\Http\Request;
use Pramnos\Http\Response;
use Pramnos\Routing\Attributes\Route;
use RadioChatBox\AdminAuth;
use RadioChatBox\Middleware\AdminAuthMiddleware;
use RadioChatBox\Services\ModerationLog;
use RadioChatBox\Services\WarningService;

/**
 * Moderator warnings: issue a warning, list a user's warnings, or remove one.
 * Reaching the configured active-warning threshold auto-timeouts the user
 * (handled in WarningService). Admin-only.
 */
class WarningController
{
    /**
     * POST /api/admin/warn-user — {username, reason?}. Records a warning and,
     * if the threshold is reached, auto-timeouts the user. 200 {success,
     * warning_id, active_count, auto_timed_out}; bad input -> 400.
     */
    #[Route('/api/admin/warn-user', methods: 'POST', name: 'admin.warn-user', middleware: [AdminAuthMiddleware::class])]
    public function warn(): Response
    {
        try {
            $input = $_POST;
            $username = trim((string) ($input['username'] ?? ''));
            $reason = isset($input['reason']) ? (string) $input['reason'] : null;
            if ($username === '') {
                return Response::json(['error' => 'username is required'], 400);
            }

            $admin = AdminAuth::getCurrentUser();
            $adminName = (string) ($admin['username'] ?? 'admin');

            $result = (new WarningService())->warn($username, $adminName, $reason);

            try {
                (new ModerationLog())->record($adminName, 'warn', $username, $reason ?? '');
            } catch (\Throwable $e) {
                // Non-fatal.
            }

            return Response::json(['success' => true] + $result);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('WarningController::warn failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * GET /api/admin/user-warnings?username=… — a user's warnings + active count.
     * 200 {success, warnings, active_count}; bad input -> 400.
     */
    #[Route('/api/admin/user-warnings', methods: 'GET', name: 'admin.user-warnings', middleware: [AdminAuthMiddleware::class])]
    public function list(): Response
    {
        try {
            $username = trim((string) Request::getInstance()->get('username', '', 'get'));
            if ($username === '') {
                return Response::json(['error' => 'username is required'], 400);
            }
            $service = new WarningService();
            return Response::json([
                'success'      => true,
                'warnings'     => $service->list($username),
                'active_count' => $service->activeCount($username),
            ]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('WarningController::list failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * POST /api/admin/remove-warning — {id}. Removes a warning (mistaken).
     * 200 {success}; bad input -> 400.
     */
    #[Route('/api/admin/remove-warning', methods: 'POST', name: 'admin.remove-warning', middleware: [AdminAuthMiddleware::class])]
    public function remove(): Response
    {
        try {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                return Response::json(['error' => 'id is required'], 400);
            }
            (new WarningService())->remove($id);
            return Response::json(['success' => true]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('WarningController::remove failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }
}
