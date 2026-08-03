<?php

namespace RadioChatBox\Auth;

use Pramnos\Auth\Passkey\PasskeyService;
use Pramnos\Cache\FlatCache;

/**
 * RadioChatBox passkey service. The framework's PasskeyService stores the WebAuthn
 * ceremony challenge in the PHP session between the begin* and finish* calls, but
 * the RCB API is stateless (session-id/token based, no PHP session). This subclass
 * overrides the challenge store to use the cache (Redis) instead, keyed by the
 * same single-use challenge hash, so the two-step ceremony works across separate
 * stateless requests.
 */
final class RcbPasskeyService extends PasskeyService
{
    private const PREFIX = 'passkey:challenge:';

    protected function storeChallenge(string $type, string $challenge, array $data): void
    {
        try {
            FlatCache::default()->set(self::PREFIX . $this->challengeKey($type, $challenge), $data, self::CHALLENGE_TTL);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('RcbPasskeyService::storeChallenge failed: ' . $e->getMessage(), 'radiochatbox');
        }
        // @codeCoverageIgnoreEnd
    }

    protected function consumeChallenge(string $type, string $challenge): ?array
    {
        $key = self::PREFIX . $this->challengeKey($type, $challenge);
        try {
            $data = FlatCache::default()->get($key);
            FlatCache::default()->delete($key); // single-use, whether valid or not
            return is_array($data) ? $data : null;
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('RcbPasskeyService::consumeChallenge failed: ' . $e->getMessage(), 'radiochatbox');
            return null;
        }
        // @codeCoverageIgnoreEnd
    }
}
