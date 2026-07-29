<?php

namespace RadioChatBox\Controllers;

use Pramnos\Broadcasting\Drivers\RedisDriver;
use Pramnos\Broadcasting\SubscriptionOptions;
use Pramnos\Http\Request;
use Pramnos\Http\Sse\SseWriter;
use Pramnos\Http\StreamedResponse;
use Pramnos\Redis\ConnectionManager;
use Pramnos\Routing\Attributes\Route;
use RadioChatBox\AdminAuth;
use Pramnos\Database\Database;

/**
 * GET /api/admin/stream — the admin Server-Sent Events feed (notifications).
 *
 * Migrated from public/api/admin/stream.php. Unlike the other admin endpoints it
 * does NOT use AdminAuthMiddleware: an EventSource cannot send an Authorization
 * header, so the legacy endpoint authenticates by a session_token query param
 * (validated against a Redis admin_session:<token> entry) or a normal header,
 * and reports auth failures as an SSE error event on a 200 event-stream rather
 * than a 401 — preserved here exactly.
 *
 * After auth it sends the initial notification_count, then streams
 * `notification` events from the chat:admin_notifications channel with named
 * `ping` keep-alives and a ~95s max-runtime reconnect (Cloudflare edge limit).
 */
final class AdminStreamController
{
    #[Route('/api/admin/stream', methods: 'GET', name: 'admin.stream')]
    public function index(): StreamedResponse
    {
        return StreamedResponse::sse(function (SseWriter $sse): void {
            $currentUser = $this->authenticate();

            if ($currentUser === null) {
                $sse->event('error', ['error' => 'Unauthorized']);
                return;
            }
            if (!in_array($currentUser['role'] ?? '', ['root', 'administrator', 'owner'], true)) {
                $sse->event('error', ['error' => 'Forbidden - Admin access required']);
                return;
            }

            $sse->comment('Admin SSE connection established');

            try {
                // Initial unread notification count for this admin (stored fn).
                $result = Database::getInstance()->preparedQuery(
                    'SELECT get_unread_notification_count(?)',
                    [$currentUser['username']]
                );
                $sse->event('notification_count', ['unread_count' => (int) ($result ? $result->fetchColumn() : 0)]);

                $driver = new RedisDriver(
                    ['prefix' => ConnectionManager::getInstance()->prefix()],
                    static fn () => ConnectionManager::getInstance()->newConnection()
                );

                $driver->subscribe(
                    ['chat:admin_notifications'],
                    function (string $channel, string $event, array $payload) use ($sse): bool {
                        if (connection_aborted()) {
                            return false;
                        }
                        $sse->event('notification', $payload);
                        return true;
                    },
                    new SubscriptionOptions(
                        readTimeout: 20,
                        maxRuntime: 95,
                        onIdle: function () use ($sse): bool {
                            if (connection_aborted()) {
                                return false;
                            }
                            // Admin feed pings as a named event (not a comment).
                            $sse->event('ping', ['timestamp' => time()]);
                            return true;
                        },
                    ),
                );

                if (!connection_aborted()) {
                    $sse->event('reconnect', ['reason' => 'timeout']);
                }
            } catch (\Throwable $e) {
                \Pramnos\Logs\Logger::log('Admin stream error: ' . $e->getMessage(), 'radiochatbox');
                if (!connection_aborted()) {
                    $sse->event('error', ['error' => 'Stream error']);
                }
            }
        });
    }

    /**
     * Resolve the admin either from a normal auth header or from a Redis-backed
     * session_token query param (EventSource cannot set headers). Returns a
     * ['username','role'] array, or null when unauthenticated.
     *
     * @return array{username:string,role:string}|null
     */
    private function authenticate(): ?array
    {
        if (AdminAuth::verify()) {
            $user = AdminAuth::getCurrentUser();
            if (is_array($user)) {
                return ['username' => (string) ($user['username'] ?? ''), 'role' => (string) ($user['role'] ?? '')];
            }
        }

        $token = (string) Request::getInstance()->get('session_token', '', 'get');
        if ($token === '') {
            return null;
        }

        // The SSE stream-token lives under an UNPREFIXED admin_session:<token> key
        // (raw, non-cache; minted by AdminSystemController::createSession) — read it
        // over the shared connection, which applies no key prefix.
        $tokenData = ConnectionManager::getInstance()->connection()->get('admin_session:' . $token);
        if (!$tokenData) {
            return null;
        }
        $data = json_decode((string) $tokenData, true);
        if (is_array($data) && ($data['expires_at'] ?? 0) > time()) {
            return [
                'username' => (string) ($data['username'] ?? ''),
                'role'     => (string) ($data['role'] ?? 'administrator'),
            ];
        }

        return null;
    }
}
