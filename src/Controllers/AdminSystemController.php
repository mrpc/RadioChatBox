<?php

namespace RadioChatBox\Controllers;

use InvalidArgumentException;
use Pramnos\Http\Request;
use Pramnos\Http\Response;
use Pramnos\Redis\ConnectionManager;
use Pramnos\Routing\Attributes\Route;
use RadioChatBox\AdminAuth;
use RadioChatBox\Http\Csv;
use RadioChatBox\Http\Validate;
use RadioChatBox\Services\ArtworkService;
use RadioChatBox\Services\ChatService;
use RadioChatBox\ConsoleCommands\RadioChatBoxDaemons;
use Pramnos\Database\Database;
use RadioChatBox\Installation;
use RadioChatBox\JobQueue;
use RadioChatBox\Middleware\AdminAuthMiddleware;
use RadioChatBox\Services\PhotoService;
use RadioChatBox\Services\ReactionService;
use RadioChatBox\Services\Scheduler;
use RadioChatBox\Services\SettingsService;
use Pramnos\Console\WorkerLock;

/**
 * Admin "system" resource controller — infrastructure, moderation views and
 * maintenance actions for the admin dashboard.
 *
 * Consolidates nine legacy file-per-endpoint scripts under public/api/admin/
 * into one attribute-routed controller. Every route carries AdminAuthMiddleware,
 * which returns 401 {"error":"Unauthorized"} for unauthenticated requests before
 * the action runs, so no action calls AdminAuth::verify()/authenticate() itself;
 * actions that inspected the current admin (role/username) keep their
 * AdminAuth::getCurrentUser() calls. Each action preserves the legacy JSON keys,
 * status codes and error mapping exactly.
 */
