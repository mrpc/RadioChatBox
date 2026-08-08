<?php

namespace RadioChatBox\Services;

use Pramnos\Database\Database as PramnosDatabase;

/**
 * Admin-defined chat slash-commands (e.g. /rules, /tip). A user types a command
 * and the server replies to them directly with canned text — nothing is
 * broadcast or stored as a message. The built-in /help lists the active
 * commands and needs no row.
 */
class ChatCommandService
{
    private PramnosDatabase $db;

    /** Reserved names an admin cannot override (handled specially). */
    public const RESERVED = ['help'];

    public function __construct()
    {
        $this->db = PramnosDatabase::getInstance();
    }

    /**
     * Normalise raw user input into a bare command name: strip a leading slash,
     * lowercase, take the first whitespace-delimited token. '' if not a command.
     */
    public static function parse(string $message): string
    {
        $message = ltrim($message);
        if ($message === '' || $message[0] !== '/') {
            return '';
        }
        $token = preg_split('/\s+/', substr($message, 1), 2)[0] ?? '';
        return strtolower(trim($token));
    }

    /**
     * Resolve a user message to a command response, or null if it is not a
     * recognised command. Handles the built-in /help; otherwise looks up an
     * active custom command.
     */
    public function respondTo(string $message): ?string
    {
        $name = self::parse($message);
        if ($name === '') {
            return null;
        }
        if ($name === 'help') {
            return $this->helpText();
        }
        $row = $this->findActive($name);
        return $row !== null ? (string) $row['response'] : null;
    }

    /** The active command row for a name, or null. */
    public function findActive(string $name): ?array
    {
        $name = strtolower(trim($name));
        if ($name === '') {
            return null;
        }
        $rows = $this->db->queryBuilder()
            ->from('chat_commands')
            ->where('command', '=', $name)
            ->where('is_active', '=', true)
            ->limit(1)
            ->getAll();
        return $rows[0] ?? null;
    }

    /**
     * The active commands, name and description only.
     *
     * Shared by /help and by the autocomplete endpoint so the two can never
     * disagree about what exists — and it returns no response bodies, which the
     * public endpoint must not hand out.
     *
     * @return array<int, array<string, mixed>>
     */
    public function activeList(): array
    {
        return $this->db->queryBuilder()
            ->from('chat_commands')
            ->select(['command', 'description'])
            ->where('is_active', '=', true)
            ->orderBy('command', 'asc')
            ->getAll() ?: [];
    }

    /** Build the /help listing from the active commands. */
    public function helpText(): string
    {
        $rows = $this->activeList();

        $lines = ['Available commands:', '/help — show this list'];
        foreach ($rows as $row) {
            $desc = trim((string) ($row['description'] ?? ''));
            $lines[] = '/' . $row['command'] . ($desc !== '' ? ' — ' . $desc : '');
        }
        return implode("\n", $lines);
    }

    /**
     * All commands (admin view), newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->db->queryBuilder()
            ->from('chat_commands')
            ->orderBy('command', 'asc')
            ->getAll();
    }

    /**
     * Create a command. Returns the new id.
     *
     * @throws \InvalidArgumentException on a bad name, reserved name, or a
     *   duplicate command.
     */
    public function create(string $command, string $response, ?string $description = null, bool $isActive = true): int
    {
        $command = $this->normaliseName($command);
        $response = trim($response);
        if ($response === '') {
            throw new \InvalidArgumentException('response is required');
        }

        if ($this->nameExists($command)) {
            throw new \InvalidArgumentException('A command with that name already exists');
        }

        $result = $this->db->queryBuilder()->from('chat_commands')->returning('id')->insert([
            'command'     => $command,
            'response'    => mb_substr($response, 0, 2000),
            'description' => ($description !== null && trim($description) !== '') ? mb_substr(trim($description), 0, 255) : null,
            'is_active'   => $isActive,
        ]);

        return ($result && isset($result->fields['id'])) ? (int) $result->fields['id'] : 0;
    }

    /**
     * Update a command's response / description / active flag (the name is
     * immutable — delete and recreate to rename). Returns whether it applied.
     */
    public function update(int $id, string $response, ?string $description, bool $isActive): bool
    {
        if ($id <= 0) {
            return false;
        }
        $response = trim($response);
        if ($response === '') {
            throw new \InvalidArgumentException('response is required');
        }
        $qb = $this->db->queryBuilder()->from('chat_commands');
        $qb->where('id', '=', $id)->update([
            'response'    => mb_substr($response, 0, 2000),
            'description' => ($description !== null && trim($description) !== '') ? mb_substr(trim($description), 0, 255) : null,
            'is_active'   => $isActive,
            'updated_at'  => $qb->raw('NOW()'),
        ]);
        return true;
    }

    /** Delete a command. Returns whether the id was valid. */
    public function delete(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        $this->db->queryBuilder()->from('chat_commands')->where('id', '=', $id)->delete();
        return true;
    }

    /**
     * Validate + normalise a command name (no slash, lowercase, [a-z0-9_-], not
     * reserved).
     *
     * @throws \InvalidArgumentException
     */
    private function normaliseName(string $command): string
    {
        $command = strtolower(trim(ltrim($command, '/')));
        if ($command === '' || !preg_match('/^[a-z0-9_-]{1,50}$/', $command)) {
            throw new \InvalidArgumentException('Command name must be 1-50 chars of letters, numbers, _ or -');
        }
        if (in_array($command, self::RESERVED, true)) {
            throw new \InvalidArgumentException('"' . $command . '" is a reserved command');
        }
        return $command;
    }

    private function nameExists(string $command): bool
    {
        return $this->db->queryBuilder()
            ->from('chat_commands')
            ->where('command', '=', $command)
            ->count() > 0;
    }
}
