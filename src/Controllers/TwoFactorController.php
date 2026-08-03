<?php

namespace RadioChatBox\Controllers;

use Pramnos\Auth\TwoFactorAuthService;
use Pramnos\Http\Request;
use Pramnos\Http\Response;
use Pramnos\Routing\Attributes\Route;
use RadioChatBox\Services\ChatService;

/**
 * TOTP two-factor authentication management for the signed-in account, thin app
 * endpoints over the framework's TwoFactorAuthService. Every call is verified
 * against the caller's chat session AND requires a real (registered) account.
 */
final class TwoFactorController
{
    /** GET /api/2fa/status?username=&session_id= — enabled + backup-code count. */
    #[Route('/api/2fa/status', methods: 'GET', name: '2fa.status')]
    public function status(): Response
    {
        [$userId, $err] = $this->resolveAccount(
            (string) Request::getInstance()->get('username', '', 'get'),
            (string) Request::getInstance()->get('session_id', '', 'get')
        );
        if ($err !== null) {
            return $err;
        }
        return Response::json(['success' => true, 'status' => (new TwoFactorAuthService())->getStatus($userId)]);
    }

    /**
     * POST /api/2fa/setup — begin enrollment: returns the secret + QR to scan and
     * one-time backup codes. Body: {username, session_id}.
     */
    #[Route('/api/2fa/setup', methods: 'POST', name: '2fa.setup')]
    public function setup(): Response
    {
        [$userId, $err] = $this->resolveAccount((string) ($_POST['username'] ?? ''), (string) ($_POST['session_id'] ?? ''));
        if ($err !== null) {
            return $err;
        }
        $service = new TwoFactorAuthService();
        if ($service->isEnabled($userId)) {
            return Response::json(['error' => 'Two-factor is already enabled. Disable it first to re-enroll.'], 409);
        }
        $setup = $service->startSetup($userId, (string) $_POST['username'], 'RadioChatBox');
        return Response::json(['success' => true, 'setup' => $setup]);
    }

    /**
     * POST /api/2fa/verify-setup — confirm enrollment with a code from the app.
     * Body: {username, session_id, code}.
     */
    #[Route('/api/2fa/verify-setup', methods: 'POST', name: '2fa.verify-setup')]
    public function verifySetup(): Response
    {
        [$userId, $err] = $this->resolveAccount((string) ($_POST['username'] ?? ''), (string) ($_POST['session_id'] ?? ''));
        if ($err !== null) {
            return $err;
        }
        $code = trim((string) ($_POST['code'] ?? ''));
        if ($code === '') {
            return Response::json(['error' => 'A verification code is required'], 400);
        }
        if (!(new TwoFactorAuthService())->completeSetup($userId, $code)) {
            return Response::json(['error' => 'Invalid or expired code'], 400);
        }
        return Response::json(['success' => true]);
    }

    /**
     * POST /api/2fa/disable — turn off 2FA. Requires a current code (or backup
     * code) so a hijacked session can't silently disable it. Body:
     * {username, session_id, code}.
     */
    #[Route('/api/2fa/disable', methods: 'POST', name: '2fa.disable')]
    public function disable(): Response
    {
        [$userId, $err] = $this->resolveAccount((string) ($_POST['username'] ?? ''), (string) ($_POST['session_id'] ?? ''));
        if ($err !== null) {
            return $err;
        }
        $service = new TwoFactorAuthService();
        if (!$service->isEnabled($userId)) {
            return Response::json(['success' => true]); // already off
        }
        $code = trim((string) ($_POST['code'] ?? ''));
        if ($code === '' || !$service->verifyCode($userId, $code)) {
            return Response::json(['error' => 'A valid current code is required to disable 2FA'], 400);
        }
        $service->disable($userId);
        return Response::json(['success' => true]);
    }

    /**
     * Verify the session and resolve the registered account's userid.
     *
     * @return array{0:int, 1:?Response} [userId, errorResponse]. errorResponse is
     *   non-null when the caller should return it (bad input / session / not an account).
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
            return [0, Response::json(['error' => 'Two-factor requires a registered account'], 403)];
        }
        return [$userId, null];
    }
}
