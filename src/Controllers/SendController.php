<?php

namespace RadioChatBox\Controllers;

use InvalidArgumentException;
use Pramnos\Http\Response;
use Pramnos\Routing\Attributes\Route;
use RadioChatBox\Services\ChatCommandService;
use RadioChatBox\Services\ChatService;
use RadioChatBox\Services\MessageFilter;
use RuntimeException;

/**
 * POST /api/send — post a public chat message.
 *
 * Migrated from public/api/send.php. The framework Request has already decoded
 * the JSON body into $_POST, so we read it there (re-reading php://input would
 * come back empty). Behaviour is preserved exactly: empty/invalid body -> 400,
 * rate-limit / public-chat-disabled -> 429, otherwise 500; success returns
 * {success, message}.
 */
final class SendController
{
    #[Route('/api/send', methods: 'POST', name: 'send.store')]
    public function store(): Response
    {
        try {
            // The framework Request populates $_POST from the JSON body.
            $input = $_POST;

            if (empty($input)) {
                throw new InvalidArgumentException('Invalid JSON');
            }

            $username    = $input['username'] ?? '';
            $message     = $input['message'] ?? '';
            $sessionId   = $input['sessionId'] ?? '';
            $replyTo     = $input['replyTo'] ?? null;
            $pinnedTrack = $input['pinned_track'] ?? null;

            $chatService = new ChatService();
            $chatMode    = $chatService->getSetting('chat_mode') ?? 'both';

            if ($chatMode === 'private') {
                throw new RuntimeException('Public chat is disabled. Please use private messages.');
            }

            // Moderator slash-commands (/mute /unmute /warn /ban): executed against
            // the target and NOT posted as a message. Role is checked in the service.
            if (is_string($message) && \RadioChatBox\Services\ModeratorCommandService::looksLikeCommand($message)) {
                $reply = (new \RadioChatBox\Services\ModeratorCommandService())
                    ->handle($username, $sessionId, $message);
                if ($reply !== null) {
                    return Response::json(['success' => true, 'command' => true, 'response' => $reply]);
                }
            }

            // /poll — create a live poll from the chat (permitted roles only). It
            // is NOT posted as a normal message; the poll card is broadcast instead.
            if (is_string($message) && str_starts_with(ltrim($message), '/poll')) {
                $pollResponse = $this->tryHandlePoll($username, $message, $sessionId, $chatService);
                if ($pollResponse !== null) {
                    return $pollResponse;
                }
            }

            // /help is a built-in, not one of the station's custom commands, and
            // was previously gated behind chat_commands_enabled along with them —
            // so on a station with no custom commands the one command that
            // answers "what can I type?" silently did nothing. It answers with
            // whatever THIS caller can actually run.
            if (is_string($message) && preg_match('/^\/help\b/i', ltrim($message))) {
                $session = $chatService->getSessionInfo($username, $sessionId);
                $usertype = \RadioChatBox\Services\Authz::usertypeForLabel(
                    (string) ($session['user_role'] ?? '')
                );
                return Response::json([
                    'success'  => true,
                    'command'  => true,
                    'response' => (new \RadioChatBox\Services\CommandCatalog())->helpTextFor($usertype),
                ]);
            }

            // /me <action> — the IRC emote, and the one command everyone who has
            // used a chat before tries. Unlike the rest it is not answered to the
            // sender: it becomes a message the room sees, so it falls through to
            // the normal send path with the text rewritten.
            if (is_string($message) && preg_match('/^\/me\s+(.+)$/is', ltrim($message), $emote)) {
                $message = '* ' . $username . ' ' . trim($emote[1]);
            } elseif (is_string($message) && preg_match('/^\/me\s*$/i', ltrim($message))) {
                return Response::json(['success' => true, 'command' => true,
                    'response' => 'Usage: /me <what you are doing>']);
            }

            // Slash-commands (when enabled): if the message is a recognised
            // command, answer the sender directly and DON'T post/broadcast it as a
            // chat message. An unrecognised /foo falls through as a normal message.
            if (is_string($message) && str_starts_with(ltrim($message), '/')
                && $this->commandsEnabled($chatService)) {
                $reply = (new ChatCommandService())->respondTo($message);
                if ($reply !== null) {
                    return Response::json(['success' => true, 'command' => true, 'response' => $reply]);
                }
            }

            $filtered = MessageFilter::filterPublicMessage($message);
            if (($filtered['allowed'] ?? true) === false) {
                // e.g. the profanity filter in 'block' mode rejects the message.
                throw new InvalidArgumentException($filtered['reason'] ?: 'Message not allowed');
            }
            $message = $filtered['filtered'];

            $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';

            $result = $chatService->postMessage($username, $message, $ipAddress, $sessionId, $replyTo, $pinnedTrack);

            // @mentions → a persistent notification for each mentioned, known user.
            $this->notifyMentions($username, $message, is_array($result) ? ($result['id'] ?? null) : null, $chatService);

            return Response::json(['success' => true, 'message' => $result]);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        } catch (RuntimeException $e) {
            return Response::json(['error' => $e->getMessage()], 429);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log($e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Handle a /poll command: "/poll Question | Option 1 | Option 2 [| …]".
     * Returns a Response (success or a system error shown to the sender) when the
     * message was a poll command, or null to let it fall through as normal text.
     * Gated by polls_enabled and the poll_min_usertype role.
     */
    private function tryHandlePoll(string $username, string $message, string $sessionId, ChatService $chatService): ?Response
    {
        if ($chatService->getSetting('polls_enabled') !== 'true') {
            return null; // feature off → treat as a normal message
        }

        // Only a permitted role may create polls.
        $session = $chatService->getSessionInfo($username, $sessionId);
        $role = (string) ($session['user_role'] ?? '');
        $minLabel = (string) ($chatService->getSetting('poll_min_usertype') ?: 'moderator');
        if (\RadioChatBox\Services\Authz::usertypeForLabel($role) < \RadioChatBox\Services\Authz::usertypeForLabel($minLabel)) {
            return Response::json(['success' => true, 'command' => true, 'response' => 'You are not allowed to create polls.']);
        }

        // Parse "/poll question | opt | opt".
        $body = trim((string) preg_replace('/^\/poll\b/i', '', ltrim($message)));
        $bits = array_map('trim', explode('|', $body));
        $bits = array_values(array_filter($bits, static fn (string $b): bool => $b !== ''));
        if (count($bits) < 3) {
            // The chat client opens a builder for a bare "/poll", so reaching here
            // means either a half-typed command or a client that has none. Point
            // at the builder first and keep the syntax as the fallback, rather
            // than answering an interface question with a manual.
            return Response::json(['success' => true, 'command' => true,
                'response' => "Send \"/poll\" on its own to open the poll builder.\n"
                    . 'Or type it in one go: /poll Question | Option 1 | Option 2']);
        }
        $question = array_shift($bits);

        try {
            $service = new \RadioChatBox\Services\PollService();
            $id = $service->create($question, $bits, $username);
            $results = $service->results($id);
            try {
                \Pramnos\Broadcasting\BroadcastingManager::instance()
                    ->broadcast('chat:updates', 'poll', ['type' => 'poll', 'poll' => $results]);
            } catch (\Throwable $e) {
                // best-effort
            }
            return Response::json(['success' => true, 'command' => true, 'response' => '📊 Poll created.']);
        } catch (InvalidArgumentException $e) {
            return Response::json(['success' => true, 'command' => true, 'response' => $e->getMessage()]);
        }
    }

    /**
     * Create an inbox notification for each distinct @mentioned user (other than
     * the sender) who is a known participant. Best-effort and capped so a message
     * full of @-tokens can't fan out unboundedly.
     */
    private function notifyMentions(string $sender, string $message, ?string $messageId, ChatService $chatService): void
    {
        try {
            if (!preg_match_all('/@([\p{L}\p{N}_]{2,50})/u', $message, $m)) {
                return;
            }
            $names = array_slice(array_values(array_unique($m[1])), 0, 5);
            $notifier = new \RadioChatBox\Services\NotificationService();
            foreach ($names as $name) {
                if (mb_strtolower($name) === mb_strtolower($sender)) {
                    continue;
                }
                // Only notify a name that is (or recently was) a real participant.
                if ($chatService->isKnownParticipant($name)) {
                    $notifier->add(
                        $name,
                        'mention',
                        "{$sender} mentioned you",
                        mb_substr($message, 0, 140),
                        $messageId
                    );
                }
            }
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('SendController::notifyMentions failed: ' . $e->getMessage(), 'radiochatbox');
        }
        // @codeCoverageIgnoreEnd
    }

    /** Whether admin-defined slash-commands are switched on. */
    private function commandsEnabled(ChatService $chatService): bool
    {
        $value = $chatService->getSetting('chat_commands_enabled');
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes'], true);
    }
}
