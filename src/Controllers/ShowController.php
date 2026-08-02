<?php

namespace RadioChatBox\Controllers;

use InvalidArgumentException;
use Pramnos\Http\Request;
use Pramnos\Http\Response;
use Pramnos\Routing\Attributes\Route;
use RadioChatBox\Middleware\AdminAuthMiddleware;
use RadioChatBox\Services\ShowService;

/**
 * Radio show schedule: a public "upcoming shows" feed and admin CRUD for the
 * schedule (recurring or one-off broadcasts).
 */
final class ShowController
{
    /**
     * GET /api/shows/upcoming?limit=10 — the next upcoming show occurrences.
     * Public. 200 {success, shows}.
     */
    #[Route('/api/shows/upcoming', methods: 'GET', name: 'shows.upcoming')]
    public function upcoming(): Response
    {
        try {
            $limit = (int) Request::getInstance()->get('limit', 10, 'get');
            $now = new \DateTimeImmutable('now');
            return Response::json([
                'success' => true,
                'shows'   => (new ShowService())->upcoming($now, $limit),
            ]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('ShowController::upcoming failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /** GET /api/admin/shows — the full schedule (admin). 200 {success, shows}. */
    #[Route('/api/admin/shows', methods: 'GET', name: 'admin.shows.list', middleware: [AdminAuthMiddleware::class])]
    public function adminList(): Response
    {
        try {
            return Response::json(['success' => true, 'shows' => (new ShowService())->all()]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('ShowController::adminList failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /** POST /api/admin/shows — create a show. 200 {success, id}; bad input -> 400. */
    #[Route('/api/admin/shows', methods: 'POST', name: 'admin.shows.create', middleware: [AdminAuthMiddleware::class])]
    public function create(): Response
    {
        try {
            $id = (new ShowService())->create($_POST);
            return Response::json(['success' => true, 'id' => $id]);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('ShowController::create failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /** POST /api/admin/shows/update — {id, ...}. 200 {success}; bad input -> 400. */
    #[Route('/api/admin/shows/update', methods: 'POST', name: 'admin.shows.update', middleware: [AdminAuthMiddleware::class])]
    public function update(): Response
    {
        try {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                return Response::json(['error' => 'id is required'], 400);
            }
            (new ShowService())->update($id, $_POST);
            return Response::json(['success' => true]);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('ShowController::update failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /** POST /api/admin/shows/delete — {id}. 200 {success}; bad input -> 400. */
    #[Route('/api/admin/shows/delete', methods: 'POST', name: 'admin.shows.delete', middleware: [AdminAuthMiddleware::class])]
    public function delete(): Response
    {
        try {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                return Response::json(['error' => 'id is required'], 400);
            }
            (new ShowService())->delete($id);
            return Response::json(['success' => true]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('ShowController::delete failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }
}
