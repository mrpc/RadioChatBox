<?php

namespace RadioChatBox\Services;

use Pramnos\Database\Database as PramnosDatabase;

/**
 * Persistent, cross-conversation mood for the auto-reply bots.
 *
 * A bot has a GLOBAL mood (on fake_users) that colours every conversation, plus a
 * fast LOCAL mood per thread (on bot_threads) that reacts to the current chat.
 * Events during a chat nudge the local mood always, and the global mood only when
 * they are STRONG — so a real upset bleeds into the bot's other conversations
 * ("annoyed everywhere") while everyday ups and downs stay local. Both fade back
 * toward the bot's baseline over time (on-read decay + a periodic sweep).
 *
 * The expressed mood is the more intense of {global, local}; below a floor it is
 * treated as the baseline (neutral by default), so a calm bot gets no directive.
 */
class MoodService
{
    /**
     * The mood vocabulary and each mood's valence (negative … positive). The
     * valence is used only for classification/telemetry, not the blend (which is
     * intensity-based).
     */
    public const MOODS = [
        'angry'   => -3,
        'annoyed' => -2,
        'sad'     => -2,
        'bored'   => -1,
        'neutral' =>  0,
        'content' =>  1,
        'happy'   =>  2,
        'flirty'  =>  2,
        'excited' =>  3,
    ];

    /** The resting mood a bot returns to when nothing is going on. */
    public const DEFAULT_BASELINE = 'neutral';

    /** Below this intensity a mood is not "felt": it reads as the baseline. */
    public const MIN_FELT_INTENSITY = 15;

    /** An event at or above this strength also shifts the GLOBAL mood. */
    public const STRONG_EVENT = 60;

    /** Intensity lost per hour (on-read decay and the periodic sweep). */
    public const DECAY_PER_HOUR = 25;

    /** How the global mood is dampened relative to the event that caused it. */
    private const GLOBAL_DAMPEN = 0.8;

    private PramnosDatabase $db;
    private SettingsService $settings;

    public function __construct(?SettingsService $settings = null)
    {
        $this->db = PramnosDatabase::getInstance();
        $this->settings = $settings ?? new SettingsService();
    }

    /** Whether the mood feature is switched on. */
    public function isEnabled(): bool
    {
        return $this->settings->get('bot_moods_enabled', 'true') === 'true';
    }

    public static function isValidMood(string $mood): bool
    {
        return isset(self::MOODS[$mood]);
    }

    /** Human, gender-neutral Greek label for a mood (for the prompt and admin). */
    public static function label(string $mood): string
    {
        return match ($mood) {
            'angry'   => 'θυμωμένος/η',
            'annoyed' => 'εκνευρισμένος/η',
            'sad'     => 'στεναχωρημένος/η',
            'bored'   => 'βαριεστημένος/η',
            'content' => 'ήρεμος/η και ευχαριστημένος/η',
            'happy'   => 'χαρούμενος/η',
            'flirty'  => 'παιχνιδιάρικος/η και σε διάθεση για φλερτ',
            'excited' => 'ενθουσιασμένος/η',
            default   => 'ουδέτερος/η',
        };
    }

    // ── Events ────────────────────────────────────────────────────────────────

    /**
     * Apply a mood event from a conversation: always nudges the thread's LOCAL
     * mood, and a strong event (>= STRONG_EVENT) also nudges the bot's GLOBAL mood
     * so it bleeds into its other chats. A weaker event never overwrites a stronger
     * standing mood (so a mild "haha" does not wipe real anger).
     */
    public function applyEvent(int $fakeUserId, string $peer, string $mood, int $strength): void
    {
        if (!$this->isEnabled() || $fakeUserId <= 0 || trim($peer) === '' || !self::isValidMood($mood)) {
            return;
        }
        $strength = max(1, min(100, $strength));

        $this->nudgeLocal($fakeUserId, $peer, $mood, $strength);

        if ($strength >= self::STRONG_EVENT) {
            $this->nudgeGlobal($fakeUserId, $mood, (int) round($strength * self::GLOBAL_DAMPEN));
        }
    }

