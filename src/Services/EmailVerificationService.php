<?php

namespace RadioChatBox\Services;

use Pramnos\Database\Database as PramnosDatabase;

/**
 * Email-address verification. Mirrors the password-reset token flow: single-use,
 * short-lived tokens stored only as a sha256 hash; on success the user's
 * `email_verified_at` is stamped. Prior unused tokens are invalidated on re-issue.
 */
class EmailVerificationService
{
    /** Verification links are valid for 24 hours. */
    private const TTL = 86400;

    private PramnosDatabase $db;

    public function __construct()
    {
        $this->db = PramnosDatabase::getInstance();
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Whether an account's email is verified. */
    public function isVerified(int $userId): bool
    {
        $row = $this->db->preparedQuery(
            'SELECT email_verified_at FROM users WHERE userid = :u LIMIT 1',
            ['u' => $userId]
        );
        $val = $row ? $row->fetchColumn() : null;
        return $val !== false && $val !== null;
    }

    /** Issue a verification token (plaintext returned for the emailed link). */
    public function issue(int $userId): string
    {
        $this->db->preparedQuery(
            'UPDATE email_verifications SET used_at = NOW() WHERE userid = :u AND used_at IS NULL',
            ['u' => $userId]
        );
        $token = bin2hex(random_bytes(32));
        $this->db->queryBuilder()->from('email_verifications')->insert([
            'userid'     => $userId,
            'token_hash' => $this->hash($token),
            'expires_at' => date('Y-m-d H:i:s', time() + self::TTL),
        ]);
        return $token;
    }

    /** The userid for a valid (unexpired, unused) token, or null. */
    public function resolve(string $token): ?int
    {
        if (trim($token) === '') {
            return null;
        }
        $row = $this->db->preparedQuery(
            'SELECT userid FROM email_verifications
             WHERE token_hash = :h AND used_at IS NULL AND expires_at > NOW() LIMIT 1',
            ['h' => $this->hash($token)]
        );
        $userId = $row ? $row->fetchColumn() : false;
        return $userId === false ? null : (int) $userId;
    }

    /** Mark the token consumed and stamp the account verified. */
    public function verify(string $token): ?int
    {
        $userId = $this->resolve($token);
        if ($userId === null) {
            return null;
        }
        $qb = $this->db->queryBuilder()->from('email_verifications');
        $qb->where('token_hash', '=', $this->hash($token))->update(['used_at' => $qb->raw('NOW()')]);

        $uqb = $this->db->queryBuilder()->from('users');
        $uqb->where('userid', '=', $userId)->update(['email_verified_at' => $uqb->raw('NOW()')]);
        return $userId;
    }
}
