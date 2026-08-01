<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use RadioChatBox\Services\RealtimeToken;

/**
 * The signed realtime token that authenticates a username across BOTH transports
 * (SSE ?token= and the WS /broadcasting/auth). Verifies a genuine token round
 * trips, and that tampering, a wrong secret, expiry or a malformed value are all
 * rejected — the guarantees the DM-privacy of both transports rests on.
 */
class RealtimeTokenTest extends TestCase
{
    private const SECRET = 'test-secret-0123456789abcdef';

    private function svc(string $secret = self::SECRET): RealtimeToken
    {
        return new RealtimeToken($secret);
    }

    public function testIssueThenVerifyRoundTrips(): void
    {
        $token = $this->svc()->issue('alice', 'sess-1', 3600);
        $claims = $this->svc()->verify($token);

        $this->assertNotNull($claims);
        $this->assertSame('alice', $claims['u']);
        $this->assertSame('sess-1', $claims['sid']);
        $this->assertGreaterThan(time(), $claims['exp']);
        $this->assertSame('alice', $this->svc()->usernameFor($token));
    }

    public function testTamperedPayloadIsRejected(): void
    {
        $token = $this->svc()->issue('alice', 'sess-1');
        [$payload, $sig] = explode('.', $token);
        // Re-encode a payload claiming to be bob, keep the original signature.
        $forged = rtrim(strtr(base64_encode((string) json_encode([
            'u' => 'bob', 'sid' => 'sess-1', 'exp' => time() + 3600,
        ])), '+/', '-_'), '=') . '.' . $sig;

        $this->assertNull($this->svc()->verify($forged), 'a re-signed-with-old-sig payload must fail');
        $this->assertNull($this->svc()->usernameFor($forged));
    }

    public function testWrongSecretIsRejected(): void
    {
        $token = $this->svc('secret-A')->issue('alice', 'sess-1');
        $this->assertNull($this->svc('secret-B')->verify($token));
    }

    public function testExpiredTokenIsRejected(): void
    {
        // ttl is clamped to >= 1, so issue with 1s then verify "in the future"
        // by signing an already-past exp directly through a tiny negative ttl path:
        $token = $this->svc()->issue('alice', 'sess-1', 1);
        // Force expiry by re-issuing with an exp in the past via reflection-free trick:
        // sleep is avoided; instead assert a hand-built expired token fails.
        $svc = $this->svc();
        $expiredPayload = rtrim(strtr(base64_encode((string) json_encode([
            'u' => 'alice', 'sid' => 'sess-1', 'exp' => time() - 5,
        ])), '+/', '-_'), '=');
        $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', $expiredPayload, self::SECRET, true)), '+/', '-_'), '=');
        $this->assertNull($svc->verify($expiredPayload . '.' . $sig), 'expired token must be rejected');
        $this->assertNotNull($svc->verify($token), 'a fresh token must still verify');
    }

    public function testMalformedTokensAreRejected(): void
    {
        $svc = $this->svc();
        $this->assertNull($svc->verify(''));
        $this->assertNull($svc->verify('no-dot'));
        $this->assertNull($svc->verify('a.b.c'));
        $this->assertNull($svc->verify('!!!.@@@'));
    }

    public function testPusherChannelAuthMatchesExpectedHmac(): void
    {
        $svc = $this->svc();
        $expected = 'appkey:' . hash_hmac('sha256', '123.456:private-pm-alice', self::SECRET);
        $this->assertSame($expected, $svc->pusherChannelAuth('appkey', '123.456', 'private-pm-alice'));
    }
}
