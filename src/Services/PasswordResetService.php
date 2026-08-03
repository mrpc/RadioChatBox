<?php

namespace RadioChatBox\Services;

use Pramnos\Database\Database as PramnosDatabase;

/**
 * Self-service password reset. Issues single-use, short-lived tokens (stored only
 * as a sha256 hash) and validates/consumes them. Delivery of the reset link is the
 * caller's concern (see AuthController::forgotPassword). Prior unused tokens for a
 * user are invalidated when a new one is issued.
 */
class PasswordResetService
{
    /** How long a reset link is valid, in seconds. */
    private const TTL = 3600;

    private PramnosDatabase $db;

    public function __construct()
    {
        $this->db = PramnosDatabase::getInstance();
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Issue a fresh reset token for a user. Returns the PLAINTEXT token (put it in
     * the emailed link); only its hash is stored. Older unused tokens for the user
     * are invalidated.
     */
    public function issue(int $userId): string
    {
        // Invalidate previous unused tokens for this user.
        $this->db->preparedQuery(
            'UPDATE password_resets SET used_at = NOW() WHERE userid = :u AND used_at IS NULL',
            ['u' => $userId]
        );

        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + self::TTL);
        $this->db->queryBuilder()->from('password_resets')->insert([
            'userid'     => $userId,
            'token_hash' => $this->hash($token),
            'expires_at' => $expires,
        ]);
        return $token;
    }

    /**
     * The userid for a valid (unexpired, unused) token, or null.
     */
    public function resolve(string $token): ?int
    {
        if (trim($token) === '') {
            return null;
        }
        $row = $this->db->preparedQuery(
            'SELECT userid FROM password_resets
             WHERE token_hash = :h AND used_at IS NULL AND expires_at > NOW()
             LIMIT 1',
            ['h' => $this->hash($token)]
        );
        $userId = $row ? $row->fetchColumn() : false;
        return $userId === false ? null : (int) $userId;
    }

    /** Mark a token consumed so it can't be reused. */
    public function consume(string $token): void
    {
        $qb = $this->db->queryBuilder()->from('password_resets');
        $qb->where('token_hash', '=', $this->hash($token))->update(['used_at' => $qb->raw('NOW()')]);
    }
}
