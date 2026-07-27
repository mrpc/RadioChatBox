<?php

namespace RadioChatBox\Controllers;

use PDO;
use Pramnos\Http\Request;
use Pramnos\Http\Response;
use Pramnos\Routing\Attributes\Route;
use RadioChatBox\Database;

/**
 * GET /api/user-profile?username=… — a user's public profile (age/sex/location
 * plus display_name for registered users).
 *
 * Migrated from public/api/user-profile.php, preserving the exact payload and the
 * 400 (missing username) / 500 error behaviour.
 */
final class UserProfileController
{
    #[Route('/api/user-profile', methods: 'GET', name: 'user-profile.show')]
    public function show(): Response
    {
        $username = (string) Request::getInstance()->get('username', '', 'get');

        if (empty($username)) {
            return Response::json(['success' => false, 'error' => 'Username is required'], 400);
        }

        try {
            $db = Database::getPDO();

            $userStmt = $db->prepare('SELECT id, username, display_name FROM users WHERE username = :username');
            $userStmt->execute(['username' => $username]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);

            $stmt = $db->prepare('
                SELECT age, sex, location
                FROM user_profiles
                WHERE username = :username
                LIMIT 1
            ');
            $stmt->execute(['username' => $username]);
            $profile = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$profile) {
                $profile = ['age' => null, 'sex' => null, 'location' => null];
            }

            $profile['display_name'] = $user ? $user['display_name'] : null;

            return Response::json(['success' => true, 'profile' => $profile]);
        } catch (\Throwable $e) {
            \RadioChatBox\Log::write('Error fetching user profile: ' . $e->getMessage());
            return Response::json(['success' => false, 'error' => 'Failed to fetch profile'], 500);
        }
    }
}
