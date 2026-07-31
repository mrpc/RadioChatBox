<?php

namespace RadioChatBox\Services;

/**
 * Authorization facade for the usertype ladder.
 *
 * Schema convergence replaced RCB's `users.role` enum with the framework's
 * `users.usertype` integer. This is the single source of truth for the mapping
 * between the numeric ladder and the human role labels the app/SPA use, plus the
 * permission model that previously lived as UserService::PERMISSIONS /
 * ROLE_LEVELS / canManageUser.
 *
 * usertype ladder (also clears the framework's built-in admin gates —
 * DevPanel/tokens/queue expect usertype >= 80/90):
 *   root=99, administrator=90, moderator=50, simple_user=0
 *
 * Permission decisions are reproduced EXACTLY from the pre-convergence
 * role→permission map so authorization behaviour is unchanged.
 */
final class Authz
{
    public const ROOT          = 99;
    public const ADMINISTRATOR = 90;
    public const MODERATOR     = 50;
    public const SIMPLE_USER   = 0;

    /** Label → usertype. */
    private const USERTYPE_BY_LABEL = [
        'root'          => self::ROOT,
        'administrator' => self::ADMINISTRATOR,
        'moderator'     => self::MODERATOR,
        'simple_user'   => self::SIMPLE_USER,
    ];

    /**
     * Permission set per role label — a verbatim copy of the pre-convergence
     * UserService::PERMISSIONS map (non-hierarchical by design: e.g. moderator
     * has view_bans while administrator has manage_bans instead), so can()
     * reproduces the old decisions exactly rather than via a threshold.
     */
    private const PERMISSIONS = [
        'root' => [
            'view_private_messages', 'manage_settings', 'manage_users',
            'manage_bans', 'manage_blacklist', 'view_messages',
            'create_root_users', 'delete_root_users',
        ],
        'administrator' => [
            'view_private_messages', 'manage_settings', 'manage_users',
            'manage_bans', 'manage_blacklist', 'view_messages',
        ],
        'moderator' => [
            'view_messages', 'view_bans', 'view_blacklist',
        ],
        'simple_user' => [],
    ];

    /**
     * Map a usertype to its role label. Tolerant of intermediate values so any
     * custom usertype still yields a sensible label.
     */
    public static function labelForUsertype(int $usertype): string
    {
        if ($usertype >= self::ROOT) {
            return 'root';
        }
        if ($usertype >= self::ADMINISTRATOR) {
            return 'administrator';
        }
        if ($usertype >= self::MODERATOR) {
            return 'moderator';
        }
        return 'simple_user';
    }

    /** Map a role label to its usertype (unknown label → simple_user). */
    public static function usertypeForLabel(string $label): int
    {
        return self::USERTYPE_BY_LABEL[$label] ?? self::SIMPLE_USER;
    }

    /** Whether $label is a known role label. */
    public static function isValidLabel(string $label): bool
    {
        return isset(self::USERTYPE_BY_LABEL[$label]);
    }

    /** The assignable role labels (order: least → most privileged). */
    public static function availableRoleLabels(): array
    {
        return ['simple_user', 'moderator', 'administrator', 'root'];
    }

    /** Whether a user of the given usertype holds a permission. */
    public static function can(int $usertype, string $permission): bool
    {
        $perms = self::PERMISSIONS[self::labelForUsertype($usertype)] ?? [];
        return in_array($permission, $perms, true);
    }

    /**
     * Whether an actor may manage a target user (reproduces canManageUser):
     * root manages everyone; administrator manages everyone except root; others
     * manage no one.
     */
    public static function canManage(int $actorUsertype, int $targetUsertype): bool
    {
        if ($actorUsertype >= self::ROOT) {
            return true;
        }
        if ($actorUsertype >= self::ADMINISTRATOR && $targetUsertype < self::ROOT) {
            return true;
        }
        return false;
    }
}
