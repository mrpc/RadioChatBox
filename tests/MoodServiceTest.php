<?php

namespace RadioChatBox\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Pramnos\Framework\Testing\TestDatabase;
use RadioChatBox\Services\MoodService;
use RadioChatBox\Services\SettingsService;

/**
 * The bot mood engine: a global mood on the fake user that bleeds across chats
 * plus a per-thread local mood, blended by intensity, faded by time-decay, and
 * rendered into a system-prompt directive that never reveals its cause.
 */
class MoodServiceTest extends TestCase
{
    private PDO $pdo;
    private int $fakeUserId;
    private string $peer;
    private MoodService $mood;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::connection();
        $suffix = substr(bin2hex(random_bytes(5)), 0, 8);
        $this->peer = 'moodpeer_' . $suffix;

        $stmt = $this->pdo->prepare(
            'INSERT INTO fake_users (nickname, age, sex, location, is_active, bot_enabled)
             VALUES (?, 27, ?, ?, TRUE, TRUE) RETURNING id'
        );
        $stmt->execute(['moodbot_' . $suffix, 'female', 'GR']);
        $this->fakeUserId = (int) $stmt->fetchColumn();

        // A thread the local mood can attach to.
        $this->pdo->prepare(
            'INSERT INTO bot_threads (fake_user_id, peer_username, messages_sent) VALUES (?, ?, 0)'
        )->execute([$this->fakeUserId, $this->peer]);

