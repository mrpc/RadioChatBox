<?php

namespace RadioChatBox\Services;

/**
 * In-chat moderator slash-commands: /mute, /unmute, /warn, /ban.
 *
 * A moderator (or above) types the command in the public chat; it is executed
 * against the named target and never posted as a message. Everything is routed
 * through the existing services (timeout, warnings, nickname bans) so the effect
 * is identical to the equivalent admin-panel action, and each command is written
 * to the moderation log.
 */
class ModeratorCommandService
{
    /** Commands and the minimum role that may run them (all moderator+). */
    private const COMMANDS = ['mute', 'unmute', 'warn', 'ban'];

    private ChatService $chat;

    public function __construct(?ChatService $chat = null)
    {
        $this->chat = $chat ?? new ChatService();
    }

    /** Whether a message begins with a moderator command we handle. */
    public static function looksLikeCommand(string $message): bool
    {
        $first = strtolower(ltrim(explode(' ', ltrim($message))[0] ?? ''));
        return in_array(ltrim($first, '/'), self::COMMANDS, true) && str_starts_with(ltrim($message), '/');
    }

    /**
     * Handle a moderator command. Returns a system reply string to show the
     * sender, or null if the message isn't one of our commands (let it fall
     * through as normal chat).
     */
    public function handle(string $actorUsername, string $actorSessionId, string $message): ?string
    {
        $parts = preg_split('/\s+/', trim($message)) ?: [];
        $command = ltrim(strtolower($parts[0] ?? ''), '/');
        if (!in_array($command, self::COMMANDS, true)) {
            return null;
        }

        // Only a moderator or above may use these, verified against the session.
        $session = $this->chat->getSessionInfo($actorUsername, $actorSessionId);
        $role = (string) ($session['user_role'] ?? '');
        if (Authz::usertypeForLabel($role) < Authz::MODERATOR) {
            return '⛔ Moderator commands are for staff only.';
        }

        $target = trim((string) ($parts[1] ?? ''));
        $target = ltrim($target, '@');
        if ($target === '') {
            return "Usage: /{$command} <username> …";
        }
        if (mb_strtolower($target) === mb_strtolower($actorUsername)) {
            return "You can't {$command} yourself.";
        }

        $rest = trim(implode(' ', array_slice($parts, 2)));

        switch ($command) {
            case 'mute':
                return $this->mute($actorUsername, $target, $rest);
            case 'unmute':
                return $this->unmute($actorUsername, $target);
            case 'warn':
                return $this->warn($actorUsername, $target, $rest);
            case 'ban':
                return $this->ban($actorUsername, $target, $rest);
        }

        return null; // unreachable
    }

    /** /mute <user> [minutes] — temporary silence (default 5 minutes). */
    private function mute(string $actor, string $target, string $rest): string
    {
        $minutes = 5;
        if ($rest !== '' && is_numeric(trim($rest))) {
            $minutes = (int) trim($rest);
        }
        $minutes = max(1, min($minutes, 1440)); // 1 minute .. 24 hours
        $this->chat->timeoutUser($target, $minutes * 60);
        $this->log($actor, 'mute', $target, "{$minutes}m (chat command)");
        return "🔇 Muted {$target} for {$minutes} minute" . ($minutes === 1 ? '' : 's') . '.';
    }

    /** /unmute <user> — lift a mute early. */
    private function unmute(string $actor, string $target): string
    {
        $this->chat->clearUserTimeout($target);
        $this->log($actor, 'unmute', $target, 'chat command');
        return "🔊 Unmuted {$target}.";
    }

    /** /warn <user> [reason] — record a warning (may auto-timeout on threshold). */
    private function warn(string $actor, string $target, string $reason): string
    {
        $result = (new WarningService())->warn($target, $actor, $reason !== '' ? $reason : null);
        $this->log($actor, 'warn', $target, $reason !== '' ? $reason : null);
        $msg = "⚠️ Warned {$target} ({$result['active_count']} active).";
        if (!empty($result['auto_timed_out'])) {
            $msg .= ' Auto-timed-out (warning threshold reached).';
        }
        return $msg;
    }

    /** /ban <user> [reason] — ban the nickname from the chat. */
    private function ban(string $actor, string $target, string $reason): string
    {
        $this->chat->banNickname($target, $reason, $actor);
        $this->log($actor, 'ban', $target, $reason !== '' ? $reason : null);
        return "🚫 Banned {$target}.";
    }

    /** Record the action in the moderation log (best-effort). */
    private function log(string $actor, string $action, string $target, ?string $details): void
    {
        try {
            (new ModerationLog())->record($actor, $action, $target, $details);
        } catch (\Throwable $e) {
            // Non-fatal — the action itself already applied.
        }
    }
}
