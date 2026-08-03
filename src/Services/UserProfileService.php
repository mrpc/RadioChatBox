<?php

namespace RadioChatBox\Services;

use Pramnos\Database\Database as PramnosDatabase;

/**
 * Public-facing profile card for a chat user: their display name, whether they
 * are online now, how many public messages they've posted, when they were first
 * and last seen, an optional profile (age/sex/location) and a role badge. Built
 * from data the app already stores — nickname-only guests still get a card
 * (message stats + online status), registered users get the extra fields.
 */
class UserProfileService
{
    /** A user counts as "online" if their heartbeat is within this many seconds. */
    private const ONLINE_WINDOW_SECONDS = 120;

    private PramnosDatabase $db;

    public function __construct()
    {
        $this->db = PramnosDatabase::getInstance();
    }

    /**
     * Build the profile card for a username. Returns null for an empty username.
     *
     * @return array<string,mixed>|null
     */
    public function profile(string $username): ?array
    {
        $username = trim($username);
        if ($username === '') {
            return null;
        }

        // Public message stats (count + first/last seen).
        $stats = $this->db->preparedQuery(
            'SELECT COUNT(*) AS cnt, MIN(created_at) AS first_seen, MAX(created_at) AS last_message
             FROM chat_messages
             WHERE LOWER(username) = LOWER(?) AND is_deleted = FALSE',
            [$username]
        );
        $statsRow = ($stats && $stats->numRows > 0) ? $stats->fields : ['cnt' => 0, 'first_seen' => null, 'last_message' => null];

        // Online now? (any fresh presence session)
        $online = $this->db->preparedQuery(
            "SELECT MAX(last_heartbeat) AS last_seen,
                    BOOL_OR(last_heartbeat > NOW() - INTERVAL '" . self::ONLINE_WINDOW_SECONDS . " seconds') AS is_online
             FROM presence_sessions WHERE LOWER(username) = LOWER(?)",
            [$username]
        );
        $onlineRow = ($online && $online->numRows > 0) ? $online->fields : ['last_seen' => null, 'is_online' => false];

        // Registered-user fields (display name + role) and optional profile.
        $user = $this->db->preparedQuery(
            'SELECT display_name, usertype FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1',
            [$username]
        );
        $userRow = ($user && $user->numRows > 0) ? $user->fields : null;

        $profile = $this->db->preparedQuery(
            'SELECT age, sex, location FROM user_profiles WHERE LOWER(username) = LOWER(?) LIMIT 1',
            [$username]
        );
        $profileRow = ($profile && $profile->numRows > 0) ? $profile->fields : null;

        $usertype = $userRow !== null ? (int) $userRow['usertype'] : 0;
        $roleLabel = Authz::labelForUsertype($usertype);
        $messageCount = (int) ($statsRow['cnt'] ?? 0);

        return [
            'username'      => $username,
            'display_name'  => $userRow['display_name'] ?? null,
            'is_online'     => !empty($onlineRow['is_online']),
            'message_count' => $messageCount,
            'rank'          => RankService::forCount($messageCount),
            'first_seen'    => $statsRow['first_seen'] ?? null,
            'last_seen'     => $onlineRow['last_seen'] ?? ($statsRow['last_message'] ?? null),
            'role'          => $roleLabel,
            'badge'         => $this->badgeFor($usertype),
            'age'           => $profileRow['age'] ?? null,
            'sex'           => $profileRow['sex'] ?? null,
            'location'      => $profileRow['location'] ?? null,
        ];
    }

    /** A short badge label for a role, or null for a plain user. */
    private function badgeFor(int $usertype): ?string
    {
        if ($usertype >= Authz::ROOT) {
            return 'Root';
        }
        if ($usertype >= Authz::ADMINISTRATOR) {
            return 'Admin';
        }
        if ($usertype >= Authz::MODERATOR) {
            return 'Moderator';
        }
        return null;
    }
}