    /** Nudge one thread's local mood (overwrites only when at least as intense). */
    private function nudgeLocal(int $fakeUserId, string $peer, string $mood, int $strength): void
    {
        $row = $this->threadMoodRow($fakeUserId, $peer);
        $current = self::decayedIntensity(
            (int) ($row['mood_local_intensity'] ?? 0),
            $row['mood_local_updated_at'] ?? null
        );
        $sameMood = ($row['mood_local'] ?? null) === $mood;

        if (!$sameMood && $strength < $current) {
            return; // a weaker, different feeling does not displace the standing one
        }
        $intensity = $sameMood ? max($current, $strength) : $strength;

        $this->db->preparedQuery('
            UPDATE bot_threads
            SET mood_local = :mood, mood_local_intensity = :intensity, mood_local_updated_at = NOW(), updated_at = NOW()
            WHERE fake_user_id = :fake AND peer_username = :peer
        ', ['mood' => $mood, 'intensity' => $intensity, 'fake' => $fakeUserId, 'peer' => $peer]);
    }

    /** Nudge the bot's global mood (overwrites only when at least as intense). */
    private function nudgeGlobal(int $fakeUserId, string $mood, int $strength): void
    {
        $row = $this->fakeUserMoodRow($fakeUserId);
        if ($row === null) {
            return;
        }
        $current = self::decayedIntensity((int) ($row['mood_intensity'] ?? 0), $row['mood_updated_at'] ?? null);
        $sameMood = ($row['mood'] ?? null) === $mood;

        if (!$sameMood && $strength < $current) {
            return;
        }
        $intensity = $sameMood ? max($current, $strength) : $strength;

        $this->db->preparedQuery('
            UPDATE fake_users
            SET mood = :mood, mood_intensity = :intensity, mood_updated_at = NOW()
            WHERE id = :id
        ', ['mood' => $mood, 'intensity' => min(100, $intensity), 'id' => $fakeUserId]);
    }

    // ── Reading the effective mood ──────────────────────────────────────────────

    /**
     * The mood the bot should express to this peer right now: the more intense of
     * the decayed global and local moods. Below MIN_FELT_INTENSITY it reads as the
     * baseline.
     *
     * @return array{mood:string, intensity:int, scope:string, baseline:string}
     */
    public function effective(int $fakeUserId, string $peer): array
    {
        $global = $this->fakeUserMoodRow($fakeUserId);
        $baseline = ($global['mood_baseline'] ?? null) ?: self::DEFAULT_BASELINE;

        $gMood = (string) ($global['mood'] ?? '');
        $gInt = self::decayedIntensity((int) ($global['mood_intensity'] ?? 0), $global['mood_updated_at'] ?? null);

        $local = $this->threadMoodRow($fakeUserId, $peer);
        $lMood = (string) ($local['mood_local'] ?? '');
        $lInt = self::decayedIntensity((int) ($local['mood_local_intensity'] ?? 0), $local['mood_local_updated_at'] ?? null);

        // The stronger standing mood wins; ties go to the (more immediate) local.
        if ($lMood !== '' && $lInt >= $gInt && $lInt >= self::MIN_FELT_INTENSITY) {
            return ['mood' => $lMood, 'intensity' => $lInt, 'scope' => 'local', 'baseline' => $baseline];
        }
        if ($gMood !== '' && $gInt >= self::MIN_FELT_INTENSITY) {
            return ['mood' => $gMood, 'intensity' => $gInt, 'scope' => 'global', 'baseline' => $baseline];
        }

        return ['mood' => $baseline, 'intensity' => 0, 'scope' => 'baseline', 'baseline' => $baseline];
    }

    /**
     * The system-prompt directive for the current mood, or '' when the bot is at a
     * neutral baseline (nothing to add). It expresses the mood as TONE and forbids
     * revealing the cause — crucial for the global bleed into unrelated chats.
     */
    public function directiveFor(int $fakeUserId, string $peer): string
    {
        if (!$this->isEnabled()) {
            return '';
        }

        $eff = $this->effective($fakeUserId, $peer);
        if ($eff['scope'] === 'baseline' || $eff['mood'] === 'neutral') {
            return '';
        }

        $strength = $eff['intensity'] >= 70 ? 'πολύ έντονα' : ($eff['intensity'] >= 40 ? 'αισθητά' : 'ελαφρώς');

        return 'ΔΙΑΘΕΣΗ ΤΩΡΑ: Είσαι ' . self::label($eff['mood']) . " ({$strength}). "
            . 'Άσ\' την να ποτίσει το ΥΦΟΣ και τις αντιδράσεις σου σε όλη τη συζήτηση — όχι σαν ανακοίνωση, αλλά όπως θα φαινόταν σε έναν άνθρωπο με αυτή τη διάθεση. '
            . 'ΜΗΝ δηλώσεις ρητά τη διάθεσή σου, και ΠΟΤΕ ΜΗΝ εξηγήσεις γιατί είσαι έτσι (η αιτία μπορεί να μην έχει καμία σχέση με αυτόν εδώ τον συνομιλητή). '
            . 'Αν η κουβέντα αλλάξει τη διάθεσή σου, ακολούθησέ το φυσικά.';
    }

    // ── Admin control ───────────────────────────────────────────────────────────

    /** Set a bot's global mood explicitly (admin). Invalid mood → no-op false. */
    public function setGlobalMood(int $fakeUserId, string $mood, int $intensity = 70): bool
    {
        if (!self::isValidMood($mood)) {
            return false;
        }
        $result = $this->db->preparedQuery('
            UPDATE fake_users
            SET mood = :mood, mood_intensity = :intensity, mood_updated_at = NOW()
            WHERE id = :id
        ', ['mood' => $mood, 'intensity' => max(0, min(100, $intensity)), 'id' => $fakeUserId]);

        return $result !== false;
    }

    /**
     * Reset a bot back to its baseline everywhere: clears the global mood and every
     * thread's local mood. Returns true on success.
     */
    public function resetMood(int $fakeUserId): bool
    {
        $baseline = $this->baselineFor($fakeUserId);

        $this->db->preparedQuery('
            UPDATE fake_users SET mood = :b, mood_intensity = 0, mood_updated_at = NOW() WHERE id = :id
        ', ['b' => $baseline, 'id' => $fakeUserId]);

        $this->db->preparedQuery('
            UPDATE bot_threads
            SET mood_local = NULL, mood_local_intensity = 0, mood_local_updated_at = NOW()
            WHERE fake_user_id = :id
        ', ['id' => $fakeUserId]);

        return true;
    }

    /** Set a bot's resting mood (personality default). */
    public function setBaseline(int $fakeUserId, string $mood): bool
    {
        if (!self::isValidMood($mood)) {
            return false;
        }
        $this->db->preparedQuery(
            'UPDATE fake_users SET mood_baseline = :b WHERE id = :id',
            ['b' => $mood, 'id' => $fakeUserId]
        );
        return true;
    }

    // ── Decay sweep (scheduler) ─────────────────────────────────────────────────

    /**
     * Fade every standing mood toward baseline by the elapsed time, and normalise a
     * fully-decayed mood back to the baseline. Idempotent-friendly: it writes back
     * the decayed intensity and resets the clock, so it composes with on-read decay.
     *
     * @return int rows touched (best-effort; 0 on error)
     */
    public function decay(): int
    {
        try {
            $rate = self::DECAY_PER_HOUR;

            // Global moods on fake_users.
            $this->db->preparedQuery('
                UPDATE fake_users
                SET mood_intensity = GREATEST(0, mood_intensity - ROUND(:rate * EXTRACT(EPOCH FROM (NOW() - mood_updated_at)) / 3600.0)),
                    mood_updated_at = NOW()
                WHERE mood IS NOT NULL AND mood_intensity > 0 AND mood_updated_at IS NOT NULL
            ', ['rate' => $rate]);
            // A spent mood returns to the baseline.
            $this->db->preparedQuery("
                UPDATE fake_users
                SET mood = COALESCE(mood_baseline, '" . self::DEFAULT_BASELINE . "')
                WHERE mood_intensity <= 0 AND mood IS NOT NULL
                  AND mood <> COALESCE(mood_baseline, '" . self::DEFAULT_BASELINE . "')
            ");

            // Local moods on bot_threads.
            $this->db->preparedQuery('
                UPDATE bot_threads
                SET mood_local_intensity = GREATEST(0, mood_local_intensity - ROUND(:rate * EXTRACT(EPOCH FROM (NOW() - mood_local_updated_at)) / 3600.0)),
                    mood_local_updated_at = NOW()
                WHERE mood_local IS NOT NULL AND mood_local_intensity > 0 AND mood_local_updated_at IS NOT NULL
            ', ['rate' => $rate]);
            $this->db->preparedQuery('
                UPDATE bot_threads SET mood_local = NULL WHERE mood_local_intensity <= 0 AND mood_local IS NOT NULL
            ');

            return 1;
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('MoodService::decay failed: ' . $e->getMessage(), 'radiochatbox');
            return 0;
        }
        // @codeCoverageIgnoreEnd
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /** Intensity after time-based decay since it was last set. */
    public static function decayedIntensity(int $intensity, mixed $updatedAt): int
    {
        if ($intensity <= 0 || $updatedAt === null || $updatedAt === '') {
            return max(0, $intensity);
        }
        $ts = is_numeric($updatedAt) ? (int) $updatedAt : strtotime((string) $updatedAt);
        if ($ts === false || $ts <= 0) {
            return max(0, $intensity);
        }
        $hours = max(0.0, (time() - $ts) / 3600.0);
        return (int) max(0, $intensity - (int) round(self::DECAY_PER_HOUR * $hours));
    }

    private function baselineFor(int $fakeUserId): string
    {
        $row = $this->fakeUserMoodRow($fakeUserId);
        return ($row['mood_baseline'] ?? null) ?: self::DEFAULT_BASELINE;
    }

    /** @return array<string,mixed>|null */
    private function fakeUserMoodRow(int $fakeUserId): ?array
    {
        $result = $this->db->preparedQuery(
            'SELECT mood, mood_intensity, mood_baseline, mood_updated_at FROM fake_users WHERE id = ?',
            [$fakeUserId]
        );
        return ($result && $result->numRows > 0) ? $result->fields : null;
    }

    /** @return array<string,mixed> */
    private function threadMoodRow(int $fakeUserId, string $peer): array
    {
        $result = $this->db->preparedQuery(
            'SELECT mood_local, mood_local_intensity, mood_local_updated_at
             FROM bot_threads WHERE fake_user_id = ? AND peer_username = ?',
            [$fakeUserId, $peer]
        );
        return ($result && $result->numRows > 0) ? $result->fields : [];
    }
}
