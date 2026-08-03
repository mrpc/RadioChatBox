<?php

namespace RadioChatBox\Controllers;

use InvalidArgumentException;
use Pramnos\Http\Response;
use Pramnos\Routing\Attributes\Route;
use RadioChatBox\Middleware\AdminAuthMiddleware;
use RadioChatBox\Services\PromoService;

/**
 * Admin CRUD for promo campaigns (scheduled promotional messages sent by fake
 * users to the public chat or as DMs). Dispatch itself runs on the scheduler.
 */
final class PromoController
{
    /** GET /api/admin/promos — all campaigns. */
    #[Route('/api/admin/promos', methods: 'GET', name: 'admin.promos.list', middleware: [AdminAuthMiddleware::class])]
    public function list(): Response
    {
        try {
            return Response::json(['success' => true, 'promos' => (new PromoService())->all()]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('PromoController::list failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /** POST /api/admin/promos — create a campaign. 400 on bad input. */
    #[Route('/api/admin/promos', methods: 'POST', name: 'admin.promos.create', middleware: [AdminAuthMiddleware::class])]
    public function create(): Response
    {
        try {
            $id = (new PromoService())->create($_POST);
            return Response::json(['success' => true, 'id' => $id]);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('PromoController::create failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /** POST /api/admin/promos/update — {id, ...}. */
    #[Route('/api/admin/promos/update', methods: 'POST', name: 'admin.promos.update', middleware: [AdminAuthMiddleware::class])]
    public function update(): Response
    {
        try {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                return Response::json(['error' => 'id is required'], 400);
            }
            (new PromoService())->update($id, $_POST);
            return Response::json(['success' => true]);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('PromoController::update failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /** POST /api/admin/promos/delete — {id}. */
    #[Route('/api/admin/promos/delete', methods: 'POST', name: 'admin.promos.delete', middleware: [AdminAuthMiddleware::class])]
    public function delete(): Response
    {
        try {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                return Response::json(['error' => 'id is required'], 400);
            }
            (new PromoService())->delete($id);
            return Response::json(['success' => true]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('PromoController::delete failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /** POST /api/admin/promos/run — {id}. Send one campaign now (manual trigger). */
    #[Route('/api/admin/promos/run', methods: 'POST', name: 'admin.promos.run', middleware: [AdminAuthMiddleware::class])]
    public function run(): Response
    {
        try {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                return Response::json(['error' => 'id is required'], 400);
            }
            $service = new PromoService();
            $campaign = null;
            foreach ($service->all() as $c) {
                if ((int) $c['id'] === $id) { $campaign = $c; break; }
            }
            if ($campaign === null) {
                return Response::json(['error' => 'Campaign not found'], 404);
            }
            $now = new \DateTimeImmutable('now');
            $sent = ((string) $campaign['target'] === 'dm')
                ? $service->runDm($campaign, $now)
                : $service->runPublic($campaign);
            return Response::json(['success' => true, 'sent' => $sent]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('PromoController::run failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }
}
