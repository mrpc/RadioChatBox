<?php

namespace RadioChatBox\Services;

use Pramnos\Database\Database as PramnosDatabase;

/**
 * Records what a user has agreed to, and when.
 *
 * Writes to the framework's own `authserver.user_consents` rather than a boolean
 * column on `users`, because a marketing opt-in is not a preference — it is a
 * grant that has to be defensible. That table already carries the shape for it:
 * the consent type, whether it is currently granted, the moment it was given,
 * the address it came from, and the legal basis. A column would answer "does
 * this person get the newsletter" and nothing a regulator would ask.
 *
 * Revocation is a row, not a delete: the history of a grant is the point.
 */
class ConsentService
{
    /** Newsletter and other promotional mail. */
    public const MARKETING_EMAIL = 'marketing_email';

    private const TABLE = 'authserver.user_consents';

    private PramnosDatabase $db;

    public function __construct(?PramnosDatabase $db = null)
    {
        $this->db = $db ?? PramnosDatabase::getInstance();
    }

    /** Record a grant. Best-effort: consent must never fail a registration. */
    public function grant(int $userId, string $type, ?string $ip = null): bool
    {
        return $this->write($userId, $type, true, $ip);
    }

    /** Record a withdrawal, leaving the original grant in place as history. */
    public function revoke(int $userId, string $type, ?string $ip = null): bool
    {
        return $this->write($userId, $type, false, $ip);
    }

    /**
     * Whether the most recent decision for this consent type was a grant.
     *
     * Reads the latest row rather than any row: a user who opted in and later
     * out has both, and only the last one is their answer.
     */
    public function has(int $userId, string $type): bool
    {
        try {
            $result = $this->db->preparedQuery(
                'SELECT granted FROM ' . self::TABLE . '
                  WHERE userid = ? AND consent_type = ?
                  ORDER BY granted_at DESC
                  LIMIT 1',
                [$userId, $type]
            );

            return $result && (int) $result->fetchColumn() === 1;
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log(
                'ConsentService::has failed for user ' . $userId . ': ' . $e->getMessage(),
                'radiochatbox'
            );
            return false;
        }
    }

    private function write(int $userId, string $type, bool $granted, ?string $ip): bool
    {
        $ip ??= (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');

        try {
            $this->db->preparedQuery(
                'INSERT INTO ' . self::TABLE . '
                    (userid, consent_type, granted, granted_at, revoked_at, legal_basis, ip_address)
                 VALUES (?, ?, ?, NOW(), ?, ?, ?)',
                [
                    $userId,
                    $type,
                    $granted ? 1 : 0,
                    $granted ? null : date('c'),
                    'consent',
                    substr($ip, 0, 45),
                ]
            );

            return true;
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log(
                'ConsentService could not record ' . $type . ' for user ' . $userId . ': ' . $e->getMessage(),
                'radiochatbox'
            );
            return false;
        }
    }
}
