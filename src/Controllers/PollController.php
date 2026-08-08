<?php

namespace RadioChatBox\Controllers;

use InvalidArgumentException;
use Pramnos\Broadcasting\BroadcastingManager;
use Pramnos\Http\Request;
use Pramnos\Http\Response;
use Pramnos\Routing\Attributes\Route;
use RadioChatBox\AdminAuth;
use RadioChatBox\Http\Csv;
use RadioChatBox\Middleware\AdminAuthMiddleware;
use RadioChatBox\Services\Authz;
use RadioChatBox\Services\ChatService;
use RadioChatBox\Services\PollService;
use RadioChatBox\Services\SettingsService;
use RuntimeException;

/**
 * Live chat polls: a public endpoint for the active poll + voting, and admin
 * endpoints to create / close / list. Gated by the polls_enabled setting. A
 * change broadcasts a 'poll' cue on the chat channel so clients update live.
 */
class PollController
{
    /**
     * GET /api/polls/active?session_id=… — the current active poll with live
     * results (and this session's vote). 200 {success, poll:{…}|null}. Feature
     * off -> 404.
     */
    #[Route('/api/polls/active', methods: 'GET', name: 'polls.active')]
    public function active(): Response
    {
        if (!$this->isEnabled()) {
            return Response::json(['success' => false, 'error' => 'Polls are disabled'], 404);
        }
        $session = (string) Request::getInstance()->get('session_id', '', 'get');
        $poll = (new PollService())->activeResults($session !== '' ? $session : null);
        return Response::json(['success' => true, 'poll' => $poll])->withHeader('Cache-Control', 'no-cache');
    }

