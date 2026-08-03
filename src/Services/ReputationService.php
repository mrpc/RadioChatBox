<?php

namespace RadioChatBox\Services;

use Pramnos\Database\Database as PramnosDatabase;

/**
 * A simple reputation score for a user, derived from behaviour the app already
 * records: public messages and reactions received count for them; active
 * warnings and reports filed against them count against them. The score is a pure
 * function of that data (nothing stored) and maps to a tier shown on the profile.
 */
class ReputationService
{
    // Weights.
    private const W_MESSAGE  = 1;
    private const W_REACTION = 2;
    private const W_WARNING  = -10;
    private const W_REPORT   = -5;

    private PramnosDatabase $db;

    public function __construct()
    {
        $this->db = PramnosDatabase::getInstance();
    }

    /**
     * Compute the reputation score + tier for a username.
     *
     * @return array{score:int, tier:string, color:string,
     *   messages:int, reactions:int, warnings:int, reports:int}
     */
    public function forUser(string $username): array
    {
        $username = trim($username);
        if ($username === '') {
            return $this->result(0, 0, 0, 0);
        }

        $messages  = $this->scalar('SELECT COUNT(*) FROM chat_messages WHERE LOWER(username) = LOWER(?) AND is_deleted = FALSE', [$username]);
        $reactions = $this->scalar(
            'SELECT COUNT(*) FROM message_reactions mr
             JOIN chat_messages m ON mr.message_id = m.message_id
             WHERE LOWER(m.username) = LOWER(?)',
            [$username]
        );
        $warnings  = $this->scalar('SELECT COUNT(*) FROM user_warnings WHERE LOWER(username) = LOWER(?)', [$username]);
        $reports   = $this->scalar('SELECT COUNT(*) FROM message_reports WHERE LOWER(reported_username) = LOWER(?)', [$username]);

        return $this->result($messages, $reactions, $warnings, $reports);
    }

    /**
     * @return array{score:int, tier:string, color:string,
     *   messages:int, reactions:int, warnings:int, reports:int}
     */
    private function result(int $messages, int $reactions, int $warnings, int $reports): array
    {
        $score = $messages * self::W_MESSAGE
            + $reactions * self::W_REACTION
            + $warnings * self::W_WARNING
            + $reports * self::W_REPORT;
        $score = max(0, $score);

        [$tier, $color] = $this->tier($score);

        return [
            'score'     => $score,
            'tier'      => $tier,
            'color'     => $color,
            'messages'  => $messages,
            'reactions' => $reactions,
            'warnings'  => $warnings,
            'reports'   => $reports,
        ];
    }

    /** @return array{0:string,1:string} tier label + colour */
    private function tier(int $score): array
    {
        if ($score >= 200) {
            return ['Excellent', '#10b981'];
        }
        if ($score >= 50) {
            return ['Good', '#3b82f6'];
        }
        if ($score >= 10) {
            return ['Neutral', '#6b7280'];
        }
        return ['New', '#9ca3af'];
    }

    /**
     * @param array<int, mixed> $params
     */
    private function scalar(string $sql, array $params): int
    {
        try {
            $result = $this->db->preparedQuery($sql, $params);
            return $result ? (int) $result->fetchColumn() : 0;
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('ReputationService query failed: ' . $e->getMessage(), 'radiochatbox');
            return 0;
        }
        // @codeCoverageIgnoreEnd
    }
}
