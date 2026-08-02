<?php

namespace RadioChatBox\Controllers;

use InvalidArgumentException;
use Pramnos\Http\Request;
use Pramnos\Http\Response;
use Pramnos\Routing\Attributes\Route;
use RadioChatBox\Services\ChatService;
use RadioChatBox\Services\RadioStatusService;
use RadioChatBox\Services\SettingsService;
use RadioChatBox\Services\TrackVoteService;

/**
 * Now-playing voting: a listener gives the currently-playing track a thumbs
 * up/down. The track is resolved server-side from the live now-playing feed
 * (never trusted from the client) so a vote always applies to what is actually
 * playing. Gated by track_voting_enabled.
 */
class TrackVoteController
{
    /**
     * GET /api/songs/vote?session_id=… — the tally for the current track (and
     * this session's vote). 200 {success, track, up, down, my_vote}; feature off
     * -> 404; nothing playing -> {success:true, track:null, up:0, down:0}.
     */
    #[Route('/api/songs/vote', methods: 'GET', name: 'songs.vote.tally')]
    public function tally(): Response
    {
        if (!$this->isEnabled()) {
            return Response::json(['success' => false, 'error' => 'Voting is disabled'], 404);
        }

        $display = $this->currentTrackDisplay();
        if ($display === null) {
            return Response::json(['success' => true, 'track' => null, 'up' => 0, 'down' => 0, 'my_vote' => 0]);
        }

        $session = (string) Request::getInstance()->get('session_id', '', 'get');
        $tally = (new TrackVoteService())->tally($display, $session !== '' ? $session : null);

        return Response::json(['success' => true, 'track' => $display] + $tally)
            ->withHeader('Cache-Control', 'no-cache');
    }

    /**
     * POST /api/songs/vote — {session_id, username?, direction:up|down}. Votes on
     * the current track (toggle off if repeated). 200 {success, track, up, down,
     * my_vote}; feature off -> 404; bad input -> 400; invalid session -> 403;
     * nothing playing -> 409.
     */
    #[Route('/api/songs/vote', methods: 'POST', name: 'songs.vote.cast')]
    public function cast(): Response
    {
        try {
            if (!$this->isEnabled()) {
                return Response::json(['success' => false, 'error' => 'Voting is disabled'], 404);
            }

            $input = $_POST;
            $username = trim((string) ($input['username'] ?? ''));
            $sessionId = trim((string) ($input['session_id'] ?? ''));
            $direction = (string) ($input['direction'] ?? '');

            if ($username === '' || $sessionId === '') {
                return Response::json(['error' => 'username and session_id are required'], 400);
            }

            // Verify the caller owns this session (no voting as someone else).
            if ((new ChatService())->getSessionInfo($username, $sessionId) === null) {
                return Response::json(['error' => 'Invalid session'], 403);
            }

            $display = $this->currentTrackDisplay();
            if ($display === null) {
                return Response::json(['error' => 'Nothing is playing right now'], 409);
            }

            $tally = (new TrackVoteService())->vote($display, $sessionId, $username, $direction);

            return Response::json(['success' => true, 'track' => $display] + $tally);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('TrackVoteController::cast failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /** The current now-playing display string, or null if nothing is active. */
    private function currentTrackDisplay(): ?string
    {
        try {
            $now = (new RadioStatusService())->getNowPlaying();
            if (!empty($now['active']) && !empty($now['display'])) {
                return trim((string) $now['display']);
            }
        } catch (\Throwable $e) {
            // treat as nothing playing
        }
        return null;
    }

    private function isEnabled(): bool
    {
        $value = (new SettingsService())->get('track_voting_enabled');
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes'], true);
    }
}
