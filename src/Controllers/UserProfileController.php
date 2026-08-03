<?php

namespace RadioChatBox\Controllers;

use Pramnos\Http\Request;
use Pramnos\Http\Response;
use Pramnos\Routing\Attributes\Route;
use Pramnos\Database\Database;
use RadioChatBox\Services\UserProfileService;

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
            $db = Database::getInstance();

            $userRow = $db->queryBuilder()
                ->from('users')
                ->select(['userid', 'username', 'display_name'])
                ->where('username', '=', $username)
                ->first();
            $user = ($userRow && $userRow->numRows > 0) ? $userRow->fields : null;

            $profileRow = $db->queryBuilder()
                ->from('user_profiles')
                ->select(['age', 'sex', 'location'])
                ->where('username', '=', $username)
                ->first();
            $profile = ($profileRow && $profileRow->numRows > 0) ? $profileRow->fields : null;

            if (!$profile) {
                $profile = ['age' => null, 'sex' => null, 'location' => null];
            }

            $profile['display_name'] = $user ? $user['display_name'] : null;

            return Response::json(['success' => true, 'profile' => $profile]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Error fetching user profile: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['success' => false, 'error' => 'Failed to fetch profile'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * GET /api/user-profile/card?username= — the richer public profile card:
     * display name, online status, public message count, first/last seen, role
     * badge and the optional age/sex/location. 200 {success, profile}; missing
     * username -> 400.
     */
    #[Route('/api/user-profile/card', methods: 'GET', name: 'user-profile.card')]
    public function card(): Response
    {
        $username = trim((string) Request::getInstance()->get('username', '', 'get'));
        if ($username === '') {
            return Response::json(['success' => false, 'error' => 'Username is required'], 400);
        }
        try {
            return Response::json(['success' => true, 'profile' => (new UserProfileService())->profile($username)]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('UserProfileController::card failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['success' => false, 'error' => 'Failed to fetch profile'], 500);
        }
        // @codeCoverageIgnoreEnd
    }
}
