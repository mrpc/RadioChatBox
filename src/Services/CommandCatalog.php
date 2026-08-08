<?php

namespace RadioChatBox\Services;

/**
 * The one list of chat commands that exist, who may run them, and what has to
 * be switched on for them to work.
 *
 * There were three handlers and no shared idea of what a command is: /poll was
 * parsed in SendController, /mute and friends in ModeratorCommandService, and
 * /help — which is supposed to answer "what can I type?" — knew only about the
 * admin-defined ones in chat_commands. So /help listed neither /poll nor the
 * moderator commands, and did not run at all unless custom commands happened to
 * be enabled, which is how it came to do nothing on a station that has none.
 *
 * Everything that wants to describe the command surface now reads this: /help,
 * and the autocomplete endpoint. Adding a command in one place puts it in both.
 *
 * Several names are the IRC ones on purpose. The roadmap has an IRC
 * compatibility layer in a later phase, and a listener who has used IRC already
 * knows /me and /whois; matching that vocabulary now costs nothing and means the
 * later bridge maps verbs it already speaks.
 */
class CommandCatalog
{
    /**
     * Built-in commands, in the order /help lists them.
     *
     * `minUsertype` is checked against the caller's role, `feature` against a
     * setting that must read as on. A command with neither is available to
     * everyone, always.
     *
     * @return array<int, array{command:string, description:string, minUsertype:int, feature:?string, irc:bool}>
     */
    public static function builtins(): array
    {
        return [
            [
                'command'     => 'help',
                'description' => 'Show the commands you can use',
                'minUsertype' => Authz::SIMPLE_USER,
                'feature'     => null,
                'irc'         => true,
            ],
            [
                'command'     => 'me',
                'description' => 'Say what you are doing — /me waves',
                'minUsertype' => Authz::SIMPLE_USER,
                'feature'     => null,
                'irc'         => true,
            ],
            [
                'command'     => 'poll',
                'description' => 'Start a poll — send it alone to open the builder',
                'minUsertype' => Authz::SIMPLE_USER,
                'feature'     => 'polls_enabled',
                'irc'         => false,
            ],
            [
                'command'     => 'mute',
                'description' => 'Silence someone — /mute <user> [minutes]',
                'minUsertype' => Authz::MODERATOR,
                'feature'     => null,
                'irc'         => true,
            ],
            [
                'command'     => 'unmute',
                'description' => 'Lift a silence — /unmute <user>',
                'minUsertype' => Authz::MODERATOR,
                'feature'     => null,
                'irc'         => true,
            ],
            [
                'command'     => 'warn',
                'description' => 'Warn someone — /warn <user> [reason]',
                'minUsertype' => Authz::MODERATOR,
                'feature'     => null,
                'irc'         => false,
            ],
            [
                'command'     => 'ban',
                'description' => 'Ban a nickname — /ban <user> [reason]',
                'minUsertype' => Authz::MODERATOR,
                'feature'     => null,
                'irc'         => true,
            ],
        ];
    }

    /**
     * The commands a particular caller can actually use right now: built-ins
     * they have the role for and whose feature is on, then the station's own.
     *
     * Poll creation carries a second, finer gate (poll_min_usertype) that this
     * respects, so the list never offers something the send path will refuse.
     *
     * @param int $usertype The caller's role, as an Authz constant.
     * @return array<int, array{command:string, description:string}>
     */
    public function availableTo(int $usertype): array
    {
        $settings = new SettingsService();
        $out = [];

        foreach (self::builtins() as $command) {
            if ($usertype < $command['minUsertype']) {
                continue;
            }
            if ($command['feature'] !== null
                && !in_array(
                    strtolower(trim((string) $settings->get($command['feature'], 'false'))),
                    ['1', 'true', 'on', 'yes'],
                    true
                )
            ) {
                continue;
            }
            if ($command['command'] === 'poll'
                && $usertype < Authz::usertypeForLabel((string) ($settings->get('poll_min_usertype') ?: 'moderator'))
            ) {
                continue;
            }

            $out[] = ['command' => $command['command'], 'description' => $command['description']];
        }

        // The station's own commands, when that feature is on. Names only —
        // the response body is the point of typing the command.
        if (in_array(
            strtolower(trim((string) $settings->get('chat_commands_enabled', 'false'))),
            ['1', 'true', 'on', 'yes'],
            true
        )) {
            foreach ((new ChatCommandService())->activeList() as $row) {
                $out[] = [
                    'command'     => (string) $row['command'],
                    'description' => trim((string) ($row['description'] ?? '')),
                ];
            }
        }

        return $out;
    }

    /** The /help reply for a caller: exactly what they can type, and nothing else. */
    public function helpTextFor(int $usertype): string
    {
        $lines = ['Available commands:'];
        foreach ($this->availableTo($usertype) as $command) {
            $lines[] = '/' . $command['command']
                . ($command['description'] !== '' ? ' — ' . $command['description'] : '');
        }

        if (count($lines) === 1) {
            return 'There are no commands available to you here.';
        }

        return implode("\n", $lines);
    }
}