        (new SettingsService())->set('bot_moods_enabled', 'true');
        $this->mood = new MoodService();
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare('DELETE FROM bot_threads WHERE fake_user_id = ?')->execute([$this->fakeUserId]);
        $this->pdo->prepare('DELETE FROM fake_users WHERE id = ?')->execute([$this->fakeUserId]);
        (new SettingsService())->set('bot_moods_enabled', null);
    }

    /** @return array<string,mixed> */
    private function globalRow(): array
    {
        return $this->pdo->query(
            "SELECT mood, mood_intensity FROM fake_users WHERE id = {$this->fakeUserId}"
        )->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * A weak event only touches the LOCAL mood; the global mood is untouched, so
     * it does not bleed into the bot's other conversations.
     */
    public function testWeakEventStaysLocal(): void
    {
        $this->mood->applyEvent($this->fakeUserId, $this->peer, 'happy', 30);

        $this->assertNull($this->globalRow()['mood'], 'a weak event must not move the global mood');
        $eff = $this->mood->effective($this->fakeUserId, $this->peer);
        $this->assertSame('happy', $eff['mood']);
        $this->assertSame('local', $eff['scope']);
    }

    /**
     * A strong event moves the GLOBAL mood too, so a second (fresh) conversation
     * with the same bot inherits it — "annoyed everywhere".
     */
    public function testStrongEventBleedsToGlobalAndOtherChats(): void
    {
        $this->mood->applyEvent($this->fakeUserId, $this->peer, 'angry', 90);

        $this->assertSame('angry', $this->globalRow()['mood']);

        // A different peer with no local mood still sees the global anger.
        $other = $this->peer . '_other';
        $eff = $this->mood->effective($this->fakeUserId, $other);
        $this->assertSame('angry', $eff['mood']);
        $this->assertSame('global', $eff['scope']);
    }

    /**
     * A calm bot (mood below the felt floor) reads as its baseline and produces no
     * directive; a felt mood produces a directive that never asks to reveal WHY.
     */
    public function testDirectiveOnlyWhenFeltAndNeverExplainsCause(): void
    {
        $this->assertSame('', $this->mood->directiveFor($this->fakeUserId, $this->peer), 'neutral bot → no directive');

        $this->mood->applyEvent($this->fakeUserId, $this->peer, 'annoyed', 80);
        $directive = $this->mood->directiveFor($this->fakeUserId, $this->peer);
        $this->assertNotSame('', $directive);
        $this->assertStringContainsString(MoodService::label('annoyed'), $directive);
        // The secrecy instruction (crucial for the global bleed) is present.
        $this->assertStringContainsString('ΜΗΝ εξηγήσεις γιατί', $directive);
    }

    /** A stronger standing mood is not displaced by a weaker, different feeling. */
    public function testWeakerDifferentEventDoesNotDisplaceAStrongMood(): void
    {
        $this->mood->applyEvent($this->fakeUserId, $this->peer, 'angry', 85);
        $this->mood->applyEvent($this->fakeUserId, $this->peer, 'happy', 20);

        $this->assertSame('angry', $this->mood->effective($this->fakeUserId, $this->peer)['mood']);
    }

    /** Time decay lowers intensity; the sweep normalises a spent mood to baseline. */
    public function testDecayReducesIntensityAndRevertsToBaseline(): void
    {
        // Static on-read decay: full intensity, set two hours ago, at 25/h → ~50 lost.
        $twoHoursAgo = date('Y-m-d H:i:s', time() - 7200);
        $this->assertSame(30, MoodService::decayedIntensity(80, $twoHoursAgo));

        // Sweep: an intensity set well in the past decays to 0 and reverts.
        $this->pdo->prepare(
            "UPDATE fake_users SET mood = 'angry', mood_intensity = 20, mood_baseline = 'neutral',
             mood_updated_at = NOW() - INTERVAL '10 hours' WHERE id = ?"
        )->execute([$this->fakeUserId]);

        $this->mood->decay();

        $row = $this->globalRow();
        $this->assertSame(0, (int) $row['mood_intensity']);
        $this->assertSame('neutral', $row['mood'], 'a spent mood returns to baseline');
    }

    /** Admin can set a global mood and reset the bot back to baseline everywhere. */
    public function testAdminSetAndResetMood(): void
    {
        $this->assertTrue($this->mood->setBaseline($this->fakeUserId, 'content'));
        $this->assertTrue($this->mood->setGlobalMood($this->fakeUserId, 'excited', 90));
        $this->mood->applyEvent($this->fakeUserId, $this->peer, 'flirty', 50); // a local mood too

        $this->assertSame('excited', $this->globalRow()['mood']);

        $this->assertTrue($this->mood->resetMood($this->fakeUserId));
        $row = $this->globalRow();
        $this->assertSame('content', $row['mood'], 'reset returns to the baseline');
        $this->assertSame(0, (int) $row['mood_intensity']);
        // The thread's local mood is cleared too.
        $local = $this->pdo->query(
            "SELECT mood_local FROM bot_threads WHERE fake_user_id = {$this->fakeUserId} AND peer_username = '{$this->peer}'"
        )->fetchColumn();
        $this->assertNull($local);
    }

    /** An invalid mood is rejected by the admin setters. */
    public function testInvalidMoodRejected(): void
    {
        $this->assertFalse($this->mood->setGlobalMood($this->fakeUserId, 'euphoric', 50));
        $this->assertFalse($this->mood->setBaseline($this->fakeUserId, 'zany'));
    }

    /** The LLM mood tag is parsed (valid moods only) and stripped from a reply. */
    public function testParseAndStripMoodTag(): void
    {
        $this->assertSame(['mood' => 'angry', 'strength' => 70], MoodService::parseTag('ελα ρε [[mood:angry|70]]'));
        $this->assertSame(['mood' => 'flirty', 'strength' => 100], MoodService::parseTag('[[ mood : flirty | 250 ]]'), 'strength clamps to 100');
        $this->assertNull(MoodService::parseTag('no tag here'));
        $this->assertNull(MoodService::parseTag('[[mood:banana|50]]'), 'unknown moods are rejected');

        $this->assertSame('γεια σου', MoodService::stripTags("γεια σου\n[[mood:happy|40]]"));
    }

    /** LLM-driven mode requires BOTH the feature and the v2 flag on. */
    public function testIsLlmDrivenNeedsBothFlags(): void
    {
        $s = new SettingsService();
        $s->set('bot_moods_enabled', 'true');
        $s->set('bot_mood_llm_enabled', 'false');
        $this->assertFalse((new MoodService($s))->isLlmDriven());

        $s->set('bot_mood_llm_enabled', 'true');
        $this->assertTrue((new MoodService($s))->isLlmDriven());

        $s->set('bot_moods_enabled', 'false');
        $this->assertFalse((new MoodService($s))->isLlmDriven(), 'the master switch still gates it');

        $s->set('bot_mood_llm_enabled', null);
    }

    /** With the feature off, events do nothing and no directive is produced. */
    public function testDisabledIsInert(): void
    {
        (new SettingsService())->set('bot_moods_enabled', 'false');
        $mood = new MoodService();

        $mood->applyEvent($this->fakeUserId, $this->peer, 'angry', 95);
        $this->assertNull($this->globalRow()['mood']);
        $this->assertSame('', $mood->directiveFor($this->fakeUserId, $this->peer));
    }
}
