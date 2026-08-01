<?php

namespace RadioChatBox\Auth;

use Pramnos\Auth\Drivers\AuthDriverInterface;
use Pramnos\Auth\Drivers\AuthResult;
use Pramnos\Database\Database;

/**
 * Authenticates RadioChatBox accounts through the framework's Auth pipeline while
 * keeping RCB's password scheme.
 *
 * The framework's default {@see \Pramnos\Auth\Drivers\DatabaseAuthDriver} verifies
 * a PEPPERED hash (`password_verify($password . md5($salt.$userid), $hash)`),
 * whereas RCB stores a PLAIN bcrypt (`password_verify($password, $hash)`). Without
 * this driver the framework would reject every existing RCB user. Registering it
 * (bootstrap/pramnos.php) lets `Auth`/`LoginFlow` verify RCB accounts — the
 * prerequisite for adopting native lockout / 2FA / passkeys — with no forced
 * password reset.
 *
 * It does NOT change today's behaviour: RCB's own login still runs through
 * {@see \RadioChatBox\Services\UserService::authenticate()}. This driver is the
 * seam the framework auth stack uses.
 */
final class RcbAuthDriver implements AuthDriverInterface
{
    public function verify(
        string $username,
        string $password,
        bool   $encryptedPassword = false
    ): AuthResult {
        // Infrastructure errors (DB down) propagate per the interface contract;
        // only bad credentials return a failure result.
        $row = Database::getInstance()->queryBuilder()
            ->from('users')
            ->select(['userid', 'username', 'password', 'email', 'is_active'])
            ->whereRaw('username = %s OR email = %s', [$username, $username])
            ->first();

        $user = ($row && $row->numRows > 0) ? $row->fields : null;

        if ($user === null) {
            return AuthResult::failure("User doesn't exist", 404);
        }
        if (!$user['is_active']) {
            return AuthResult::failure('Inactive user', 0);
        }

        $hash = (string) $user['password'];
        $ok   = $encryptedPassword
            ? hash_equals($hash, $password)                 // token re-auth: compare hashes
            : password_verify($password, $hash);            // normal path: plain bcrypt

        if (!$ok) {
            return AuthResult::failure('Invalid credentials', 401);
        }

        return AuthResult::success(
            (string) $user['username'],
            (int) $user['userid'],
            (string) ($user['email'] ?? ''),
            $hash,
            1
        );
    }
}
