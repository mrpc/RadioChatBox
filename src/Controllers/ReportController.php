<?php

namespace RadioChatBox\Controllers;

use InvalidArgumentException;
use Pramnos\Http\Request;
use Pramnos\Http\Response;
use Pramnos\Routing\Attributes\Route;
use RadioChatBox\AdminAuth;
use RadioChatBox\Http\Csv;
use RadioChatBox\Middleware\AdminAuthMiddleware;
use RadioChatBox\Services\ChatService;
use RadioChatBox\Services\ReportService;

/**
 * Abuse reports: a public endpoint for chat users to flag a message/user, and
 * admin endpoints to work the queue.
 */
class ReportController
{
    /**
     * POST /api/report — a chat user files a report. Body:
     * {username, session_id, message_id?, message_type?, reported_username?,
     *  reason, details?, content_snapshot?}. The session is verified so a report
     * cannot be filed as someone else. 200 {success, report_id}; bad reason/empty
     * reporter -> 400; invalid session -> 403.
     */
    #[Route('/api/report', methods: 'POST', name: 'report.create')]
    public function create(): Response
    {
        try {
            $input = $_POST;
            $username = trim((string) ($input['username'] ?? ''));
            $sessionId = trim((string) ($input['session_id'] ?? ''));
            $reason = trim((string) ($input['reason'] ?? ''));

            if ($username === '' || $reason === '') {
                return Response::json(['error' => 'username and reason are required'], 400);
            }
            if (!ReportService::isValidReason($reason)) {
                return Response::json(['error' => 'Invalid reason'], 400);
            }

            // Verify the caller owns this session (no reporting as someone else).
            if ((new ChatService())->getSessionInfo($username, $sessionId) === null) {
                return Response::json(['error' => 'Invalid session'], 403);
            }

            $reportId = (new ReportService())->create(
                $username,
                $sessionId,
                isset($input['message_id']) ? (string) $input['message_id'] : null,
                (string) ($input['message_type'] ?? 'public'),
                isset($input['reported_username']) ? (string) $input['reported_username'] : null,
                $reason,
                isset($input['details']) ? (string) $input['details'] : null,
                isset($input['content_snapshot']) ? (string) $input['content_snapshot'] : null
            );

            // Live cue for the admin Reports badge/queue (no polling).
            try {
                \Pramnos\Broadcasting\BroadcastingManager::instance()->broadcast(
                    'chat:admin_notifications',
                    'reports_changed',
                    ['signal' => 'reports_changed']
                );
            // @codeCoverageIgnoreStart
            } catch (\Throwable $e) {
                \Pramnos\Logs\Logger::log('reports_changed signal failed: ' . $e->getMessage(), 'radiochatbox');
            }
            // @codeCoverageIgnoreEnd

            return Response::json(['success' => true, 'report_id' => $reportId]);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('ReportController::create failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * GET /api/admin/reports?status=pending&page=1 — the report queue for admins,
     * plus a pending count for the badge. 200 {success, reports, pending_count,
     * pagination}.
     */
    #[Route('/api/admin/reports', methods: 'GET', name: 'admin.reports.list', middleware: [AdminAuthMiddleware::class])]
    public function adminList(): Response
    {
        try {
            $request = Request::getInstance();
            $status = (string) $request->get('status', 'pending', 'get');
            $page = max(1, (int) $request->get('page', 1, 'get'));
            $limit = min((int) $request->get('limit', 50, 'get'), 200);
            $offset = ($page - 1) * $limit;

            $service = new ReportService();
            $reports = $service->list($status, $limit, $offset);
            $total = $service->count($status);

            return Response::json([
                'success'       => true,
                'reports'       => $reports,
                'pending_count' => $service->count('pending'),
                'pagination'    => [
                    'page'        => $page,
                    'limit'       => $limit,
                    'total'       => $total,
                    'total_pages' => (int) ceil($total / $limit),
                ],
            ]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('ReportController::adminList failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * GET /api/admin/reports/stats?days=30 — aggregate report statistics (totals
     * by status/reason and the most-reported users) over a rolling window.
     */
    #[Route('/api/admin/reports/stats', methods: 'GET', name: 'admin.reports.stats', middleware: [AdminAuthMiddleware::class])]
    public function stats(): Response
    {
        try {
            $days = (int) Request::getInstance()->get('days', 30, 'get');
            return Response::json([
                'success' => true,
                'stats'   => (new ReportService())->stats($days),
            ]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('ReportController::stats failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * GET /api/admin/reports/export?status=all — download the reports queue as a
     * CSV file (all statuses by default, or one when `status` is given).
     */
    #[Route('/api/admin/reports/export', methods: 'GET', name: 'admin.reports.export', middleware: [AdminAuthMiddleware::class])]
    public function export(): Response
    {
        try {
            $status = (string) Request::getInstance()->get('status', 'all', 'get');
            $reports = (new ReportService())->list($status, 5000, 0);

            $rows = array_map(static fn (array $r): array => [
                $r['id'] ?? '',
                $r['created_at'] ?? '',
                $r['reason'] ?? '',
                $r['reported_username'] ?? '',
                $r['reporter_username'] ?? '',
                $r['message_type'] ?? '',
                $r['content_snapshot'] ?? '',
                $r['details'] ?? '',
                $r['status'] ?? '',
                $r['resolved_by'] ?? '',
                $r['resolved_at'] ?? '',
            ], $reports);

            $csv = Csv::build(
                ['id', 'created_at', 'reason', 'reported', 'reporter', 'type', 'content', 'details', 'status', 'resolved_by', 'resolved_at'],
                $rows
            );
            return Csv::download($csv, 'reports.csv');
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('ReportController::export failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * POST /api/admin/reports/resolve — {id, action:'resolve'|'dismiss'}. Marks a
     * report handled. 200 {success}; bad input -> 400.
     */
    #[Route('/api/admin/reports/resolve', methods: 'POST', name: 'admin.reports.resolve', middleware: [AdminAuthMiddleware::class])]
    public function adminResolve(): Response
    {
        try {
            $input = $_POST;
            $id = (int) ($input['id'] ?? 0);
            $action = (string) ($input['action'] ?? '');
            $status = $action === 'dismiss' ? 'dismissed' : ($action === 'resolve' ? 'resolved' : '');

            if ($id <= 0 || $status === '') {
                return Response::json(['error' => 'id and a valid action (resolve|dismiss) are required'], 400);
            }

            $admin = AdminAuth::getCurrentUser();
            $adminName = (string) ($admin['username'] ?? 'admin');
            $note = isset($input['note']) ? (string) $input['note'] : null;

            (new ReportService())->setStatus($id, $status, $adminName, $note);
            (new \RadioChatBox\Services\ModerationLog())->record(
                $adminName,
                $status === 'dismissed' ? 'report_dismiss' : 'report_resolve',
                null,
                'report #' . $id
            );
            return Response::json(['success' => true]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('ReportController::adminResolve failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }
}
