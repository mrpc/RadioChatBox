<?php

namespace RadioChatBox\Controllers;

use InvalidArgumentException;
use Pramnos\Http\Response;
use Pramnos\Routing\Attributes\Route;
use RadioChatBox\Services\ChatService;
use Pramnos\Database\Database;
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

            // Two-factor step-up: if this account has 2FA enabled, require a valid
            // TOTP/backup code before the session is bound. The password already
            // verified above, so a second submit carries the code (stateless).
            $userId = (int) ($user['userid'] ?? 0);
            if ($userId > 0) {
                $twoFactor = new \Pramnos\Auth\TwoFactorAuthService();
                if ($twoFactor->isEnabled($userId)) {
                    $code = trim((string) ($input['code'] ?? ''));
                    if ($code === '') {
                        // Tell the client to collect a code and resubmit login + code.
                        return Response::json(['success' => false, 'twofa_required' => true]);
                    }
                    if (!$twoFactor->verifyCode($userId, $code)) {
                        return Response::json(['error' => 'Invalid two-factor code', 'twofa_required' => true], 401);
                    }
                }
            }

            // Link the session to this authenticated user
            $db = Database::getInstance();
            $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';

            // Create or update session with user_id (upsert with NOW() expressions
            // in both VALUES and DO UPDATE — kept as verbatim prepared SQL).
            $db->preparedQuery(
                'INSERT INTO presence_sessions (username, session_id, ip_address, user_id, last_heartbeat, joined_at)
                 VALUES (:username, :session_id, :ip_address, :user_id, NOW(), NOW())
                 ON CONFLICT (username, session_id) DO UPDATE SET
                     ip_address = :ip_address,
                     user_id = :user_id,
                     last_heartbeat = NOW()',
                [
                    'username' => $user['username'],
                    'session_id' => $sessionId,
                    'ip_address' => $ipAddress,
                    'user_id' => $user['userid'],
                ]
            );

            return Response::json([
                'success' => true,
                'message' => 'Login successful',
                'user' => [
                    'id' => $user['userid'],
                    'username' => $user['username'],
                    'display_name' => $user['display_name'] ?? null,
                    'role' => $user['role'],
                ],
            ]);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log($e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * POST /api/register-account — public self-registration of a real account
     * (username + password + optional email). Gated by self_registration_enabled.
     * Creates a `simple_user` and, when a sessionId is supplied, binds the chat
     * session to the new account. 200 {success, user}; feature off -> 403; bad
     * input -> 400; duplicate username -> 409.
     */
    #[Route('/api/register-account', methods: 'POST', name: 'auth.register-account')]
    public function registerAccount(): Response
    {
        try {
            $input = $_POST;
            if (empty($input)) {
                throw new InvalidArgumentException('Invalid JSON');
            }

            if ((new \RadioChatBox\Services\SettingsService())->get('self_registration_enabled', 'false') !== 'true') {
                return Response::json(['error' => 'Self-registration is disabled'], 403);
            }

            $error = Validate::check($input, [
                'username' => 'required',
                'password' => 'required',
            ], [
                'username.required' => 'Username and password are required',
                'password.required' => 'Username and password are required',
            ]);
            if ($error) {
                return $error;
            }

            $username = trim((string) $input['username']);
            $password = (string) $input['password'];
            $confirm  = (string) ($input['password_confirm'] ?? $password);
            $email    = trim((string) ($input['email'] ?? ''));
            $displayName = trim((string) ($input['display_name'] ?? '')) ?: null;

            if ($password !== $confirm) {
                return Response::json(['error' => 'Passwords do not match'], 400);
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return Response::json(['error' => 'Invalid email address'], 400);
            }

            // A brand-new account is always a plain user; staff are promoted by admins.
            $result = (new UserService())->createUser(
                $username,
                $password,
                'simple_user',
                $email !== '' ? $email : null,
                null,
                $displayName
            );

            if (empty($result['success'])) {
                $msg = $result['error'] ?? 'Registration failed';
                $status = stripos($msg, 'already exists') !== false ? 409 : 400;
                return Response::json(['error' => $msg], $status);
            }

            // Optionally bind the current chat session to the new account.
            $sessionId = trim((string) ($input['sessionId'] ?? ''));
            if ($sessionId !== '') {
                $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                Database::getInstance()->preparedQuery(
                    'INSERT INTO presence_sessions (username, session_id, ip_address, user_id, last_heartbeat, joined_at)
                     VALUES (:username, :session_id, :ip_address, :user_id, NOW(), NOW())
                     ON CONFLICT (username, session_id) DO UPDATE SET
                         ip_address = :ip_address, user_id = :user_id, last_heartbeat = NOW()',
                    [
                        'username'   => $result['user']['username'],
                        'session_id' => $sessionId,
                        'ip_address' => $ipAddress,
                        'user_id'    => $result['user']['userid'] ?? $result['user']['id'] ?? null,
                    ]
                );
            }

            return Response::json(['success' => true, 'user' => $result['user']]);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('AuthController::registerAccount failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
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
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log($e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
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
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log($e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }
}
