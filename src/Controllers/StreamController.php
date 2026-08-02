<?php

namespace RadioChatBox\Controllers;

use Pramnos\Broadcasting\Drivers\RedisDriver;
use Pramnos\Broadcasting\Drivers\SubscribableDriverInterface;
use Pramnos\Http\Request;
use Pramnos\Http\Sse\SseWriter;
use Pramnos\Http\StreamedResponse;
use Pramnos\Redis\ConnectionManager;
use Pramnos\Routing\Attributes\Route;
use RadioChatBox\Services\ChatService;
use RadioChatBox\Services\ReactionService;
use RadioChatBox\Services\RealtimeToken;

/**
 * GET /api/stream — the public Server-Sent Events feed.
 *
 * Migrated from public/api/stream.php onto the framework SSE stack: a
 * StreamedResponse producing an event-stream, driven by the framework's Redis
 * broadcasting backplane (SubscribableDriverInterface). Behaviour is preserved
 * exactly — the initial history/users/config snapshot, the named events
 * (message/clear/reaction/message_deleted/users/private), per-viewer private
 * filtering, keep-alive pings, and the ~95s max-runtime reconnect that keeps the
 * connection under Cloudflare's edge timeout.
 *
 * The legacy publishers still publish raw JSON to the same Redis channels; the
 * RedisDriver delivers those non-enveloped messages as (channel, '', payload),
 * so no publisher change is required for this migration.
 */
final class StreamController
{
    /**
     * @param SubscribableDriverInterface|null $driver Injectable backplane for
     *   tests; defaults to the app's Redis subscribe driver so production is
     *   unchanged.
     */
    public function __construct(private ?SubscribableDriverInterface $driver = null)
    {
    }

    #[Route('/api/stream', methods: 'GET', name: 'stream.index')]
    public function index(): StreamedResponse
    {
        // Prefer the cryptographically-verified username from the realtime token
        // (EventSource cannot send headers, so it rides in the query string). A
        // valid token is authoritative — this is what stops a client passing
        // `?username=someone_else` and receiving that user's private messages. The
        // raw `?username=` remains a backward-compat fallback for clients that do
        // not yet send a token (they keep today's behaviour until the client ships
        // the token); once every client sends one the fallback can be dropped.
        $token       = (string) Request::getInstance()->get('token', '', 'get');
        $claims      = $token !== '' ? (new RealtimeToken())->verify($token) : null;
        $verified    = $claims['u'] ?? null;
        $rawUsername = (string) Request::getInstance()->get('username', '', 'get');
        $username    = $verified ?? ($rawUsername !== '' ? $rawUsername : null);
        // The token also carries the session id, so a live connection can drive
        // presence server-side (in addition to the client's HTTP heartbeat).
        $sessionId   = $claims['sid'] ?? null;
        $clientIp    = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
        $userAgent   = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $chatService = new ChatService();
        $chatMode    = $chatService->getSetting('chat_mode', 'public');
        $prefix      = ConnectionManager::getInstance()->prefix();

        $publicMode  = $chatMode === 'public' || $chatMode === 'both';
        $privateMode = $chatMode === 'private' || $chatMode === 'both';

        $channels = ['chat:user_updates'];
        if ($publicMode) {
            $channels[] = 'chat:updates';
        }
        if ($privateMode) {
            $channels[] = 'chat:private_messages';
        }

        // Reuse RadioChatBox's dedicated subscribe connection + key prefix; the
        // driver prefixes channels on subscribe and strips the prefix on delivery.
        $driver = $this->driver
            ?? new RedisDriver(['prefix' => $prefix], static fn () => ConnectionManager::getInstance()->newConnection());

        return StreamedResponse::sse(function (SseWriter $sse) use ($username, $sessionId, $clientIp, $userAgent, $chatService, $chatMode, $publicMode, $driver, $channels) {
            $sse->comment('SSE connection established');

            // The live connection is itself proof of presence. A connected client can
            // drive presence server-side (in addition to its HTTP heartbeat, which
            // stays as the safety net). We refresh on connect and then on every idle
            // tick (~pingInterval, below) via onTick — so an active viewer stays
            // online within ~20s even if their HTTP heartbeat lags or stalls, instead
            // of only refreshing on the ~95s reconnect. The connect also records the
            // device UA so it shows immediately, not only after the first heartbeat.
            $canTrackPresence = $username !== null && $sessionId !== null && $sessionId !== '';
            $refreshPresence  = function (string $ua = '') use ($canTrackPresence, $chatService, $username, $sessionId, $clientIp): void {
                if ($canTrackPresence) {
                    $chatService->refreshPresence($username, $sessionId, $clientIp, null, $ua);
                }
            };
            $refreshPresence($userAgent);

            // Initial snapshot.
            if ($publicMode) {
                $history = (new ReactionService())->attachToMessages($chatService->getHistory(50), $username);
                $sse->event('history', $history);
            }
            $allUsers = $chatService->getAllUsers();
            $sse->event('users', ['count' => count($allUsers), 'users' => $allUsers]);
            $sse->event('config', ['chat_mode' => $chatMode]);

            $sse->stream(
                $driver,
                $channels,
                function (string $channel, string $event, array $payload, SseWriter $writer) use ($username): void {
                    switch ($channel) {
                        case 'chat:updates':
                            $type = $payload['type'] ?? null;
                            $name = match ($type) {
                                'clear'           => 'clear',
                                'message_deleted' => 'message_deleted',
                                'reaction'        => 'reaction',
                                'now_playing'     => 'now_playing',
                                'config'          => 'config',
                                default           => 'message',
                            };
                            $writer->event($name, $payload);
                            break;

                        case 'chat:user_updates':
                            $writer->event('users', $payload);
                            break;

                        case 'chat:private_messages':
                            if ($username !== null
                                && (($payload['to_username'] ?? null) === $username
                                    || ($payload['from_username'] ?? null) === $username)) {
                                $writer->event('private', $payload);
                            }
                            break;
                    }
                },
                maxRuntime: 95,
                pingInterval: 20,
                // Keep presence fresh for the still-connected viewer on every idle
                // tick (~20s), not just on connect/reconnect. Additive to the client
                // HTTP heartbeat — never a replacement for it.
                onTick: function (SseWriter $writer) use ($refreshPresence): void {
                    $refreshPresence();
                },
            );
        });
    }
}