final class AdminSystemController
{
    /**
     * POST /api/admin/flush-redis — flush every Redis key for this installation.
     *
     * Migrated from public/api/admin/flush-redis.php. Deletes all keys matching
     * the database prefix and returns {success, message, keys_cleared}. Errors map
     * to 500 {"error":"Server error: <msg>"}.
     */
    #[Route('/api/admin/flush-redis', methods: 'POST', name: 'admin.system.flush-redis', middleware: [AdminAuthMiddleware::class])]
    public function flushRedis(): Response
    {
        try {
            $connections = ConnectionManager::getInstance();
            $redis  = $connections->connection();
            $prefix = $connections->prefix();

            // Get all keys with this database prefix.
            $pattern = $prefix . '*';
            $keys    = $redis->keys($pattern);

            $keysCleared = 0;
            if (!empty($keys)) {
                // Delete all keys matching the pattern.
                $keysCleared = $redis->del($keys);
            }

            return Response::json([
                'success'      => true,
                'message'      => 'Redis cache flushed successfully',
                'keys_cleared' => $keysCleared,
            ]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            return Response::json(['error' => 'Server error: ' . $e->getMessage()], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * GET|POST /api/admin/clear-messages-cache — drop only message-related Redis
     * cache keys (leaves user sessions untouched).
     *
     * Migrated from public/api/admin/clear-messages-cache.php (which had no HTTP
     * method restriction; the dashboard calls it via POST). Returns {success,
     * message, keys_cleared (list), timestamp}. Errors map to 500
     * {"success":false,"error":"Failed to clear cache: <msg>"}.
     */
    #[Route('/api/admin/clear-messages-cache', methods: ['GET', 'POST'], name: 'admin.system.clear-messages-cache', middleware: [AdminAuthMiddleware::class])]
    public function clearMessagesCache(): Response
    {
        try {
            $connections = ConnectionManager::getInstance();
            $redis  = $connections->connection();
            $prefix = $connections->prefix();

            $keysCleared = [];

            // Clear chat messages list.
            $messagesKey = $prefix . 'chat:messages';
            if ($redis->exists($messagesKey)) {
                $redis->del($messagesKey);
                $keysCleared[] = 'chat:messages';
            }

            // Clear any message-related cached queries.
            $pattern = $prefix . 'cache:messages:*';
            $keys    = $redis->keys($pattern);
            if ($keys && count($keys) > 0) {
                foreach ($keys as $key) {
                    $redis->del($key);
                    $keysCleared[] = str_replace($prefix, '', $key);
                }
            }

            return Response::json([
                'success'      => true,
                'message'      => 'Message cache cleared successfully',
                'keys_cleared' => $keysCleared,
                'timestamp'    => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            return Response::json([
                'success' => false,
                'error'   => 'Failed to clear cache: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/admin/restart-workers — ask every running background worker to
     * restart, so freshly-deployed code is picked up.
     *
     * PHP loads a class once per process, so a long-running worker keeps executing
     * the code it started with until it is replaced. This drops the graceful-stop
     * sentinel (`<lock>.stop`) next to each worker's lock — exactly what the
     * worker's shouldStop() watches — so it exits cleanly and the daemon supervisor
     * respawns it with the new code. If the supervisor is not running the workers
     * will stop but not come back on their own, so the response says which case it
     * was. Restricted to full admins (it is a service-control action).
     */
    #[Route('/api/admin/restart-workers', methods: 'POST', name: 'admin.system.restart-workers', middleware: [AdminAuthMiddleware::class])]
    public function restartWorkers(): Response
    {
        $current = AdminAuth::getCurrentUser();
        if (!$current || !in_array($current['role'], ['root', 'owner', 'administrator'], true)) {
            return Response::json(['error' => 'Forbidden: restarting workers requires an administrator'], 403);
        }

        try {
            $status    = (new RadioChatBoxDaemons())->status();
            $signalled = [];
            $failed    = [];

            foreach (($status['daemons'] ?? []) as $daemon) {
                $lockFile = isset($daemon['lockFile']) ? (string) $daemon['lockFile'] : '';
                $id       = (string) ($daemon['id'] ?? ($lockFile !== '' ? basename($lockFile) : 'worker'));

                // Only signal a worker that actually has a live lock file.
                if ($lockFile === '' || !file_exists($lockFile)) {
                    continue;
                }

                if (@file_put_contents($lockFile . '.stop', '1') !== false) {
                    $signalled[] = $id;
                } else {
                    $failed[] = $id;
                }
            }

            $supervisorRunning = (bool) ($status['running'] ?? false);

            return Response::json([
                'success'            => true,
                'supervisor_running' => $supervisorRunning,
                'restarted'          => $signalled,
                'failed'             => $failed,
                'message'            => $supervisorRunning
                    ? 'Signalled ' . count($signalled) . ' worker(s) to restart — the supervisor will respawn them with the new code within a few seconds.'
                    : 'Signalled ' . count($signalled) . ' worker(s) to stop, but the supervisor is not running, so they will only come back when your service manager restarts it.',
            ]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('restart-workers error: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Failed to signal the workers'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * GET /api/admin/worker-status — health of the background worker, its
     * supervisor and the periodic-task schedule, for the dashboard.
     *
     * Migrated from public/api/admin/worker-status.php. Returns the full status
     * payload ({success, instance, root, supervisor, daemons, running, wedged,
     * pid, ..., queue, schedule, schedule_failures}). Errors map to 500
     * {"error":"Failed to read worker status"} and are logged.
     */
    #[Route('/api/admin/worker-status', methods: 'GET', name: 'admin.system.worker-status', middleware: [AdminAuthMiddleware::class])]
    public function workerStatus(): Response
    {
        try {
            $settings = new SettingsService();
            $lock     = new WorkerLock('worker', Installation::lockPath());
            $queue    = new JobQueue();

            $state        = $lock->readState();
            $heartbeatAge = $lock->heartbeatAge($state);

            // "Running" is the lock being held by a live process; "wedged" is a
            // process that is alive but has stopped making progress.
            $running = $state !== null && $lock->isHeldByAnother();
            $wedged  = $lock->holderIsWedged();

            $tasks         = [];
            $scheduler     = new Scheduler($settings);
            $scheduleState = $scheduler->state();
            $due           = $scheduler->dueTasks();

            foreach ($scheduler->tasks() as $task => $meta) {
                $row = $scheduleState[$task] ?? null;

                $tasks[] = [
                    'task'             => $task,
                    'description'      => $meta['description'],
                    'every_seconds'    => (int) $meta['every'],
                    'due_now'          => in_array($task, $due, true),
                    'last_run_at'      => $row['last_run_at'] ?? null,
                    'last_status'      => $row['last_status'] ?? null,
                    'last_duration_ms' => isset($row['last_duration_ms']) ? (int) $row['last_duration_ms'] : null,
                    'last_error'       => $row['last_error'] ?? null,
                    'runs'             => (int) ($row['runs'] ?? 0),
                    'failures'         => (int) ($row['failures'] ?? 0),
                ];
            }

            // The supervisor is what restarts a dead worker, so "worker stopped"
            // and "nothing is watching it" are different problems. Its health comes
            // from the framework daemon orchestrator (the process `rcb daemons`
            // runs) — reading its own lock/state, so this reflects the supervisor
            // that is actually running.
            $supervisor = (new RadioChatBoxDaemons())->status();

            return Response::json([
                'success'  => true,
                // Several installations can share a server; say which this one is.
                'instance' => Installation::id(),
                'root'     => Installation::root(),
                'supervisor' => [
                    'running'               => $supervisor['running'],
                    'pid'                   => $supervisor['pid'],
                    'heartbeat_age_seconds' => $supervisor['heartbeat_age_seconds'],
                ],
                'daemons'               => $supervisor['daemons'],
                'running'               => $running,
                'wedged'                => $wedged,
                'pid'                   => $state['pid'] ?? null,
                'host'                  => $state['host'] ?? null,
                'started_at'            => $state['started_at'] ?? null,
                'uptime_seconds'        => isset($state['started_at']) ? max(0, time() - (int) $state['started_at']) : null,
                'heartbeat_age_seconds' => $heartbeatAge,
                'stale_after_seconds'   => $lock->getStaleAfter(),
                'jobs_processed'        => isset($state['jobs_processed']) ? (int) $state['jobs_processed'] : null,
                'current_job'           => $state['current_job'] ?? null,
                'lock_path'             => $lock->getPath(),
                'queue' => [
                    'size'            => $queue->size(),
                    'next_in_seconds' => $queue->secondsUntilNext(),
                ],
                // A stopped worker means no bot replies AND no scheduled maintenance.
                'schedule'           => $tasks,
                'schedule_failures'  => count(array_filter($tasks, static fn (array $t): bool => $t['last_status'] === 'failed')),
            ]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('worker-status error: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Failed to read worker status'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * GET /api/admin/active-users — everyone the frontend shows (real + active
     * fake users).
     *
     * Migrated from public/api/admin/active-users.php. Returns {success, count,
     * users} with count === number of users. Errors map to 500
     * {"error":"Internal server error"} and are logged.
     */
    #[Route('/api/admin/active-users', methods: 'GET', name: 'admin.system.active-users', middleware: [AdminAuthMiddleware::class])]
    public function activeUsers(): Response
    {
        try {
            $chatService = new ChatService();

            // Get all users (real + active fake) — same as the frontend sees.
            $allUsers = $chatService->getAllUsers();

            // Count only includes what is shown (real + active fake).
            $count = count($allUsers);

            return Response::json([
                'success' => true,
                'count'   => $count,
                'users'   => $allUsers,
            ]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log($e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * GET /api/admin/inactive-users — paginated list of users who have connected
     * before but are not currently in a session.
     *
     * Migrated from public/api/admin/inactive-users.php. Returns {success, users,
     * pagination:{page, limit, total, total_pages}}. Errors map to 500
     * {"error":"Server error: <msg>"}.
     */
    #[Route('/api/admin/inactive-users', methods: 'GET', name: 'admin.system.inactive-users', middleware: [AdminAuthMiddleware::class])]
    public function inactiveUsers(): Response
    {
        $db = Database::getInstance();

        try {
            $request = Request::getInstance();
            $page    = (int) $request->get('page', 1, 'get');
            $limit   = min((int) $request->get('limit', 100, 'get'), 200);
            $offset  = ($page - 1) * $limit;
            $search  = trim((string) $request->get('search', '', 'get'));

            // Optional username filter (ILIKE); kept as a bound param either way.
            $searchClause = $search !== '' ? " AND u.username ILIKE :search" : '';
            $searchParams = $search !== '' ? ['search' => '%' . $search . '%'] : [];

            // Get total count (analytical query with NOT IN subquery).
            $countResult = $db->preparedQuery("
                SELECT COUNT(DISTINCT u.username)
                FROM user_activity u
                WHERE u.username NOT IN (SELECT username FROM presence_sessions)
                {$searchClause}
            ", $searchParams);
            $total      = (int) ($countResult ? $countResult->fetchColumn() : 0);
            $totalPages = ceil($total / $limit);

            // Users from user_activity who are NOT in sessions: everyone who has
            // ever connected but is not currently active (correlated subqueries +
            // DISTINCT + NULLS LAST — kept verbatim via preparedQuery).
            $result = $db->preparedQuery("
                SELECT DISTINCT
                    u.username,
                    u.ip_address,
                    p.age,
                    p.location,
                    p.sex,
                    (SELECT MAX(created_at) FROM chat_messages m WHERE m.username = u.username) as last_message_at,
                    (SELECT COUNT(*) FROM chat_messages m WHERE m.username = u.username) as message_count
                FROM user_activity u
                LEFT JOIN user_profiles p ON u.username = p.username
                WHERE u.username NOT IN (SELECT username FROM presence_sessions)
                {$searchClause}
                ORDER BY last_message_at DESC NULLS LAST
                LIMIT :limit OFFSET :offset
            ", ['limit' => $limit, 'offset' => $offset] + $searchParams);

            $inactiveUsers = $result ? $result->fetchAll() : [];

            return Response::json([
                'success'    => true,
                'users'      => $inactiveUsers,
                'pagination' => [
                    'page'        => $page,
                    'limit'       => $limit,
                    'total'       => $total,
                    'total_pages' => $totalPages,
                ],
            ]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            return Response::json(['error' => 'Server error: ' . $e->getMessage()], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * GET /api/admin/messages — paginated moderation view of chat messages.
     * Root admins additionally see private messages.
     *
     * Migrated from public/api/admin/messages.php. Keeps the AdminAuth::
     * getCurrentUser() role check that gates private messages. Returns {success,
     * messages, include_private, chat_mode, pagination:{page, limit, total,
     * total_pages}}. Errors map to 500 {"error":"Internal server error"} and are
     * logged.
     */
    #[Route('/api/admin/messages', methods: 'GET', name: 'admin.system.messages', middleware: [AdminAuthMiddleware::class])]
    public function messages(): Response
    {
        try {
            $chatService = new ChatService();
            $currentUser = AdminAuth::getCurrentUser();

            $request = Request::getInstance();
            $page    = (int) $request->get('page', 1, 'get');
            $limit   = min((int) $request->get('limit', 100, 'get'), 500);
            $offset  = ($page - 1) * $limit;
            $type    = (string) $request->get('type', 'all', 'get'); // all, public, private

            // Root admins can see both public and private messages.
            $includePrivate = $currentUser && $currentUser['role'] === 'root';

            // Chat mode determines whether both message types are available.
            $chatMode = $chatService->getSetting('chat_mode') ?? 'both';

            $messages   = $chatService->getAllMessages($limit, $offset, $includePrivate, $type);
            $messages   = $this->attachMixedReactions($messages);
            $total      = $chatService->getTotalMessagesCount($includePrivate, $type);
            $totalPages = ceil($total / $limit);

            return Response::json([
                'success'         => true,
                'messages'        => $messages,
                'include_private' => $includePrivate,
                'chat_mode'       => $chatMode,
                'pagination'      => [
                    'page'        => $page,
                    'limit'       => $limit,
                    'total'       => $total,
                    'total_pages' => $totalPages,
                ],
            ]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log($e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * GET /api/admin/backup/export — download a configuration backup (settings +
     * fake users, shows, promos, URL lists, bans, commands) as a JSON file.
     * Secrets in settings are redacted. Admin-only.
     */
    #[Route('/api/admin/backup/export', methods: 'GET', name: 'admin.system.backup-export', middleware: [AdminAuthMiddleware::class])]
    public function backupExport(): Response
    {
        try {
            $bundle = (new \RadioChatBox\Services\BackupService())->export();
            $bundle['generated_at'] = date('c');
            $json = json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $stamp = date('Ymd_His');
            return Response::make((string) $json)
                ->withHeader('Content-Type', 'application/json; charset=utf-8')
                ->withHeader('Content-Disposition', 'attachment; filename="radiochatbox-config-' . $stamp . '.json"');
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('AdminSystemController::backupExport failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * GET /api/admin/migrations — the applied database migrations (from the
     * framework `schemaversion` history table), newest first. Admin-only. 200
     * {success, migrations}.
     */
    #[Route('/api/admin/migrations', methods: 'GET', name: 'admin.system.migrations', middleware: [AdminAuthMiddleware::class])]
    public function migrations(): Response
    {
        try {
            $db = Database::getInstance();
            $result = $db->query(
                'SELECT "key", "when", "scope", "batch", "execution_time", "result", "error_message"
                 FROM schemaversion
                 ORDER BY "when" DESC NULLS LAST, "key" DESC
                 LIMIT 200'
            );
            $rows = $result ? $result->fetchAll() : [];

            return Response::json([
                'success'    => true,
                'migrations' => $rows,
                'count'      => count($rows),
            ]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('AdminSystemController::migrations failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * GET /api/admin/radio/probe?url= — fetch and parse one radio status URL and
     * report what the feed actually provides, for the "Check feed" button.
     *
     * Reads the URL from the request rather than the saved setting so a feed can
     * be tested before it is committed; without this, checking meant saving an
     * unverified URL first. Admin-only, and no new reach: an admin can already
     * point the station's own fetcher at any URL by saving it. Falls back to the
     * saved value when the parameter is empty, so the button still works on a
     * form the operator has not touched. 200 {success, nowPlaying}.
     */
    #[Route('/api/admin/radio/probe', methods: 'GET', name: 'admin.system.radio-probe', middleware: [AdminAuthMiddleware::class])]
    public function radioProbe(): Response
    {
        try {
            $url = trim((string) Request::getInstance()->get('url', '', 'get'));
            if ($url === '') {
                $url = trim((string) (new SettingsService())->get('radio_status_url', ''));
            }
            if ($url === '') {
                return Response::json(['error' => 'No status URL to check'], 400);
            }
            if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
                return Response::json(['error' => 'That does not look like an http(s) URL'], 400);
            }

            return Response::json([
                'success'    => true,
                'nowPlaying' => (new \RadioChatBox\Services\RadioStatusService())->probe($url),
            ]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('AdminSystemController::radioProbe failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * GET /api/admin/mails — the transactional email log (the framework's `mails`
     * table, written by Pramnos\Email\Email on every send), newest first, with a
     * per-status tally. Optional ?status= filters to one status.
     *
     * Status is the mailer's own vocabulary: 1 sent, 2 queued, 0 failed —
     * Email::send() stores `$success ? 1 : 0`, so a 0 row is a delivery that did
     * not happen. Admin-only. 200 {success, mails, counts}.
     */
    #[Route('/api/admin/mails', methods: 'GET', name: 'admin.system.mails', middleware: [AdminAuthMiddleware::class])]
    public function mails(): Response
    {
        try {
            $db = Database::getInstance();

            $limit  = min(500, max(1, (int) Request::getInstance()->get('limit', '200', 'get')));
            $status = Request::getInstance()->get('status', '', 'get');

            $qb = $db->queryBuilder()
                ->from('mails')
                // extrainfo carries the mailer's error text on a failed row
                // (Email::recordMail stores lastError there) — the one field that
                // says *why*, so the log is diagnosable without the app log.
                ->select(['id', 'status', 'tomail', 'toname', 'subject', 'date', 'module', 'extrainfo'])
                ->orderBy('date', 'desc')
                ->limit($limit);

            if ($status !== '' && $status !== null) {
                $qb->where('status', (int) $status);
            }

            $rows = $qb->getAll() ?: [];

            // Tally every status, not just the filtered one, so the UI can show
            // "3 failed" while the list is filtered to sent.
            $tally  = $db->query('SELECT status, COUNT(*) AS total FROM mails GROUP BY status');
            $counts = ['sent' => 0, 'queued' => 0, 'failed' => 0];
            foreach (($tally ? $tally->fetchAll() : []) as $row) {
                $key = match ((int) $row['status']) {
                    1 => 'sent',
                    2 => 'queued',
                    default => 'failed',
                };
                $counts[$key] += (int) $row['total'];
            }

            return Response::json([
                'success' => true,
                'mails'   => $rows,
                'counts'  => $counts,
                'count'   => count($rows),
            ]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('AdminSystemController::mails failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * GET /api/admin/user-data-export?username= — a GDPR-style data export for a
     * user: everything the app stores about them, as a downloadable JSON file.
     * Admin-only. 400 when username is missing.
     */
    #[Route('/api/admin/user-data-export', methods: 'GET', name: 'admin.system.user-data-export', middleware: [AdminAuthMiddleware::class])]
    public function userDataExport(): Response
    {
        try {
            $username = trim((string) Request::getInstance()->get('username', '', 'get'));
            if ($username === '') {
                return Response::json(['error' => 'username is required'], 400);
            }
            $data = (new \RadioChatBox\Services\UserDataExportService())->export($username);
            $data['generated_at'] = date('c');

            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $username);
            return Response::make((string) $json)
                ->withHeader('Content-Type', 'application/json; charset=utf-8')
                ->withHeader('Content-Disposition', 'attachment; filename="user-data-' . $safe . '.json"');
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('AdminSystemController::userDataExport failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * GET /api/admin/message-edits?message_id= — the edit history of a public
     * message (previous versions, newest first) plus its current text. Admin-only.
     * 200 {success, current, edits}; missing id -> 400.
     */
    #[Route('/api/admin/message-edits', methods: 'GET', name: 'admin.system.message-edits', middleware: [AdminAuthMiddleware::class])]
    public function messageEdits(): Response
    {
        try {
            $messageId = (string) Request::getInstance()->get('message_id', '', 'get');
            if (trim($messageId) === '') {
                return Response::json(['error' => 'message_id is required'], 400);
            }
            $db = Database::getInstance();

            $current = $db->queryBuilder()
                ->from('chat_messages')
                ->select(['message', 'username', 'edited_at'])
                ->where('message_id', '=', $messageId)
                ->first();

            $edits = $db->queryBuilder()
                ->from('message_edits')
                ->select(['old_message', 'edited_by', 'created_at'])
                ->where('message_id', '=', $messageId)
                ->orderBy('created_at', 'desc')
                ->getAll();

            return Response::json([
                'success' => true,
                'current' => ($current && $current->numRows > 0) ? $current->fields : null,
                'edits'   => $edits,
            ]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('AdminSystemController::messageEdits failed: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Attach reaction pills to a moderation list that mixes public messages and
     * DMs. The two live in separate tables — and the row's `message_id` is the
     * varchar id for a public message but the private_messages id cast to text
     * for a DM — so each type is resolved against its own table and the rows are
     * put back in their original positions (the list is ordered by date).
     *
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, array<string, mixed>>
     */
    private function attachMixedReactions(array $messages): array
    {
        if (empty($messages)) {
            return $messages;
        }

        $buckets = ['public' => [], 'private' => []];
        foreach ($messages as $i => $message) {
            $kind = ($message['message_type'] ?? 'public') === 'private' ? 'private' : 'public';
            $buckets[$kind][$i] = $message;
        }

        $tables  = ['public' => 'message_reactions', 'private' => 'private_message_reactions'];
        $service = new ReactionService();

        foreach ($buckets as $kind => $rows) {
            if (empty($rows)) {
                continue;
            }
            // No username: "mine" is meaningless for a moderator looking at other
            // people's conversations — the admin list shows counts only.
            $attached = $service->attachToMessages(array_values($rows), null, $tables[$kind]);
            $positions = array_keys($rows);
            foreach ($attached as $j => $row) {
                $messages[$positions[$j]] = $row;
            }
        }

        return $messages;
    }

    /**
     * GET /api/admin/messages/export?type=public — download the chat history as a
     * CSV file. Private messages are only included for root admins (same rule as
     * the listing). `type` is all|public|private; capped at 10k rows.
     */
    #[Route('/api/admin/messages/export', methods: 'GET', name: 'admin.system.messages.export', middleware: [AdminAuthMiddleware::class])]
    public function messagesExport(): Response
    {
        try {
            $chatService = new ChatService();
            $currentUser = AdminAuth::getCurrentUser();
            $type = (string) Request::getInstance()->get('type', 'all', 'get');
            $includePrivate = $currentUser && $currentUser['role'] === 'root';

            $rows = [];
            $limit = 2000;
            $offset = 0;
            $max = 10000;
            // Page through the history so a large chat doesn't need one huge query.
            do {
                $batch = $chatService->getAllMessages($limit, $offset, $includePrivate, $type);
                foreach ($batch as $m) {
                    $rows[] = [
                        $m['created_at'] ?? '',
                        $m['message_type'] ?? 'public',
                        $m['username'] ?? '',
                        $m['to_username'] ?? '',
                        $m['message'] ?? '',
                        !empty($m['is_deleted']) ? 'yes' : '',
                    ];
                }
                $offset += $limit;
            } while (count($batch) === $limit && $offset < $max);

            $csv = Csv::build(
                ['created_at', 'type', 'from', 'to', 'message', 'deleted'],
                $rows
            );
            return Csv::download($csv, 'chat-history.csv');
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log($e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * GET|POST /api/admin/photos — view uploaded photos (GET) and empty the trash
     * (POST).
     *
     * Migrated from public/api/admin/photos.php.
     *  - GET action=list (default): {success, photos, pagination:{page, limit,
     *    total, total_pages}}.
     *  - GET action=by_user (username required): {success, username, photos,
     *    count}.
     *  - POST action=empty_trash: {success, removed}.
     *  - Invalid action -> 400 {"error":"Invalid action"}.
     * Missing username -> 400 {"error":"Username is required"}. Other errors map
     * to 500 {"error":"Server error"} (logged as "Admin photos error: <msg>").
     */
    #[Route('/api/admin/photos', methods: ['GET', 'POST'], name: 'admin.system.photos', middleware: [AdminAuthMiddleware::class])]
    public function photos(): Response
    {
        try {
            $photoService = new PhotoService();
            $request      = Request::getInstance();

            if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                $action = (string) $request->get('action', 'list', 'get');

                if ($action === 'list') {
                    // All photos with pagination.
                    $page           = (int) $request->get('page', 1, 'get');
                    $limit          = min((int) $request->get('limit', 50, 'get'), 200);
                    $offset         = ($page - 1) * $limit;
                    $includeDeleted = !empty($request->get('include_deleted', '', 'get'));

                    $photos     = $photoService->getAllAttachments($limit, $offset, $includeDeleted);
                    $total      = $photoService->getTotalAttachmentsCount($includeDeleted);
                    $totalPages = ceil($total / $limit);

                    return Response::json([
                        'success'    => true,
                        'photos'     => $photos,
                        'pagination' => [
                            'page'        => $page,
                            'limit'       => $limit,
                            'total'       => $total,
                            'total_pages' => $totalPages,
                        ],
                    ]);
                }

                if ($action === 'by_user') {
                    // Photos by a specific user.
                    $username = (string) $request->get('username', '', 'get');
                    if (empty($username)) {
                        throw new InvalidArgumentException('Username is required');
                    }

                    $photos = $photoService->getAttachmentsByUser($username);

                    return Response::json([
                        'success'  => true,
                        'username' => $username,
                        'photos'   => $photos,
                        'count'    => count($photos),
                    ]);
                }

                return Response::json(['error' => 'Invalid action'], 400);
            }

            // POST — the framework Request has already decoded the JSON body into
            // $_POST (the legacy read php://input directly).
            $action = $_POST['action'] ?? '';

            if ($action === 'empty_trash') {
                // Permanently remove every soft-deleted photo (file + row).
                $removed = $photoService->emptyTrash();
                return Response::json(['success' => true, 'removed' => $removed]);
            }

            return Response::json(['error' => 'Invalid action'], 400);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Admin photos error: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * POST /api/admin/create-session — mint a short-lived admin session token for
     * SSE connections (so raw credentials never appear in URLs).
     *
     * Migrated from public/api/admin/create-session.php. Stores a 24h Redis token
     * keyed to the current admin (AdminAuth::getCurrentUser()) and returns
     * {success, session_token, expires_in}. Errors map to 500 {"error":"Failed to
     * create session token"} and are logged. (The legacy hand-set CORS headers are
     * dropped — the global CORS middleware handles them.)
     */
    #[Route('/api/admin/create-session', methods: 'POST', name: 'admin.system.create-session', middleware: [AdminAuthMiddleware::class])]
    public function createSession(): Response
    {
        try {
            // The stream token is a raw, UNPREFIXED admin_session:<token> key (read
            // back by AdminStreamController over the same shared connection).
            $redis = ConnectionManager::getInstance()->connection();

            if (!$redis) {
                throw new \Exception('Failed to get Redis connection');
            }

            // Cryptographically secure random token.
            $token = bin2hex(random_bytes(32));

            // Authenticated user (already verified by the middleware).
            $currentUser = AdminAuth::getCurrentUser();
            $username    = $currentUser['username'] ?? 'admin';
            $role        = $currentUser['role'] ?? 'administrator';

            // Store token in Redis with a 24-hour TTL.
            $expiryTime = time() + (24 * 60 * 60);
            $tokenData  = json_encode([
                'username'   => $username,
                'role'       => $role,
                'expires_at' => $expiryTime,
                'created_at' => time(),
            ]);

            $cacheKey = 'admin_session:' . $token;
            $redis->setex($cacheKey, 24 * 60 * 60, $tokenData);

            return Response::json([
                'success'       => true,
                'session_token' => $token,
                'expires_in'    => 24 * 60 * 60,
            ]);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Error creating session token: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Failed to create session token'], 500);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * POST /api/admin/track-artwork-upload — upload a manual cover/artist image
     * and attach it to a track or artist.
     *
     * Migrated from public/api/admin/track-artwork-upload.php. Multipart form:
     * type ('track_cover'|'artist_image'), id (int) and a 'file' upload (<= 8MB).
     * Stores the image via ArtworkService and updates the row. Returns {success,
     * url, thumb}. Validation failures -> 400 {"error":"<msg>"}; other errors ->
     * 500 {"error":"Internal server error"} (logged).
     */
    #[Route('/api/admin/track-artwork-upload', methods: 'POST', name: 'admin.system.track-artwork-upload', middleware: [AdminAuthMiddleware::class])]
    public function trackArtworkUpload(): Response
    {
        try {
            $type = $_POST['type'] ?? '';          // 'track_cover' | 'artist_image'
            $id   = (int) ($_POST['id'] ?? 0);

            // type must be one of the two kinds and id a positive integer — one
            // combined message regardless of which fails (legacy 400 body).
            $invalid = 'Valid type and id are required';
            if ($error = Validate::check($_POST, [
                'type' => 'required|in:track_cover,artist_image',
                'id'   => 'required|integer|min:1',
            ], [
                'type.required' => $invalid, 'type.in'      => $invalid,
                'id.required'   => $invalid, 'id.integer'   => $invalid, 'id.min' => $invalid,
            ])) {
                return $error;
            }
            if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                throw new InvalidArgumentException('No file uploaded');
            }
            if ($_FILES['file']['size'] > 8 * 1024 * 1024) {
                throw new InvalidArgumentException('Image too large (max 8MB)');
            }

            $bytes = file_get_contents($_FILES['file']['tmp_name']);
            if ($bytes === false) {
                throw new \RuntimeException('Could not read upload');
            }

            $saved = (new ArtworkService())->saveUploadedImage($bytes, 'manual');
            if (empty($saved['full'])) {
                throw new \RuntimeException('Invalid image or storage failed');
            }

            $db = Database::getInstance();
            if ($type === 'track_cover') {
                $db->queryBuilder()->from('tracks')
                    ->where('id', '=', $id)
                    ->update(['cover_file' => $saved['full']]);
            } else {
                // updated_at = NOW() expression — kept as verbatim prepared SQL.
                $db->preparedQuery(
                    'UPDATE artists SET image_file = :c, updated_at = NOW() WHERE id = :id',
                    ['c' => $saved['full'], 'id' => $id]
                );
            }

            return Response::json([
                'success' => true,
                'url'     => $saved['full'],
                'thumb'   => $saved['thumb'],
            ]);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log($e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
        // @codeCoverageIgnoreEnd
    }
}
