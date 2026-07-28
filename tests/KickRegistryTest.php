<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use RadioChatBox\Database;
use RadioChatBox\KickRegistry;

/**
 * Covers RadioChatBox\KickRegistry, the single owner of the kicked-session
 * (`banned_session:<id>`) state introduced when the scattered raw-Redis kick
 * calls were consolidated behind one boundary (Phase 8 State layer).
 *
 * Runs against the shared dev Redis (kicks are Redis state, not a cache), using
 * a random session id and cleaning up after itself. Also pins the historical
 * contract that keys are stored UNPREFIXED, so a kick recorded here is visible
 * to any reader on the same Redis regardless of the per-install prefix.
 */
class KickRegistryTest extends TestCase
{
    private string $sessionId;

    protected function setUp(): void
    {
        $this->sessionId = 'kicktest_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        try {
            Database::getRedis()->del('banned_session:' . $this->sessionId);
        } catch (\Throwable) {
            // best effort
        }
    }

    /**
     * kick() locks a session out, isKicked() reports it, forget() clears it —
     * the full lifecycle the moderation flow relies on.
     */
    public function testKickThenIsKickedThenForget(): void
    {
        $registry = new KickRegistry();

        $this->assertFalse($registry->isKicked($this->sessionId), 'not kicked initially');

        $registry->kick($this->sessionId, 'baduser', 'Kicked by admin');
        $this->assertTrue($registry->isKicked($this->sessionId), 'kick must lock the session out');

        $registry->forget($this->sessionId);
        $this->assertFalse($registry->isKicked($this->sessionId), 'forget must clear the kick');
    }

    /** An empty session id is never considered kicked. */
    public function testEmptySessionIsNeverKicked(): void
    {
        $this->assertFalse((new KickRegistry())->isKicked(''));
    }

    /**
     * list() surfaces a kicked session with its metadata and a positive TTL, so
     * the admin moderation view keeps working after the consolidation.
     */
    public function testListSurfacesTheKickedSessionWithMetadata(): void
    {
        $registry = new KickRegistry();
        $registry->kick($this->sessionId, 'baduser', 'spamming');

        $rows = $registry->list();
        $mine = null;
        foreach ($rows as $row) {
            if ($row['session_id'] === $this->sessionId) {
                $mine = $row;
                break;
            }
        }

        $this->assertNotNull($mine, 'the kicked session must appear in list()');
        $this->assertSame('baduser', $mine['username']);
        $this->assertSame('spamming', $mine['reason']);
        $this->assertGreaterThan(0, $mine['expires_in']);
        $this->assertLessThanOrEqual(KickRegistry::TTL, $mine['expires_in']);
    }

    /**
     * The key is stored UNPREFIXED (a kick is global across installs sharing the
     * Redis) — pins the BC contract the consolidated call sites depended on.
     */
    public function testKeyIsStoredUnprefixed(): void
    {
        (new KickRegistry())->kick($this->sessionId, 'baduser');

        $this->assertSame(1, Database::getRedis()->exists('banned_session:' . $this->sessionId));
    }
}
