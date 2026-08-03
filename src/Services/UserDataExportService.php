<?php

namespace RadioChatBox\Services;

use Pramnos\Database\Database as PramnosDatabase;

/**
 * Assembles everything the app stores about a username into one structured array
 * (a GDPR-style "download my data" export). Read-only; joins across the public
 * messages, private messages, reports, song requests, warnings and profile.
 */
class UserDataExportService
{
    private PramnosDatabase $db;

    public function __construct()
    {
        $this->db = PramnosDatabase::getInstance();
    }

    /**
     * Everything on record for $username.
     *
     * @return array<string,mixed>
     */
    public function export(string $username): array
    {
        $username = trim($username);

        return [
            'username'         => $username,
            'generated_at'     => null, // stamped by the caller (no clock in services/tests)
            'profile'          => (new UserProfileService())->profile($username),
            'public_messages'  => $this->rows(
                'SELECT message_id, message, created_at, edited_at, is_deleted
                 FROM chat_messages WHERE LOWER(username) = LOWER(?) ORDER BY created_at',
                [$username]
            ),
            'private_messages' => $this->rows(
                'SELECT id, from_username, to_username, message, created_at
                 FROM private_messages
                 WHERE LOWER(from_username) = LOWER(?) OR LOWER(to_username) = LOWER(?)
                 ORDER BY created_at',
                [$username, $username]
            ),
            'reports_filed'    => $this->rows(
                'SELECT id, reported_username, reason, details, status, created_at
                 FROM message_reports WHERE LOWER(reporter_username) = LOWER(?) ORDER BY created_at',
                [$username]
            ),
            'reports_against'  => $this->rows(
                'SELECT id, reporter_username, reason, status, created_at
                 FROM message_reports WHERE LOWER(reported_username) = LOWER(?) ORDER BY created_at',
                [$username]
            ),
            'song_requests'    => $this->rows(
                'SELECT id, song_title, artist, dedication, status, created_at
                 FROM song_requests WHERE LOWER(requester_username) = LOWER(?) ORDER BY created_at',
                [$username]
            ),
            'warnings'         => $this->rows(
                'SELECT id, moderator, reason, created_at
                 FROM user_warnings WHERE LOWER(username) = LOWER(?) ORDER BY created_at',
                [$username]
            ),
        ];
    }

    /**
     * @param array<int, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    private function rows(string $sql, array $params): array
    {
        try {
            $result = $this->db->preparedQuery($sql, $params);
            return $result ? $result->fetchAll() : [];
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('UserDataExportService query failed: ' . $e->getMessage(), 'radiochatbox');
            return [];
        }
        // @codeCoverageIgnoreEnd
    }
}
