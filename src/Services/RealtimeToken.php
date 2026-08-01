<?php

namespace RadioChatBox\Services;

/**
 * Short-lived signed token that proves a realtime client owns a chat username,
 * for BOTH transports:
 *
 *  - SSE  — passed as `/api/stream?token=…` (EventSource cannot send headers), so
 *           the server derives the viewer's username from the *verified* token
 *           instead of a spoofable `?username=` query param.
 *  - WS   — presented to `/api/broadcasting/auth`, which signs the caller's own
 *           `private-pm-<username>` channel only when the token matches.
 *
 * The token is `base64url(payload).base64url(HMAC-SHA256(payload, secret))` with
 * payload `{u:username, sid:sessionId, exp:unixts}`. It is deliberately NOT a
 * bearer login credential — it only authorizes realtime delivery for a username
 * a client already claimed via the heartbeat/presence flow, and it expires fast
 * (default 1h) because it travels in the SSE URL. Refresh it on every heartbeat.
 *
 * The signing secret is a per-install random value persisted once in settings
 * (`realtime_secret`), so every process of the install (web, SSE, the WS worker)
 * signs and verifies with the same key with no shared env var required.
 */
final class RealtimeToken
{
    /** Default token lifetime (seconds). Kept short — it rides in the SSE URL. */
    public const DEFAULT_TTL = 3600;

    private string $secret;

    /**
     * @param string|null $secret Injected secret (tests); otherwise resolved from
     *   settings, generating and persisting one on first use.
     */
    public function __construct(?string $secret = null, ?SettingsService $settings = null)
    {
        $this->secret = $secret ?? self::resolveSecret($settings ?? new SettingsService());
    }

    /**
     * Resolve the per-install signing secret, generating + persisting one the
     * first time. A 256-bit random hex value; stable across every process.
     */
    private static function resolveSecret(SettingsService $settings): string
    {
        $secret = (string) $settings->get('realtime_secret', '');
        if ($secret === '') {
            $secret = bin2hex(random_bytes(32));
            $settings->set('realtime_secret', $secret);
        }
        return $secret;
    }

    /** Issue a token binding $username + $sessionId, valid for $ttl seconds. */
    public function issue(string $username, string $sessionId, int $ttl = self::DEFAULT_TTL): string
    {
        $payload = self::b64encode((string) json_encode([
            'u'   => $username,
            'sid' => $sessionId,
            'exp' => time() + max(1, $ttl),
        ]));
        return $payload . '.' . self::b64encode($this->sign($payload));
    }

    /**
     * Verify a token and return its claims, or null if malformed, tampered or
     * expired. Constant-time signature comparison.
     *
     * @return array{u:string,sid:string,exp:int}|null
     */
    public function verify(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }
        [$payload, $sig] = $parts;

        $expected = $this->sign($payload);
        $given    = self::b64decode($sig);
        if ($given === null || !hash_equals($expected, $given)) {
            return null;
        }

        $json = self::b64decode($payload);
        $data = $json !== null ? json_decode($json, true) : null;
        if (!is_array($data) || !isset($data['u'], $data['sid'], $data['exp'])) {
            return null;
        }
        if ((int) $data['exp'] < time()) {
            return null;
        }

        return ['u' => (string) $data['u'], 'sid' => (string) $data['sid'], 'exp' => (int) $data['exp']];
    }

    /** The verified username for a token, or null. Convenience for the SSE path. */
    public function usernameFor(string $token): ?string
    {
        return $this->verify($token)['u'] ?? null;
    }

    /**
     * Pusher-compatible channel-auth signature for the WS `/broadcasting/auth`
     * endpoint: `"<appKey>:" . HMAC-SHA256("<socketId>:<channel>", secret)`. Uses
     * the SAME per-install secret as the token, so the WS worker's PusherAuthorizer
     * (configured with this secret) validates it.
     */
    public function pusherChannelAuth(string $appKey, string $socketId, string $channel): string
    {
        return $appKey . ':' . hash_hmac('sha256', $socketId . ':' . $channel, $this->secret);
    }

    /** The signing secret (for wiring the WS worker's PusherAuthorizer). */
    public function secret(): string
    {
        return $this->secret;
    }

    private function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->secret, true);
    }

    private static function b64encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function b64decode(string $enc): ?string
    {
        $decoded = base64_decode(strtr($enc, '-_', '+/'), true);
        return $decoded === false ? null : $decoded;
    }
}
