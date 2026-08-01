<?php

namespace RadioChatBox\Services;

/**
 * Resolves the realtime WebSocket configuration from admin settings, so the whole
 * transport is configurable from the panel with no redeploy — consistent with
 * `realtime_websocket_enabled`, which the daemon orchestrator already reads to
 * spawn the worker autonomously.
 *
 * Source of truth is the DB setting; an environment variable supplies the default
 * when the setting is unset (handy for infra-managed deploys), then a hard
 * default. The one genuinely infra-level concern — publishing the port / the TLS
 * reverse proxy — lives in the web server config, not here.
 */
final class RealtimeSettings
{
    /**
     * @return array{
     *   enabled:bool, bindHost:string, bindPort:int,
     *   scheme:string, publicHost:string, publicPort:int, appKey:string
     * }
     */
    public static function resolve(?SettingsService $settings = null): array
    {
        $s = $settings ?? new SettingsService();

        $get = static function (string $key, string $env, string $default) use ($s): string {
            $v = (string) $s->get($key, '');
            if ($v !== '') {
                return $v;
            }
            $e = (string) envvar($env, '');
            return $e !== '' ? $e : $default;
        };

        return [
            'enabled'    => $s->get('realtime_websocket_enabled', 'false') === 'true',
            'bindHost'   => $get('realtime_ws_bind_host',    'REALTIME_WS_HOST',         '127.0.0.1'),
            'bindPort'   => (int) $get('realtime_ws_bind_port', 'REALTIME_WS_PORT',      '6001'),
            'scheme'     => $get('realtime_ws_public_scheme', 'REALTIME_WS_PUBLIC_SCHEME', 'wss'),
            'publicHost' => $get('realtime_ws_public_host',   'REALTIME_WS_PUBLIC_HOST',  ''),
            'publicPort' => (int) $get('realtime_ws_public_port', 'REALTIME_WS_PUBLIC_PORT', '443'),
            'appKey'     => $get('realtime_app_key',          'REALTIME_APP_KEY',         'radiochatbox'),
        ];
    }
}
