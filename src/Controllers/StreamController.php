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
        $username    = (string) Request::getInstance()->get('username', '', 'get');
        $username    = $username !== '' ? $username : null;
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

        return StreamedResponse::sse(function (SseWriter $sse) use ($username, $chatService, $chatMode, $publicMode, $driver, $channels) {
            $sse->comment('SSE connection established');

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
            );
        });
    }
}
