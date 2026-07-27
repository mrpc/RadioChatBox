<?php

namespace RadioChatBox\Http\Controllers;

use Pramnos\Http\Response;
use Pramnos\Routing\Attributes\Route;
use RadioChatBox\FakeUserService;
use RadioChatBox\Http\Middleware\AdminAuthMiddleware;

/**
 * Admin: GET /api/admin/list-fake-users — the full fake-user list.
 *
 * Migrated from public/api/admin/list-fake-users.php. Demonstrates the admin
 * auth pattern: the route carries AdminAuthMiddleware, so an unauthenticated
 * request gets a 401 before the action runs; the payload is unchanged
 * ({success, fake_users}).
 */
final class AdminFakeUsersController
{
    #[Route('/api/admin/list-fake-users', methods: 'GET', name: 'admin.fake-users.list', middleware: [AdminAuthMiddleware::class])]
    public function list(): Response
    {
        try {
            $fakeUsers = (new FakeUserService())->getAllFakeUsers();

            return Response::json([
                'success'    => true,
                'fake_users' => $fakeUsers,
            ]);
        } catch (\Throwable $e) {
            error_log($e->getMessage());
            return Response::json(['error' => 'Internal server error'], 500);
        }
    }
}
