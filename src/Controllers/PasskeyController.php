<?php

namespace RadioChatBox\Controllers;

use Pramnos\Database\Database;
use Pramnos\Http\Request;
use Pramnos\Http\Response;
use Pramnos\Routing\Attributes\Route;
use RadioChatBox\Auth\RcbPasskeyService;
use RadioChatBox\Services\ChatService;
use RadioChatBox\Services\UserService;

/**
 * WebAuthn passkeys, thin app endpoints over the framework PasskeyService (with a
 * Redis-backed, stateless challenge store — see RcbPasskeyService).
 *
 * Enrollment + management require a verified chat session bound to a real account.
 * Passkey LOGIN is passwordless: begin → the browser signs → finish binds the
 * chat session to the resolved account (mirrors AuthController::login's output).
 */
final class PasskeyController
{
    /** POST /api/passkey/register/options {username, session_id} — creation options. */
    #[Route('/api/passkey/register/options', methods: 'POST', name: 'passkey.register.options')]
    public function registerOptions(): Response
    {
        [$userId, $err] = $this->resolveAccount((string) ($_POST['username'] ?? ''), (string) ($_POST['session_id'] ?? ''));
        if ($err !== null) {
            return $err;
        }
        try {
            $options = (new RcbPasskeyService())->beginRegistration($userId, (string) $_POST['username']);
            return $this->optionsJson($options->json);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            return Response::json(['error' => 'Could not start passkey registration: ' . $e->getMessage()], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /** POST /api/passkey/register {username, session_id, credential, label?} — finish enrollment. */
    #[Route('/api/passkey/register', methods: 'POST', name: 'passkey.register')]
    public function register(): Response
    {
        [$userId, $err] = $this->resolveAccount((string) ($_POST['username'] ?? ''), (string) ($_POST['session_id'] ?? ''));
        if ($err !== null) {
            return $err;
        }
        $credential = $this->credentialString();
        if ($credential === '') {
            return Response::json(['error' => 'credential is required'], 400);
        }
        $label = trim((string) ($_POST['label'] ?? '')) ?: null;
        try {
            $cred = (new RcbPasskeyService())->finishRegistration($userId, $credential, $label);
            return Response::json(['success' => true, 'credential' => ['id' => $cred->id, 'name' => $label]]);
        } catch (\Throwable $e) {
            return Response::json(['error' => 'Passkey registration failed: ' . $e->getMessage()], 400);
        }
    }

    /** POST /api/passkey/login/options {username} — authentication options for an account. */
    #[Route('/api/passkey/login/options', methods: 'POST', name: 'passkey.login.options')]
    public function loginOptions(): Response
    {
        $username = trim((string) ($_POST['username'] ?? ''));
        if ($username === '') {
            return Response::json(['error' => 'username is required'], 400);
        }
        $userId = (new ChatService())->accountUserId($username);
        if ($userId === null) {
            return Response::json(['error' => 'No such account'], 404);
        }
        try {
            $options = (new RcbPasskeyService())->beginAuthentication($userId);
            return $this->optionsJson($options->json);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            return Response::json(['error' => 'Could not start passkey login: ' . $e->getMessage()], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /** POST /api/passkey/login {username, credential, sessionId} — passwordless login. */
    #[Route('/api/passkey/login', methods: 'POST', name: 'passkey.login')]
    public function login(): Response
    {
        $username = trim((string) ($_POST['username'] ?? ''));
        $sessionId = trim((string) ($_POST['sessionId'] ?? $_POST['session_id'] ?? ''));
        $credential = $this->credentialString();
        if ($username === '' || $sessionId === '' || $credential === '') {
            return Response::json(['error' => 'username, sessionId and credential are required'], 400);
        }
        $expectedUserId = (new ChatService())->accountUserId($username);
        if ($expectedUserId === null) {
            return Response::json(['error' => 'No such account'], 404);
        }

        try {
            $result = (new RcbPasskeyService())->finishAuthentication($credential);
            if ($result->userId !== $expectedUserId) {
                return Response::json(['error' => 'Passkey does not match this account'], 403);
            }
        } catch (\Throwable $e) {
            return Response::json(['error' => 'Passkey authentication failed'], 401);
        }

        $user = (new UserService())->getUserById($expectedUserId);
        if ($user === null) {
            return Response::json(['error' => 'Account not found'], 404);
        }

        // Bind the chat session to the authenticated account (same as password login).
        $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        Database::getInstance()->preparedQuery(
            'INSERT INTO presence_sessions (username, session_id, ip_address, user_id, last_heartbeat, joined_at)
             VALUES (:u, :s, :ip, :uid, NOW(), NOW())
             ON CONFLICT (username, session_id) DO UPDATE SET ip_address = :ip, user_id = :uid, last_heartbeat = NOW()',
            ['u' => $user['username'], 's' => $sessionId, 'ip' => $ipAddress, 'uid' => $expectedUserId]
        );

        return Response::json([
            'success' => true,
            'user' => [
                'id'           => $expectedUserId,
                'username'     => $user['username'],
                'display_name' => $user['display_name'] ?? null,
                'role'         => $user['role'] ?? null,
            ],
        ]);
    }

    /** GET /api/passkey/list?username=&session_id= — the account's registered passkeys. */
    #[Route('/api/passkey/list', methods: 'GET', name: 'passkey.list')]
    public function list(): Response
    {
        [$userId, $err] = $this->resolveAccount(
            (string) Request::getInstance()->get('username', '', 'get'),
            (string) Request::getInstance()->get('session_id', '', 'get')
        );
        if ($err !== null) {
            return $err;
        }
        return Response::json(['success' => true, 'passkeys' => (new RcbPasskeyService())->listCredentials($userId)]);
    }

    /** POST /api/passkey/revoke {username, session_id, credential_id}. */
    #[Route('/api/passkey/revoke', methods: 'POST', name: 'passkey.revoke')]
    public function revoke(): Response
    {
        [$userId, $err] = $this->resolveAccount((string) ($_POST['username'] ?? ''), (string) ($_POST['session_id'] ?? ''));
        if ($err !== null) {
            return $err;
        }
        $credentialId = (int) ($_POST['credential_id'] ?? 0);
        if ($credentialId <= 0) {
            return Response::json(['error' => 'credential_id is required'], 400);
        }
        $ok = (new RcbPasskeyService())->revokeCredential($userId, $credentialId);
        return Response::json(['success' => $ok]);
    }

    /** The WebAuthn client response, accepted as a JSON string or an object. */
    private function credentialString(): string
    {
        $c = $_POST['credential'] ?? '';
        if (is_array($c)) {
            return (string) json_encode($c);
        }
        return trim((string) $c);
    }

    /** Return the raw options JSON (already a JSON string) with the JSON content type. */
    private function optionsJson(string $json): Response
    {
        return Response::make($json)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    /**
     * @return array{0:int, 1:?Response}
     */
    private function resolveAccount(string $username, string $sessionId): array
    {
        $username = trim($username);
        $sessionId = trim($sessionId);
        if ($username === '' || $sessionId === '') {
            return [0, Response::json(['error' => 'username and session_id are required'], 400)];
        }
        $chat = new ChatService();
        if ($chat->getSessionInfo($username, $sessionId) === null) {
            return [0, Response::json(['error' => 'Invalid session'], 403)];
        }
        $userId = $chat->accountUserId($username);
        if ($userId === null) {
            return [0, Response::json(['error' => 'Passkeys require a registered account'], 403)];
        }
        return [$userId, null];
    }
}
