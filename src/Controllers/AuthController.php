<?php

namespace RadioChatBox\Controllers;

use InvalidArgumentException;
use Pramnos\Http\Response;
use Pramnos\Routing\Attributes\Route;
use RadioChatBox\Services\ChatService;
use RadioChatBox\Database;
use RadioChatBox\Http\Validate;
use RadioChatBox\Services\UserService;

/**
 * Authentication endpoints: login / logout / register.
 *
 * Migrated from public/api/{login,logout,register}.php. The framework Request
 * has already decoded the JSON body into $_POST, so we read it there (re-reading
 * php://input would come back empty). Behaviour is preserved exactly: same JSON
 * keys, status codes and error strings the legacy files returned.
 */
final class AuthController
{
    /**
     * POST /api/login — authenticate a registered user and bind the chat
     * session to their account.
     *
     * Replaces public/api/login.php. Empty/invalid body -> 400 "Invalid JSON";
     * missing username/password/sessionId -> 400; bad credentials -> 401
     * "Invalid username or password"; otherwise 500. Success (200) returns
     * {success, message, user:{id, username, display_name, role}}.
     */
    #[Route('/api/login', methods: 'POST', name: 'auth.login')]
    public function login(): Response
    {
        try {
            $input = $_POST;

            if (empty($input)) {
                throw new InvalidArgumentException('Invalid JSON');
            }

            $error = Validate::check($input, [
                'username'  => 'required',
                'password'  => 'required',
                'sessionId' => 'required',
            ], [
                'username.required'  => 'Username, password, and session ID are required',
                'password.required'  => 'Username, password, and session ID are required',
                'sessionId.required' => 'Username, password, and session ID are required',
            ]);
            if ($error) {
                return $error;
            }

            $username  = (string) $input['username'];
            $password  = (string) $input['password'];
            $sessionId = (string) $input['sessionId'];

            $userService = new UserService();

            // Authenticate user
            $user = $userService->authenticate($username, $password);

            if (!$user) {
                return Response::json(['error' => 'Invalid username or password'], 401);
            }

            // Link the session to this authenticated user
            $db = Database::getDb();
            $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';

            // Create or update session with user_id (upsert with NOW() expressions
            // in both VALUES and DO UPDATE — kept as verbatim prepared SQL).
            $db->preparedQuery(
                'INSERT INTO sessions (username, session_id, ip_address, user_id, last_heartbeat, joined_at)
                 VALUES (:username, :session_id, :ip_address, :user_id, NOW(), NOW())
                 ON CONFLICT (username, session_id) DO UPDATE SET
                     ip_address = :ip_address,
                     user_id = :user_id,
                     last_heartbeat = NOW()',
                [
                    'username' => $user['username'],
                    'session_id' => $sessionId,
                    'ip_address' => $ipAddress,
                    'user_id' => $user['id'],
                ]
            );

            return Response::json([
                'success' => true,
                'message' => 'Login successful',
                'user' => [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'display_name' => $user['display_name'] ?? null,
                    'role' => $user['role'],
                ],
            ]);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            \RadioChatBox\Log::write($e->getMessage());
            return Response::json(['error' => 'Internal server error'], 500);
        }
    }

    /**
     * POST /api/logout — end a chat session.
     *
     * Replaces public/api/logout.php. Empty/invalid body -> 400 "Invalid JSON";
     * missing sessionId -> 400 "Session ID is required"; logout failure -> 500
     * "Failed to logout user"; unexpected error -> 500. Success (200) returns
     * {success, message}.
     */
    #[Route('/api/logout', methods: 'POST', name: 'auth.logout')]
    public function logout(): Response
    {
        try {
            $input = $_POST;

            if (empty($input)) {
                throw new InvalidArgumentException('Invalid JSON');
            }

            $error = Validate::check($input, ['sessionId' => 'required'], [
                'sessionId.required' => 'Session ID is required',
            ]);
            if ($error) {
                return $error;
            }

            $sessionId = (string) $input['sessionId'];

            $chatService = new ChatService();
            $success = $chatService->logoutUser($sessionId);

            if ($success) {
                return Response::json([
                    'success' => true,
                    'message' => 'User logged out successfully',
                ]);
            }

            return Response::json(['error' => 'Failed to logout user'], 500);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            \RadioChatBox\Log::write($e->getMessage());
            return Response::json(['error' => 'Internal server error'], 500);
        }
    }

    /**
     * POST /api/register — register a nickname for a chat session (with optional
     * profile), then rebalance fake users.
     *
     * Replaces public/api/register.php. Empty/invalid body -> 400 "Invalid JSON";
     * missing username/sessionId -> 400; when require_profile is on, missing
     * age/location/sex -> 400; out-of-range age -> 400; taken/banned nickname ->
     * 409; unexpected error -> 500. Success (200) returns {success, message}.
     */
    #[Route('/api/register', methods: 'POST', name: 'auth.register')]
    public function register(): Response
    {
        try {
            $input = $_POST;

            if (empty($input)) {
                return Response::json(['error' => 'Invalid JSON'], 400);
            }

            $chatService    = new ChatService();
            $requireProfile = $chatService->getSetting('require_profile', 'false') === 'true';

            // Field order matters: Validate surfaces the FIRST failing rule's
            // message as `error`, so username/sessionId precede the profile fields.
            // age is numeric even as a form string (framework Validator handles it).
            $rules = [
                'username'  => 'required',
                'sessionId' => 'required',
                'age'       => $requireProfile ? 'required|integer|min:18|max:120' : 'nullable|integer|min:18|max:120',
            ];
            if ($requireProfile) {
                $rules['location'] = 'required';
                $rules['sex']      = 'required';
            }
            $messages = [
                'username.required'  => 'Username and session ID are required',
                'sessionId.required' => 'Username and session ID are required',
                'age.required'       => 'Age, location, and sex are required',
                'location.required'  => 'Age, location, and sex are required',
                'sex.required'       => 'Age, location, and sex are required',
                'age.integer'        => 'Age must be between 18 and 120',
                'age.min'            => 'Age must be between 18 and 120',
                'age.max'            => 'Age must be between 18 and 120',
            ];
            if ($error = Validate::check($input, $rules, $messages)) {
                return $error;
            }

            $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $success = $chatService->registerUser(
                (string) $input['username'],
                (string) $input['sessionId'],
                $ipAddress,
                $input['age'] ?? null,
                $input['location'] ?? null,
                $input['sex'] ?? null
            );

            if (!$success) {
                return Response::json(['error' => 'Username is already taken or you are banned.'], 409);
            }

            // Balance fake users after a new user joins (this will publish user update)
            $chatService->balanceFakeUsers();

            return Response::json([
                'success' => true,
                'message' => 'User registered successfully',
            ]);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            \RadioChatBox\Log::write($e->getMessage());
            return Response::json(['error' => 'Internal server error'], 500);
        }
    }
}