    /**
     * POST /api/polls/vote — {poll_id, option_index, username, session_id}. Casts
     * a vote and returns fresh results. 200 {success, poll}; feature off -> 404;
     * bad input -> 400; invalid session -> 403; closed poll -> 409.
     */
    #[Route('/api/polls/vote', methods: 'POST', name: 'polls.vote')]
    public function vote(): Response
    {
        try {
            if (!$this->isEnabled()) {
                return Response::json(['success' => false, 'error' => 'Polls are disabled'], 404);
            }

            $input = $_POST;
            $pollId = (int) ($input['poll_id'] ?? 0);
            $optionIndex = (int) ($input['option_index'] ?? -1);
            $username = trim((string) ($input['username'] ?? ''));
            $sessionId = trim((string) ($input['session_id'] ?? ''));

            if ($username === '' || $sessionId === '') {
                return Response::json(['error' => 'username and session_id are required'], 400);
            }
            if ((new ChatService())->getSessionInfo($username, $sessionId) === null) {
                return Response::json(['error' => 'Invalid session'], 403);
            }

            $results = (new PollService())->vote($pollId, $sessionId, $username, $optionIndex);
            $this->broadcast($results);

            return Response::json(['success' => true, 'poll' => $results]);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        } catch (RuntimeException $e) {
            return Response::json(['error' => $e->getMessage()], 409);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('PollController::vote failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * POST /api/polls/close — {poll_id, username, session_id}. Ends a running
     * poll from the chat.
     *
     * Closing was previously only possible from the admin panel, so the
     * moderator who opened a poll with /poll had to leave the conversation to
     * end it — and an ordinary listener allowed to create one (poll_min_usertype
     * can be lowered) could not end it at all. The author may close their own;
     * a moderator may close anyone's.
     *
     * 200 {success, poll}; 403 for a bad session or someone else's poll.
     */
    #[Route('/api/polls/close', methods: 'POST', name: 'polls.close')]
    public function closeFromChat(): Response
    {
        try {
            if (!$this->isEnabled()) {
                return Response::json(['success' => false, 'error' => 'Polls are disabled'], 404);
            }

            $pollId    = (int) ($_POST['poll_id'] ?? 0);
            $username  = trim((string) ($_POST['username'] ?? ''));
            $sessionId = trim((string) ($_POST['session_id'] ?? ''));

            if ($pollId <= 0 || $username === '' || $sessionId === '') {
                return Response::json(['error' => 'poll_id, username and session_id are required'], 400);
            }

            $session = (new ChatService())->getSessionInfo($username, $sessionId);
            if ($session === null) {
                return Response::json(['error' => 'Invalid session'], 403);
            }

            $service = new PollService();
            $poll = $service->results($pollId);
            if (($poll['id'] ?? 0) === 0) {
                return Response::json(['error' => 'No such poll'], 404);
            }

            $isAuthor = mb_strtolower((string) ($poll['created_by'] ?? '')) === mb_strtolower($username);
            $isStaff  = Authz::usertypeForLabel((string) ($session['user_role'] ?? '')) >= Authz::MODERATOR;
            if (!$isAuthor && !$isStaff) {
                return Response::json(['error' => 'Only the person who started this poll can close it'], 403);
            }

            $service->close($pollId);
            $results = $service->results($pollId);
            $this->broadcast($results);

            return Response::json(['success' => true, 'poll' => $results]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('PollController::closeFromChat failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * POST /api/admin/polls — {question, options:[], expires_minutes?}. Creates a
     * poll (closing any active one) and broadcasts it. 200 {success, poll};
     * bad input -> 400. Admin-only.
     */
    #[Route('/api/admin/polls', methods: 'POST', name: 'admin.polls.create', middleware: [AdminAuthMiddleware::class])]
    public function create(): Response
    {
        try {
            $input = $_POST;
            $question = (string) ($input['question'] ?? '');
            $options = $input['options'] ?? [];
            if (!is_array($options)) {
                $options = array_map('trim', explode("\n", (string) $options));
            }
            $expires = isset($input['expires_minutes']) && $input['expires_minutes'] !== ''
                ? (int) $input['expires_minutes'] : null;

            $admin = AdminAuth::getCurrentUser();
            $service = new PollService();
            $id = $service->create($question, $options, (string) ($admin['username'] ?? 'admin'), $expires);
            $results = $service->results($id);
            $this->broadcast($results);

            return Response::json(['success' => true, 'poll' => $results]);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('PollController::create failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * POST /api/admin/polls/close — {id}. Closes a poll and broadcasts the change.
     * 200 {success}; bad input -> 400. Admin-only.
     */
    #[Route('/api/admin/polls/close', methods: 'POST', name: 'admin.polls.close', middleware: [AdminAuthMiddleware::class])]
    public function close(): Response
    {
        try {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                return Response::json(['error' => 'id is required'], 400);
            }
            $service = new PollService();
            $service->close($id);
            $this->broadcast($service->results($id));
            return Response::json(['success' => true]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('PollController::close failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * GET /api/admin/polls — recent polls with results (admin history). 200
     * {success, polls}. Admin-only.
     */
    #[Route('/api/admin/polls', methods: 'GET', name: 'admin.polls.list', middleware: [AdminAuthMiddleware::class])]
    public function list(): Response
    {
        try {
            $service = new PollService();
            $polls = array_map(
                fn (array $p): array => $service->results((int) $p['id']) + [
                    'created_at' => $p['created_at'] ?? null,
                    'created_by' => $p['created_by'] ?? null,
                ],
                $service->list(50, 0)
            );
            return Response::json(['success' => true, 'polls' => $polls]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('PollController::list failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * GET /api/admin/polls/export?id= — download one poll's results as CSV
     * (question, option, votes, percent). Admin-only.
     */
    #[Route('/api/admin/polls/export', methods: 'GET', name: 'admin.polls.export', middleware: [AdminAuthMiddleware::class])]
    public function export(): Response
    {
        try {
            $id = (int) Request::getInstance()->get('id', 0, 'get');
            $results = (new PollService())->results($id);
            if (($results['id'] ?? 0) === 0) {
                return Response::json(['error' => 'Poll not found'], 404);
            }

            $total = (int) ($results['total'] ?? 0);
            $rows = [];
            foreach (($results['options'] ?? []) as $i => $option) {
                $count = (int) ($results['counts'][$i] ?? 0);
                $pct = $total > 0 ? round($count / $total * 100, 1) : 0;
                $rows[] = [$results['question'], $option, $count, $pct . '%'];
            }

            $csv = Csv::build(['question', 'option', 'votes', 'percent'], $rows);
            return Csv::download($csv, 'poll-' . $id . '-results.csv');
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('PollController::export failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * GET /api/admin/polls/voters?id= — who voted for what (named voting, admin
     * view). 200 {success, question, options, voters}; missing poll -> 404.
     */
    #[Route('/api/admin/polls/voters', methods: 'GET', name: 'admin.polls.voters', middleware: [AdminAuthMiddleware::class])]
    public function voters(): Response
    {
        try {
            $id = (int) Request::getInstance()->get('id', 0, 'get');
            $data = (new PollService())->voters($id);
            if ($data['options'] === []) {
                return Response::json(['error' => 'Poll not found'], 404);
            }
            return Response::json(['success' => true] + $data);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('PollController::voters failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    private function isEnabled(): bool
    {
        $value = (new SettingsService())->get('polls_enabled');
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes'], true);
    }

    /** Push the current poll state to every client (no polling). */
    private function broadcast(array $results): void
    {
        try {
            BroadcastingManager::instance()->broadcast('chat:updates', 'poll', ['type' => 'poll', 'poll' => $results]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('poll broadcast failed: ' . $e->getMessage(), 'radiochatbox');
        }
        // @codeCoverageIgnoreEnd
    }
}
