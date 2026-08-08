<?php

namespace RadioChatBox\Controllers;

use Pramnos\Database\Database;
use Pramnos\Http\Request;
use Pramnos\Http\Response;
use Pramnos\Routing\Attributes\Route;
use RadioChatBox\Services\ChatService;
use RadioChatBox\Services\ConsentService;
use RadioChatBox\Services\SettingsService;

/**
 * What a signed-in account can do about itself from the chat.
 *
 * Two-factor, passkeys and email verification already had endpoints — what was
 * missing was anywhere to reach them, and the two things with no endpoint at
 * all: changing a password you still know, and answering the newsletter
 * question after registration. This controller adds those and an overview the
 * settings panel opens with, so one request tells it what to show rather than
 * five.
 *
 * Authentication follows the convention the 2FA and passkey endpoints already
 * use: the chat username plus its session id, checked against the live session.
 */
final class AccountController
{
    /**
     * GET /api/account/overview?username=&session_id= — everything the account
     * panel needs to draw itself in one round trip.
     *
     * 200 {success, account:{username, email, email_verified, verification_offered,
     * marketing_opt_in, marketing_offered}}.
     */
    #[Route('/api/account/overview', methods: 'GET', name: 'account.overview')]
    public function overview(): Response
    {
        [$userId, $err] = $this->resolveAccount(
            (string) Request::getInstance()->get('username', '', 'get'),
            (string) Request::getInstance()->get('session_id', '', 'get')
        );
        if ($err !== null) {
            return $err;
        }

        try {
            $row = Database::getInstance()->preparedQuery(
                'SELECT username, email, email_verified_at FROM users WHERE userid = ? LIMIT 1',
                [$userId]
            );
            $user = $row ? $row->fetch() : null;
            if (!$user) {
                return Response::json(['error' => 'Account not found'], 404);
            }

            $settings = new SettingsService();

            return Response::json([
                'success' => true,
                'account' => [
                    'username'       => (string) $user['username'],
                    'email'          => (string) ($user['email'] ?? ''),
                    'email_verified' => !empty($user['email_verified_at']),
                    // Only offer to re-send when the station actually verifies.
                    'verification_offered' => $settings->get('email_verification_enabled', 'false') === 'true',
                    'marketing_opt_in'     => (new ConsentService())->has($userId, ConsentService::MARKETING_EMAIL),
                    'marketing_offered'    => $this->marketingOffered($settings),
                    'marketing_text'       => $this->marketingText($settings),
                ],
            ]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('AccountController::overview failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * POST /api/account/password — {username, session_id, current_password,
     * new_password}. Change a password you still know.
     *
     * Distinct from the reset flow, which exists for the case where you do not.
     * The current password is required even though the session is already
     * proven: a session can be a borrowed laptop, and changing the password is
     * how someone would lock the owner out of their own account.
     */
    #[Route('/api/account/password', methods: 'POST', name: 'account.password')]
    public function changePassword(): Response
    {
        [$userId, $err] = $this->resolveAccount(
            (string) ($_POST['username'] ?? ''),
            (string) ($_POST['session_id'] ?? '')
        );
        if ($err !== null) {
            return $err;
        }

        $current = (string) ($_POST['current_password'] ?? '');
        $new     = (string) ($_POST['new_password'] ?? '');

        if ($current === '' || $new === '') {
            return Response::json(['error' => 'Both your current and new password are required'], 400);
        }
        if (strlen($new) < 8) {
            return Response::json(['error' => 'The new password must be at least 8 characters'], 400);
        }
        if ($current === $new) {
            return Response::json(['error' => 'That is the password you already have'], 400);
        }

        try {
            $db = Database::getInstance();
            $row = $db->preparedQuery('SELECT password FROM users WHERE userid = ? LIMIT 1', [$userId]);
            $hash = $row ? (string) $row->fetchColumn() : '';

            if ($hash === '' || !password_verify($current, $hash)) {
                return Response::json(['error' => 'That is not your current password'], 403);
            }

            $db->preparedQuery(
                'UPDATE users SET password = ? WHERE userid = ?',
                [password_hash($new, PASSWORD_BCRYPT), $userId]
            );

            return Response::json(['success' => true, 'message' => 'Password changed.']);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('AccountController::changePassword failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * POST /api/account/marketing — {username, session_id, opt_in}. Grant or
     * withdraw consent to marketing email.
     *
     * Withdrawal has to be as easy as granting, which is most of why this
     * exists: the tick box at registration was the only way in, and there was no
     * way back out at all.
     */
    #[Route('/api/account/marketing', methods: 'POST', name: 'account.marketing')]
    public function marketing(): Response
    {
        [$userId, $err] = $this->resolveAccount(
            (string) ($_POST['username'] ?? ''),
            (string) ($_POST['session_id'] ?? '')
        );
        if ($err !== null) {
            return $err;
        }

        $optIn = in_array(
            strtolower(trim((string) ($_POST['opt_in'] ?? ''))),
            ['1', 'true', 'on', 'yes'],
            true
        );

        try {
            $consent = new ConsentService();
            if ($optIn) {
                $consent->grant($userId, ConsentService::MARKETING_EMAIL);
            } else {
                $consent->revoke($userId, ConsentService::MARKETING_EMAIL);
            }

            return Response::json(['success' => true, 'marketing_opt_in' => $optIn]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('AccountController::marketing failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /** Whether the station asks about marketing at all (see the registration form). */
    private function marketingOffered(SettingsService $settings): bool
    {
        $raw = $settings->get('marketing_consent_enabled', '');
        if ($raw === '' || $raw === null) {
            return true; // on unless switched off, as at registration
        }

        return in_array(strtolower(trim((string) $raw)), ['1', 'true', 'on', 'yes'], true);
    }

    /** The station's own wording, or the neutral default the join form uses. */
    private function marketingText(SettingsService $settings): string
    {
        $text = trim((string) $settings->get('marketing_consent_text', ''));

        return $text !== '' ? $text : 'Email me occasional updates. You can unsubscribe at any time.';
    }

    /**
     * Resolve the signed-in account behind a chat session.
     *
     * Same shape as TwoFactorController's: a live session proves the person, and
     * an account id is required because a guest has nothing to configure.
     *
     * @return array{0:int, 1:Response|null}
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
            return [0, Response::json(['error' => 'This needs a registered account'], 403)];
        }

        return [$userId, null];
    }
}
