<?php

namespace RadioChatBox\Controllers;

use RadioChatBox\Http\Validate;
use Pramnos\Http\Response;
use Pramnos\Routing\Attributes\Route;
use Pramnos\Broadcasting\BroadcastingManager;
use Pramnos\Cache\FlatCache;
use Pramnos\Database\Database;
use RadioChatBox\Services\PhotoService;

/**
 * "Profile" resource controller — public (non-admin) endpoints for a user's own
 * profile data.
 *
 * Migrated from:
 *   - public/api/update-profile.php -> POST /api/update-profile
 *   - public/api/upload-photo.php   -> POST /api/upload-photo
 *
 * Behaviour (JSON keys, status codes, error mapping, cache-clearing and Redis
 * publishes) is preserved exactly. POST bodies are read from $_POST: for
 * update-profile the framework Request has already decoded the JSON body into
 * $_POST, and for upload-photo PHP populates $_POST/$_FILES from the multipart
 * form.
 */
final class ProfileController
{
    /**
     * POST /api/update-profile — replaces public/api/update-profile.php.
     *
     * Verifies the session belongs to the user, optionally updates the
     * registered user's display_name (with the same uniqueness checks and Redis
     * cache-busting / pub-sub the legacy performed), then upserts the guest
     * profile row (age/sex/location).
     *
     * Success: 200 {success:true, message:"Profile updated successfully"}.
     * Errors: 400 (missing username/sessionId, bad age/sex, display-name
     * conflicts), 403 (invalid session), 500 (unexpected).
     */
    #[Route('/api/update-profile', methods: 'POST', name: 'profile.update')]
    public function update(): Response
    {
        // Framework Request has already decoded the JSON body into $_POST.
        $input = $_POST;

        $username    = $input['username'] ?? '';
        $sessionId   = $input['sessionId'] ?? '';
        $age         = $input['age'] ?? null;
        $sex         = $input['sex'] ?? '';
        $location    = $input['location'] ?? '';
        $displayName = $input['displayName'] ?? null;

        // Validation (age range compares numerically even for form strings;
        // sex, when given, must be male/female). Field order sets which message
        // surfaces as `error`.
        $error = Validate::check($input, [
            'username'  => 'required',
            'sessionId' => 'required',
            'age'       => 'nullable|integer|min:18|max:120',
            'sex'       => 'nullable|in:male,female',
        ], [
            'username.required'  => 'Username and session ID are required',
            'sessionId.required' => 'Username and session ID are required',
            'age.integer'        => 'Age must be between 18 and 120',
            'age.min'            => 'Age must be between 18 and 120',
            'age.max'            => 'Age must be between 18 and 120',
            'sex.in'             => 'Invalid sex value',
        ]);
        if ($error) {
            return $error;
        }

        try {
            $db = Database::getInstance();

            // Verify session belongs to user
            $sessionOwned = $db->queryBuilder()
                ->from('sessions')
                ->where('session_id', '=', $sessionId)
                ->where('username', '=', $username)
                ->exists();

            if (!$sessionOwned) {
                return Response::json(['success' => false, 'error' => 'Invalid session'], 403);
            }

            // Update display name in users table if key is present (even if value is null)
            if (array_key_exists('displayName', $input)) {
                // Check if user is authenticated (has user_id in session)
                $sessionRow = $db->queryBuilder()
                    ->from('sessions')
                    ->select(['user_id'])
                    ->where('session_id', '=', $sessionId)
                    ->where('username', '=', $username)
                    ->whereRaw('user_id IS NOT NULL')
                    ->first();
                $session = ($sessionRow && $sessionRow->numRows > 0) ? $sessionRow->fields : null;

                if ($session && $session['user_id']) {
                    // Handle null and empty string cases for PHP 8+ compatibility
                    $trimmedDisplayName = $displayName !== null ? trim($displayName) : '';
                    $finalDisplayName   = empty($trimmedDisplayName) ? null : $trimmedDisplayName;

                    // If setting a display name (not clearing it), check for uniqueness
                    if ($finalDisplayName !== null) {
                        // Check if display name conflicts with any username
                        if ($db->queryBuilder()->from('users')->where('username', '=', $finalDisplayName)->exists()) {
                            return Response::json(['success' => false, 'error' => 'This display name is already taken as a username'], 400);
                        }

                        // Check if display name conflicts with another user's display name
                        $conflictsOtherUser = $db->queryBuilder()
                            ->from('users')
                            ->where('display_name', '=', $finalDisplayName)
                            ->where('id', '!=', $session['user_id'])
                            ->exists();
                        if ($conflictsOtherUser) {
                            return Response::json(['success' => false, 'error' => 'This display name is already taken'], 400);
                        }

                        // Check if display name conflicts with fake user nicknames
                        if ($db->queryBuilder()->from('fake_users')->where('nickname', '=', $finalDisplayName)->exists()) {
                            return Response::json(['success' => false, 'error' => 'This display name conflicts with a system user'], 400);
                        }

                        // Check if display name conflicts with active guest nicknames
                        if ($db->queryBuilder()->from('sessions')->where('username', '=', $finalDisplayName)->exists()) {
                            return Response::json(['success' => false, 'error' => 'This display name is currently in use as a nickname'], 400);
                        }
                    }

                    // Update display_name in users table
                    $db->queryBuilder()
                        ->from('users')
                        ->where('id', '=', $session['user_id'])
                        ->update(['display_name' => $finalDisplayName]);

                    // Clear ALL caches related to this user's display name (the
                    // Cache capability applies the instance prefix). chat:messages /
                    // chat:messages:hash are the message-history Redis structures —
                    // deleting the key forces a rebuild (re-modeled in Step 4).
                    FlatCache::default()->delete('display_name:' . $username); // legacy
                    FlatCache::default()->delete('user_data:' . $username);
                    FlatCache::default()->delete('chat:messages'); // message history
                    FlatCache::default()->delete('chat:all_users'); // combined user list
                    FlatCache::default()->delete('chat:messages:hash'); // reply hash

                    // Small delay to ensure database commit and cache clear complete
                    usleep(100000); // 100ms

                    // Publish a history refresh event to all connected clients
                    BroadcastingManager::instance()->broadcast('chat:updates', 'refresh_history', [
                        'type'   => 'refresh_history',
                        'reason' => 'display_name_changed',
                    ]);

                    // Publish user list update event (to refresh display names in user list)
                    BroadcastingManager::instance()->broadcast('chat:user_updates', 'display_name_changed', [
                        'type'         => 'display_name_changed',
                        'username'     => $username,
                        'display_name' => $finalDisplayName,
                    ]);
                }
            }

            // Update profile (upsert with EXCLUDED — kept as verbatim prepared SQL)
            $db->preparedQuery("
                INSERT INTO user_profiles (username, session_id, age, sex, location)
                VALUES (:username, :session_id, :age, :sex, :location)
                ON CONFLICT (username, session_id)
                DO UPDATE SET
                    age = EXCLUDED.age,
                    sex = EXCLUDED.sex,
                    location = EXCLUDED.location
            ", [
                'username'   => $username,
                'session_id' => $sessionId,
                'age'        => $age,
                'sex'        => $sex,
                'location'   => $location,
            ]);

            return Response::json([
                'success' => true,
                'message' => 'Profile updated successfully',
            ]);
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log("Error updating profile: " . $e->getMessage(), 'radiochatbox');
            return Response::json([
                'success' => false,
                'error'   => 'Failed to update profile',
            ], 500);
        }
    }

    /**
     * POST /api/upload-photo — replaces public/api/upload-photo.php.
     *
     * Accepts a multipart form (username, recipient, sessionId + a "photo"
     * file) and hands it to PhotoService::uploadPhoto().
     *
     * Success: 200 {success:true, attachment:<result>, message:"Photo uploaded successfully"}.
     * Errors: 400 (missing fields / no file, InvalidArgumentException,
     * RuntimeException from the service), 500 (any other error, with the
     * legacy debug/file/line diagnostics).
     */
    #[Route('/api/upload-photo', methods: 'POST', name: 'profile.upload-photo')]
    public function uploadPhoto(): Response
    {
        try {
            // Get form data
            $error = Validate::check($_POST, [
                'username'  => 'required',
                'recipient' => 'required',
            ], [
                'username.required'  => 'Username and recipient are required',
                'recipient.required' => 'Username and recipient are required',
            ]);
            if ($error) {
                return $error;
            }

            $username  = $_POST['username'];
            $recipient = $_POST['recipient'];
            $sessionId = $_POST['sessionId'] ?? '';

            if (!isset($_FILES['photo'])) {
                throw new \InvalidArgumentException('No photo file provided');
            }

            // Get client IP
            $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';

            // Upload photo
            $photoService = new PhotoService();
            $result = $photoService->uploadPhoto($_FILES['photo'], $username, $recipient, $ipAddress);

            return Response::json([
                'success'    => true,
                'attachment' => $result,
                'message'    => 'Photo uploaded successfully',
            ]);
        } catch (\InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        } catch (\RuntimeException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            \Pramnos\Logs\Logger::log("Photo upload error: " . $e->getMessage(), 'radiochatbox');
            return Response::json([
                'error' => 'Failed to upload photo',
                'debug' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ], 500);
        }
    }
}
