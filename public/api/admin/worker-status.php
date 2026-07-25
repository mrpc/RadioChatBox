<?php

/**
 * Health of the background worker, for the dashboard.
 *
 * GET -> { running, wedged, pid, uptime_seconds, heartbeat_age_seconds, jobs_processed,
 *          queue: { size, next_in_seconds }, schedule: [ per-task last run ] }
 *
 * Until now this only existed as `php worker.php status` on the server, which
 * means nobody notices a stopped worker until replies stop arriving - and with the
 * periodic tasks moved into the worker, a stopped worker also means no stats, no
 * cleanup and no track history.
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use RadioChatBox\AdminAuth;
use RadioChatBox\CorsHandler;
use RadioChatBox\DaemonSupervisor;
use RadioChatBox\Installation;
use RadioChatBox\JobQueue;
use RadioChatBox\Scheduler;
use RadioChatBox\SettingsService;
use RadioChatBox\WorkerLock;

header('Content-Type: application/json');

CorsHandler::handle();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if (!AdminAuth::verify()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $settings = new SettingsService();
    $lock = new WorkerLock();
    $queue = new JobQueue();

    $state = $lock->readState();
    $heartbeatAge = $lock->heartbeatAge($state);

    // "Running" is the lock being held by a live process; "wedged" is a process that
    // is alive but has stopped making progress, which looks identical from outside
    // until you compare the heartbeat.
    $running = $state !== null && $lock->isHeldByAnother();
    $wedged = $lock->holderIsWedged();

    $tasks = [];
    $scheduler = new Scheduler($settings);
    $scheduleState = $scheduler->state();
    $due = $scheduler->dueTasks();

    foreach ($scheduler->tasks() as $task => $meta) {
        $row = $scheduleState[$task] ?? null;

        $tasks[] = [
            'task' => $task,
            'description' => $meta['description'],
            'every_seconds' => (int) $meta['every'],
            'due_now' => in_array($task, $due, true),
            'last_run_at' => $row['last_run_at'] ?? null,
            'last_status' => $row['last_status'] ?? null,
            'last_duration_ms' => isset($row['last_duration_ms']) ? (int) $row['last_duration_ms'] : null,
            'last_error' => $row['last_error'] ?? null,
            'runs' => (int) ($row['runs'] ?? 0),
            'failures' => (int) ($row['failures'] ?? 0),
        ];
    }

    // The supervisor is what restarts a dead worker, so "worker stopped" and "nothing
    // is watching it" are different problems and the panel has to tell them apart.
    $supervisor = new DaemonSupervisor();
    $supervisorState = $supervisor->ownLock()->readState();
    $supervisorAge = $supervisor->ownLock()->heartbeatAge($supervisorState);

    echo json_encode([
        'success' => true,
        // Several installations can share a server; say which directory this one is.
        'instance' => Installation::id(),
        'root' => Installation::root(),
        'supervisor' => [
            'running' => $supervisorState !== null && $supervisor->ownLock()->isHeldByAnother(),
            'pid' => $supervisorState['pid'] ?? null,
            'heartbeat_age_seconds' => $supervisorAge,
        ],
        'daemons' => $supervisor->status(),
        'running' => $running,
        'wedged' => $wedged,
        'pid' => $state['pid'] ?? null,
        'host' => $state['host'] ?? null,
        'started_at' => $state['started_at'] ?? null,
        'uptime_seconds' => isset($state['started_at']) ? max(0, time() - (int) $state['started_at']) : null,
        'heartbeat_age_seconds' => $heartbeatAge,
        'stale_after_seconds' => $lock->getStaleAfter(),
        'jobs_processed' => isset($state['jobs_processed']) ? (int) $state['jobs_processed'] : null,
        'current_job' => $state['current_job'] ?? null,
        'lock_path' => $lock->getPath(),
        'queue' => [
            'size' => $queue->size(),
            'next_in_seconds' => $queue->secondsUntilNext(),
        ],
        // A stopped worker means no bot replies AND no scheduled maintenance.
        'schedule' => $tasks,
        'schedule_failures' => count(array_filter($tasks, static fn (array $t): bool => $t['last_status'] === 'failed')),
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to read worker status']);
    error_log('worker-status error: ' . $e->getMessage());
}
