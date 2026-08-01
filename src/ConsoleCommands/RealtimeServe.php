<?php

namespace RadioChatBox\ConsoleCommands;

use Pramnos\Broadcasting\Auth\PusherAuthorizer;
use Pramnos\Broadcasting\LocalBroadcastServer;
use Pramnos\Broadcasting\RedisSubscriberSocket;
use Pramnos\Console\CommandBase;
use Pramnos\Redis\ConnectionManager;
use RadioChatBox\Services\RealtimeToken;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `realtime:serve` — the optional WebSocket transport worker.
 *
 * A pure-PHP (no-Ratchet) Pusher-protocol server fed from RadioChatBox's Redis
 * pub/sub backplane — the SAME channels the SSE stream reads, so both transports
 * carry an identical feed. Supervised by the daemon orchestrator only when the
 * `realtime_websocket_enabled` setting is on; otherwise the app runs SSE exactly
 * as before.
 *
 * Channel routing (via LocalBroadcastServer::useIngestRouter):
 *  - `chat:updates` / `chat:user_updates` are PUBLIC — delivered under their
 *    logical name (the Redis key prefix is stripped) with the same event names
 *    the SSE stream emits (message/clear/reaction/message_deleted/users).
 *  - `chat:private_messages` is fanned out ONLY to the sender's and recipient's
 *    per-user `private-pm-<username>` channels, so a client subscribed to its own
 *    private channel never receives another user's DM. Those channels are Pusher
 *    private channels: the PusherAuthorizer (keyed with the same per-install
 *    RealtimeToken secret) rejects a subscription without a valid signature from
 *    /api/broadcasting/auth.
 *
 * Bind host/port come from REALTIME_WS_HOST / REALTIME_WS_PORT (per-install .env;
 * two installs on one host MUST use distinct ports). The public scheme/host/port
 * a browser dials are advertised separately by /api/realtime-config.
 */
final class RealtimeServe extends CommandBase
{
    protected static $defaultName = 'realtime:serve';

    private ?LocalBroadcastServer $server = null;

    protected function getJobName(): string
    {
        return 'realtime';
    }

    protected function configure(): void
    {
        // Defaults resolved from settings at run time (see execute); an explicit
        // --host/--port still overrides.
        $this->setName('realtime:serve')
            ->setDescription('WebSocket realtime worker (public chat feed + per-user private DMs) fed from Redis pub/sub')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Bind address (default: realtime_ws_bind_host setting)')
            ->addOption('port', 'p', InputOption::VALUE_REQUIRED, 'Listen port (default: realtime_ws_bind_port setting)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Admin settings are the source of truth (env/default as fallback); an
        // explicit CLI option wins over both.
        $cfg    = \RadioChatBox\Services\RealtimeSettings::resolve();
        $host   = (string) ($input->getOption('host') ?: $cfg['bindHost']);
        $port   = (int) ($input->getOption('port') ?: $cfg['bindPort']);
        $appKey = $cfg['appKey'];
        $secret = (new RealtimeToken())->secret(); // same per-install secret the token signs with

        $cm     = ConnectionManager::getInstance();
        $prefix = $cm->prefix();

        // Subscribe to the prefixed Redis channels (the driver publishes under the
        // connection prefix); the router maps them back to logical WS channels.
        $logical   = ['chat:updates', 'chat:user_updates', 'chat:private_messages'];
        $subscribe = array_map(static fn (string $c): string => $prefix . $c, $logical);

        $redisConfig = [
            'host'     => $cm->host(),
            'port'     => $cm->port(),
            'database' => $cm->database(),
            'password' => $cm->password() ?? '',
        ];

        $server = new LocalBroadcastServer($appKey, null, new PusherAuthorizer($appKey, $secret));
        $server->useRedisIngest(new RedisSubscriberSocket($redisConfig, $subscribe));
        $server->useIngestRouter(static function (string $channel, string $event, $payload) use ($prefix): array {
            return self::routeMessage($prefix, $channel, $event, $payload);
        });

        $this->server = $server;
        $this->installStopSignals(function () use ($output): void {
            $output->writeln('<comment>realtime:serve — stop signal, shutting down.</comment>');
            $this->server?->stop();
        });

        $output->writeln("<info>realtime:serve</info> — ws://{$host}:{$port}  (app-key: <comment>{$appKey}</comment>)");
        $output->writeln('  Redis ingest: <comment>' . implode(', ', $subscribe) . '</comment>');

        try {
            $server->run($host, $port);
        } catch (\RuntimeException $e) {
            $output->writeln("<error>Error: {$e->getMessage()}</error>");
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Map one ingested Redis message to zero or more WS deliveries. Pure + static
     * so it is unit-testable without a socket.
     *
     * @param  mixed $payload
     * @return list<array{0:string,1:string,2:mixed}>
     */
    public static function routeMessage(string $prefix, string $channel, string $event, $payload): array
    {
        $logical = str_starts_with($channel, $prefix) ? substr($channel, strlen($prefix)) : $channel;

        // Private messages: deliver ONLY to the two participants' private channels.
        if ($logical === 'chat:private_messages') {
            $to    = is_array($payload) ? (string) ($payload['to_username'] ?? '') : '';
            $from  = is_array($payload) ? (string) ($payload['from_username'] ?? '') : '';
            $routes = [];
            if ($to !== '') {
                $routes[] = ['private-pm-' . $to, 'private', $payload];
            }
            if ($from !== '' && $from !== $to) {
                $routes[] = ['private-pm-' . $from, 'private', $payload];
            }
            return $routes;
        }

        // Public channels: keep the SSE stream's event naming so one client handler
        // serves both transports.
        if ($logical === 'chat:user_updates') {
            return [['chat:user_updates', 'users', $payload]];
        }

        if ($logical === 'chat:updates') {
            $type = is_array($payload) ? ($payload['type'] ?? null) : null;
            $name = match ($type) {
                'clear'           => 'clear',
                'message_deleted' => 'message_deleted',
                'reaction'        => 'reaction',
                default           => 'message',
            };
            return [['chat:updates', $name, $payload]];
        }

        // Unknown channel: pass through under its logical name.
        return [[$logical, $event, $payload]];
    }
}
