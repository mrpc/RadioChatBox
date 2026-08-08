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

            // A station that verifies addresses cannot accept accounts without
            // one: the account would be permanently unverified, unable to reset
            // its own password, and invisible to every mail the feature exists to
            // send. Optional only while verification is off.
            $settings = new \RadioChatBox\Services\SettingsService();
            $verificationOn = $settings->get('email_verification_enabled', 'false') === 'true';
            if ($verificationOn && $email === '') {
                return Response::json(['error' => 'An email address is required'], 400);
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

            $newUserId = (int) ($result['user']['userid'] ?? $result['user']['id'] ?? 0);

            // Marketing consent is opt-in and recorded, not inferred: the ledger
            // keeps when it was given and from which address, which is what makes
            // it defensible later. Silence means no consent.
            if ($newUserId > 0 && !empty($input['marketing_opt_in'])) {
                (new \RadioChatBox\Services\ConsentService())->grant($newUserId, 'marketing_email');
            }

            // Send a verification email when the feature is on and an email exists.
            if ($email !== '' && $newUserId > 0 && $verificationOn) {
                try {
                    $token = (new \RadioChatBox\Services\EmailVerificationService())->issue($newUserId);
                    $this->sendVerificationEmail($email, $username, $token);
                } catch (\Throwable $e) {
                    \Pramnos\Logs\Logger::log('verification email on register failed: ' . $e->getMessage(), 'radiochatbox');
                }
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
     * POST /api/email/verify — {token}. Marks the account's email verified and
     * consumes the token. 200 {success}; bad/expired token -> 400.
     */
    #[Route('/api/email/verify', methods: 'POST', name: 'auth.email.verify')]
    public function verifyEmail(): Response
    {
        try {
            $token = trim((string) ($_POST['token'] ?? ''));
            if ($token === '') {
                return Response::json(['error' => 'A verification token is required'], 400);
            }
            $userId = (new \RadioChatBox\Services\EmailVerificationService())->verify($token);
            if ($userId === null) {
                return Response::json(['error' => 'This verification link is invalid or has expired'], 400);
            }
            return Response::json(['success' => true, 'message' => 'Your email has been verified.']);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('AuthController::verifyEmail failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * POST /api/email/resend — {username, session_id}. Re-sends the verification
     * email for the signed-in account. 200 {success}; bad session/account -> 403;
     * already verified -> 200 {success, already_verified}.
     */
    #[Route('/api/email/resend', methods: 'POST', name: 'auth.email.resend')]
    public function resendVerification(): Response
    {
        try {
            $username = trim((string) ($_POST['username'] ?? ''));
            $sessionId = trim((string) ($_POST['session_id'] ?? ''));
            if ($username === '' || $sessionId === '') {
                return Response::json(['error' => 'username and session_id are required'], 400);
            }
            $chat = new ChatService();
            if ($chat->getSessionInfo($username, $sessionId) === null) {
                return Response::json(['error' => 'Invalid session'], 403);
            }
            $userId = $chat->accountUserId($username);
            if ($userId === null) {
                return Response::json(['error' => 'Not a registered account'], 403);
            }

            $verifier = new \RadioChatBox\Services\EmailVerificationService();
            if ($verifier->isVerified($userId)) {
                return Response::json(['success' => true, 'already_verified' => true]);
            }

            $row = Database::getInstance()->preparedQuery('SELECT email FROM users WHERE userid = :u LIMIT 1', ['u' => $userId]);
            $emailAddr = $row ? (string) $row->fetchColumn() : '';
            if ($emailAddr === '') {
                return Response::json(['error' => 'No email address on file'], 400);
            }

            $token = $verifier->issue($userId);
            $this->sendVerificationEmail($emailAddr, $username, $token);
            return Response::json(['success' => true]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('AuthController::resendVerification failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Email a verification link. The send is best-effort — the account is created
     * and the token stays valid whether or not the mail goes out, so a broken
     * mailer must not fail the registration. The failure is not lost, though:
     * Email::recordMail() writes a status-0 row with the error into `mails`,
     * which the admin's Email Log reads (and badges in the sidebar).
     */
    private function sendVerificationEmail(string $to, string $username, string $token): void
    {
        $settings = new \RadioChatBox\Services\SettingsService();
        $base = rtrim((string) ($settings->get('siteurl') ?: ($_SERVER['HTTP_ORIGIN'] ?? '')), '/');
        $link = $base . '/?verify=' . urlencode($token);
        $brand = (string) ($settings->get('brand_name') ?: ($settings->get('site_title') ?: 'RadioChatBox'));

        $body = '<p>Hi ' . htmlspecialchars($username) . ',</p>'
            . '<p>Please confirm your email address for ' . htmlspecialchars($brand)
            . ' by clicking the link below (valid for 24 hours):</p>'
            . '<p><a href="' . htmlspecialchars($link) . '">' . htmlspecialchars($link) . '</a></p>';

        (new \RadioChatBox\Services\MailService($settings))
            ->send($to, $brand . ' — verify your email', $body, 'verification');
    }

    /**
     * POST /api/password/forgot — {identifier}. Starts a password reset: finds the
     * account by username OR email and, if found, issues a single-use token and
     * emails the reset link. Always returns a generic success (never reveals
     * whether an account exists). 200 {success, message}.
     */
    #[Route('/api/password/forgot', methods: 'POST', name: 'auth.password.forgot')]
    public function forgotPassword(): Response
    {
        $generic = Response::json([
            'success' => true,
            'message' => 'If an account matches, a reset link has been sent.',
        ]);
        try {
            $identifier = trim((string) ($_POST['identifier'] ?? $_POST['username'] ?? $_POST['email'] ?? ''));
            if ($identifier === '') {
                return $generic;
            }

            $row = Database::getInstance()->preparedQuery(
                'SELECT userid, username, email FROM users
                 WHERE (LOWER(username) = LOWER(:id) OR LOWER(email) = LOWER(:id)) AND is_active = TRUE
                 LIMIT 1',
                ['id' => $identifier]
            );
            $user = ($row && $row->numRows > 0) ? $row->fields : null;
            if ($user === null || empty($user['email'])) {
                // No account, or no email on file to send to → generic response.
                return $generic;
            }

            $token = (new \RadioChatBox\Services\PasswordResetService())->issue((int) $user['userid']);
            $this->sendResetEmail((string) $user['email'], (string) $user['username'], $token);

            return $generic;
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('AuthController::forgotPassword failed: ' . $e->getMessage(), 'radiochatbox');
            return $generic; // never leak internal state on this endpoint
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * POST /api/password/reset — {token, password, password_confirm}. Sets a new
     * password for the account the token belongs to and consumes the token. 200
     * {success}; bad/expired token -> 400; weak/mismatched password -> 400.
     */
    #[Route('/api/password/reset', methods: 'POST', name: 'auth.password.reset')]
    public function resetPassword(): Response
    {
        try {
            $token = trim((string) ($_POST['token'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $confirm = (string) ($_POST['password_confirm'] ?? $password);

            if ($token === '') {
                return Response::json(['error' => 'A reset token is required'], 400);
            }
            if (strlen($password) < 8) {
                return Response::json(['error' => 'Password must be at least 8 characters'], 400);
            }
            if ($password !== $confirm) {
                return Response::json(['error' => 'Passwords do not match'], 400);
            }

            $service = new \RadioChatBox\Services\PasswordResetService();
            $userId = $service->resolve($token);
            if ($userId === null) {
                return Response::json(['error' => 'This reset link is invalid or has expired'], 400);
            }

            $result = (new UserService())->updateUser($userId, ['password' => $password]);
            if (empty($result['success'])) {
                return Response::json(['error' => $result['error'] ?? 'Could not update password'], 400);
            }
            $service->consume($token);

            return Response::json(['success' => true, 'message' => 'Your password has been reset — you can now log in.']);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('AuthController::resetPassword failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Email a password-reset link. Best-effort: a missing/failed mailer must not
     * change the caller's generic response (and the token still exists).
     */
    private function sendResetEmail(string $to, string $username, string $token): void
    {
        try {
            $settings = new \RadioChatBox\Services\SettingsService();
            $base = rtrim((string) ($settings->get('siteurl') ?: ($_SERVER['HTTP_ORIGIN'] ?? '')), '/');
            $link = ($base !== '' ? $base : '') . '/?reset=' . urlencode($token);
            $brand = (string) ($settings->get('brand_name') ?: ($settings->get('site_title') ?: 'RadioChatBox'));

            $body = '<p>Hi ' . htmlspecialchars($username) . ',</p>'
                . '<p>We received a request to reset your ' . htmlspecialchars($brand) . ' password. '
                . 'Click the link below to choose a new one (valid for 1 hour):</p>'
                . '<p><a href="' . htmlspecialchars($link) . '">' . htmlspecialchars($link) . '</a></p>'
                . '<p>If you did not request this, you can safely ignore this email.</p>';

            (new \RadioChatBox\Services\MailService($settings))
                ->send($to, $brand . ' — password reset', $body, 'password_reset');
        } catch (\Throwable $e) {
            // Delivery is best-effort; the reset token is already persisted.
            \Pramnos\Logs\Logger::log('password reset email failed (token still valid): ' . $e->getMessage(), 'radiochatbox');
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
