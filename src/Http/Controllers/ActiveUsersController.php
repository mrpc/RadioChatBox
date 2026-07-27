<?php

namespace RadioChatBox\Http\Controllers;

use Pramnos\Http\Response;
use Pramnos\Routing\Attributes\Route;
use RadioChatBox\ChatService;

/**
 * GET /api/active-users — the online user list (real + active fake users) and its
 * count. Migrated from public/api/active-users.php onto the framework HTTP path
 * (attribute route + Response), producing an identical payload.
 */
final class ActiveUsersController
{
    #[Route('/api/active-users', methods: 'GET', name: 'active-users.index')]
    public function index(): Response
    {
        try {
            $users = (new ChatService())->getAllUsers();

            return Response::json([
                'success' => true,
                'count'   => count($users),
                'users'   => $users,
            ]);
        } catch (\Throwable $e) {
            error_log($e->getMessage());
            return Response::json(['error' => 'Internal server error'], 500);
        }
    }
}
