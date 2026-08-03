<?php

namespace RadioChatBox\Controllers;

use InvalidArgumentException;
use Pramnos\Http\Request;
use Pramnos\Http\Response;
use Pramnos\Routing\Attributes\Route;
use RadioChatBox\Middleware\AdminAuthMiddleware;
use RadioChatBox\Services\ShowService;

/**
 * Radio show schedule: a public "upcoming shows" feed and admin CRUD for the
 * schedule (recurring or one-off broadcasts).
 */
final class ShowController
{
    /**
     * GET /api/shows/upcoming?limit=10 — the next upcoming show occurrences.
     * Public. 200 {success, shows}.
     */
    #[Route('/api/shows/upcoming', methods: 'GET', name: 'shows.upcoming')]
    public function upcoming(): Response
    {
        try {
            $limit = (int) Request::getInstance()->get('limit', 10, 'get');
            $now = new \DateTimeImmutable('now');
            return Response::json([
                'success' => true,
                'shows'   => (new ShowService())->upcoming($now, $limit),
            ]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('ShowController::upcoming failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * GET /api/shows/ical — the upcoming schedule as an iCalendar (.ics) feed,
     * subscribable in Google/Apple Calendar. Public.
     */
    #[Route('/api/shows/ical', methods: 'GET', name: 'shows.ical')]
    public function ical(): Response
    {
        try {
            $now = new \DateTimeImmutable('now');
            $shows = (new ShowService())->upcoming($now, 50);

            $lines = [
                'BEGIN:VCALENDAR',
                'VERSION:2.0',
                'PRODID:-//RadioChatBox//Schedule//EN',
                'CALSCALE:GREGORIAN',
                'METHOD:PUBLISH',
                'X-WR-CALNAME:Radio Schedule',
            ];
            foreach ($shows as $show) {
                $start = new \DateTimeImmutable((string) $show['next_start']);
                $startUtc = $start->setTimezone(new \DateTimeZone('UTC'));
                // Duration: use end_time if present, else default to 1 hour.
                $end = $startUtc->add(new \DateInterval('PT1H'));
                if (!empty($show['end_time'])) {
                    [$eh, $em] = array_pad(array_map('intval', explode(':', (string) $show['end_time'])), 2, 0);
                    $endLocal = $start->setTime($eh, $em);
                    if ($endLocal <= $start) {
                        $endLocal = $endLocal->add(new \DateInterval('P1D')); // wraps past midnight
                    }
                    $end = $endLocal->setTimezone(new \DateTimeZone('UTC'));
                }

                $lines[] = 'BEGIN:VEVENT';
                $lines[] = 'UID:show-' . ((int) $show['id']) . '-' . $startUtc->format('Ymd') . '@radiochatbox';
                $lines[] = 'DTSTAMP:' . $startUtc->format('Ymd\THis\Z');
                $lines[] = 'DTSTART:' . $startUtc->format('Ymd\THis\Z');
                $lines[] = 'DTEND:' . $end->format('Ymd\THis\Z');
                $lines[] = 'SUMMARY:' . $this->icsEscape((string) $show['title']);
                if (!empty($show['host'])) {
                    $lines[] = 'DESCRIPTION:' . $this->icsEscape('Host: ' . $show['host']
                        . (!empty($show['description']) ? ' — ' . $show['description'] : ''));
                } elseif (!empty($show['description'])) {
                    $lines[] = 'DESCRIPTION:' . $this->icsEscape((string) $show['description']);
                }
                $lines[] = 'END:VEVENT';
            }
            $lines[] = 'END:VCALENDAR';

            $ics = implode("\r\n", $lines) . "\r\n";
            return Response::make($ics)
                ->withHeader('Content-Type', 'text/calendar; charset=utf-8')
                ->withHeader('Content-Disposition', 'attachment; filename="radio-schedule.ics"');
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('ShowController::ical failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /** Escape a value for an iCalendar text field (RFC 5545). */
    private function icsEscape(string $text): string
    {
        $text = str_replace(['\\', "\n", "\r", ',', ';'], ['\\\\', '\\n', '', '\\,', '\\;'], $text);
        return $text;
    }

    /**
     * POST /api/shows/subscribe — {username, session_id, show_id, subscribe?}.
     * Subscribe/unsubscribe a listener to a show's air reminders. 200
     * {success, subscribed}; bad input -> 400; bad session -> 403.
     */
    #[Route('/api/shows/subscribe', methods: 'POST', name: 'shows.subscribe')]
    public function subscribe(): Response
    {
        try {
            $input = $_POST;
            $username = trim((string) ($input['username'] ?? ''));
            $sessionId = trim((string) ($input['session_id'] ?? ''));
            $showId = (int) ($input['show_id'] ?? 0);
            $subscribe = !array_key_exists('subscribe', $input)
                || in_array(strtolower((string) $input['subscribe']), ['1', 'true', 'on', 'yes'], true);

            if ($username === '' || $sessionId === '' || $showId <= 0) {
                return Response::json(['error' => 'username, session_id and show_id are required'], 400);
            }
            if ((new \RadioChatBox\Services\ChatService())->getSessionInfo($username, $sessionId) === null) {
                return Response::json(['error' => 'Invalid session'], 403);
            }

            $state = (new \RadioChatBox\Services\ShowSubscriptionService())->setSubscribed($username, $showId, $subscribe);
            return Response::json(['success' => true, 'subscribed' => $state]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('ShowController::subscribe failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * GET /api/shows/subscriptions?username=&session_id= — the show ids this user
     * is subscribed to (for rendering the subscribe toggles). 200 {success, show_ids}.
     */
    #[Route('/api/shows/subscriptions', methods: 'GET', name: 'shows.subscriptions')]
    public function subscriptions(): Response
    {
        try {
            $request = Request::getInstance();
            $username = trim((string) $request->get('username', '', 'get'));
            $sessionId = trim((string) $request->get('session_id', '', 'get'));
            if ($username === '' || $sessionId === '') {
                return Response::json(['error' => 'username and session_id are required'], 400);
            }
            if ((new \RadioChatBox\Services\ChatService())->getSessionInfo($username, $sessionId) === null) {
                return Response::json(['error' => 'Invalid session'], 403);
            }
            return Response::json([
                'success'  => true,
                'show_ids' => (new \RadioChatBox\Services\ShowSubscriptionService())->subscribedShowIds($username),
            ]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('ShowController::subscriptions failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /** GET /api/admin/shows — the full schedule (admin). 200 {success, shows}. */
    #[Route('/api/admin/shows', methods: 'GET', name: 'admin.shows.list', middleware: [AdminAuthMiddleware::class])]
    public function adminList(): Response
    {
        try {
            return Response::json(['success' => true, 'shows' => (new ShowService())->all()]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('ShowController::adminList failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /** POST /api/admin/shows — create a show. 200 {success, id}; bad input -> 400. */
    #[Route('/api/admin/shows', methods: 'POST', name: 'admin.shows.create', middleware: [AdminAuthMiddleware::class])]
    public function create(): Response
    {
        try {
            $id = (new ShowService())->create($_POST);
            return Response::json(['success' => true, 'id' => $id]);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('ShowController::create failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /** POST /api/admin/shows/update — {id, ...}. 200 {success}; bad input -> 400. */
    #[Route('/api/admin/shows/update', methods: 'POST', name: 'admin.shows.update', middleware: [AdminAuthMiddleware::class])]
    public function update(): Response
    {
        try {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                return Response::json(['error' => 'id is required'], 400);
            }
            (new ShowService())->update($id, $_POST);
            return Response::json(['success' => true]);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('ShowController::update failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /** POST /api/admin/shows/delete — {id}. 200 {success}; bad input -> 400. */
    #[Route('/api/admin/shows/delete', methods: 'POST', name: 'admin.shows.delete', middleware: [AdminAuthMiddleware::class])]
    public function delete(): Response
    {
        try {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                return Response::json(['error' => 'id is required'], 400);
            }
            (new ShowService())->delete($id);
            return Response::json(['success' => true]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('ShowController::delete failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }
}
