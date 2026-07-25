<?php

namespace RadioChatBox;

/**
 * Keeps the background daemons running, so exactly one long-lived process needs
 * supervising from outside.
 *
 * The shape follows the house pattern: systemd (or whatever) supervises **this**
 * process, and this process reconciles what should be running against what is. Every
 * cycle it starts what is missing, asks a stalled worker to restart, and on a new
 * deployment asks everything to restart on the new code.
 *
 * Why a supervisor rather than each worker looking after itself: PHP cannot reload
 * code into a running process, so a deploy means replacing processes - and something
 * has to do the replacing. A worker that exits on its own leaves nothing behind, which
 * is exactly the hole this fills.
 *
 * Stopping is always a *request*, never a kill: a `.stop` file next to the worker's
 * lock, which the worker notices between jobs and exits cleanly, finishing what it was
 * doing. The next cycle finds the slot empty and fills it.
 *
 * A deployment is detected from `.git/HEAD` rather than from file timestamps: a commit
 * hash changes once per deploy, while mtimes change on every editor save.
 *
 * Everything is scoped by installation (Installation::id() - the directory, not the
 * database), because several copies of the code run on one server: the supervisor's own
 * lock, the workers' locks and the daemon ids all carry it, so two supervisors run side
 * by side without ever managing each other's processes.
 */
class DaemonSupervisor
{
    /** How often a cycle runs, unless told otherwise. */
    public const DEFAULT_INTERVAL = 10;

    /** No heartbeat for this long means the worker is alive but stuck. */
    public const HEARTBEAT_STALE_SECONDS = 300;

    /** How often the deployed commit is checked. */
    public const DEPLOY_CHECK_SECONDS = 60;

    /**
     * The daemons this supervises. One entry per process that should be running.
     *
     * @var array<string,array{script:string,args:list<string>,lock_name:string,description:string}>
     */
    public const DAEMONS = [
        'bot_worker' => [
            'script' => 'worker.php',
            'args' => ['run', '--schedule'],
            // Resolved through WorkerLock, which owns the naming (it includes the
            // database, so two installations on one host do not fight).
            'lock_name' => 'worker',
            'description' => 'Bot replies and the periodic tasks',
        ],
    ];

    private string $root;

    /** @var array<string,string> Resolved lock paths by daemon id */
    private array $lockPaths = [];

    /** @var array<string,array<string,mixed>> */
    private array $daemons;

    /** @var callable(string):int Spawns a command, returns the pid (0 when unknown) */
    private $spawner;

    /** @var callable(int):bool */
    private $isAlive;

    /** @var callable():int */
    private $clock;

    private string $lastDeployHash = '';
    private int $lastDeployCheck = 0;
    private ?WorkerLock $ownLock = null;

    /**
     * @param array<string,array<string,mixed>>|null $daemons Overrides DAEMONS, for tests
     * @param callable|null                          $spawner Replaces the real spawn
     * @param callable|null                          $isAlive Replaces the pid check
     */
    public function __construct(
        ?string $root = null,
        ?array $daemons = null,
        ?callable $spawner = null,
        ?callable $isAlive = null,
        ?callable $clock = null
    ) {
        $this->root = rtrim($root ?? dirname(__DIR__), '/');
        $this->daemons = $daemons ?? self::DAEMONS;
        $this->clock = $clock ?? static fn (): int => time();

        $this->spawner = $spawner ?? function (string $command): int {
            // Detached, so the child outlives this cycle and is not killed with us.
            $pid = (int) trim((string) shell_exec($command . ' > /dev/null 2>&1 & echo $!'));

            return $pid;
        };

        $this->isAlive = $isAlive ?? static function (int $pid): bool {
            if ($pid <= 0) {
                return false;
            }
            if (function_exists('posix_kill')) {
                return @posix_kill($pid, 0);
            }

            return is_dir('/proc/' . $pid);
        };
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function daemons(): array
    {
        return $this->daemons;
    }

    /**
     * Which installation this supervisor belongs to. Part of every name it uses.
     */
    public function instance(): string
    {
        return Installation::id();
    }

    /**
     * Identity and directory, because the identity alone does not tell you where to look.
     */
    public function label(): string
    {
        return Installation::label();
    }

    /**
     * The supervisor's own single-instance lock.
     *
     * Two supervisors for the same installation would each see an empty slot and start
     * a worker, so the doubling this exists to prevent would come from the thing meant
     * to prevent it. Scoped by instance, so a second installation on the same server is
     * unaffected.
     */
    public function ownLock(): WorkerLock
    {
        return $this->ownLock ??= new WorkerLock('daemon-supervisor');
    }

    /**
     * Take the supervisor lock, or explain who already holds it.
     */
    public function claim(?string &$heldBy = null): bool
    {
        $lock = $this->ownLock();

        if ($lock->acquire($takenOverFrom)) {
            return true;
        }

        $state = $lock->readState();
        $heldBy = sprintf(
            'pid %s on %s, heartbeat %s',
            $state['pid'] ?? '?',
            $state['host'] ?? '?',
            $lock->heartbeatAge($state) === null ? 'unknown' : $lock->heartbeatAge($state) . 's ago'
        );

        return false;
    }

    public function lockPath(string $id): string
    {
        if (isset($this->lockPaths[$id])) {
            return $this->lockPaths[$id];
        }

        $daemon = $this->daemons[$id];

        // An explicit path is only for tests; production goes through WorkerLock so
        // there is one naming rule, not two that can drift apart.
        return $this->lockPaths[$id] = isset($daemon['lock_path'])
            ? (string) $daemon['lock_path']
            : WorkerLock::defaultPath((string) ($daemon['lock_name'] ?? $id));
    }

    /**
     * One reconciliation cycle: bring reality in line with what should be running.
     *
     * @return list<array{daemon:string,action:string,detail:string}> What it did, for logging
     */
    public function reconcile(bool $dryRun = false): array
    {
        $actions = [];

        foreach (array_keys($this->daemons) as $id) {
            $actions[] = $this->reconcileOne($id, $dryRun);
        }

        return $actions;
    }

    /**
     * @return array{daemon:string,action:string,detail:string}
     */
    private function reconcileOne(string $id, bool $dryRun): array
    {
        $lock = $this->lockPath($id);
        $state = $this->readLock($lock);
        $pid = (int) ($state['pid'] ?? 0);
        $alive = ($this->isAlive)($pid);
        $now = ($this->clock)();

        // A stop was requested: wait for it to go, then fill the slot again.
        if ($this->stopRequested($lock)) {
            if ($alive) {
                return $this->action($id, 'waiting', 'stop requested, waiting for pid ' . $pid . ' to finish');
            }

            if (!$dryRun) {
                $this->clearStopRequest($lock);
            }

            return $this->start($id, $dryRun, 'restarting after a requested stop');
        }

        if (!$alive) {
            return $this->start($id, $dryRun, $pid > 0 ? 'pid ' . $pid . ' is gone' : 'not running');
        }

        // Alive but no longer reporting progress: a plain pid check would call this
        // healthy, which is how a wedged worker goes unnoticed for hours.
        $heartbeat = (int) ($state['heartbeat_at'] ?? 0);
        if ($heartbeat > 0 && ($now - $heartbeat) > self::HEARTBEAT_STALE_SECONDS) {
            if (!$dryRun) {
                $this->requestStop($lock);
            }

            return $this->action(
                $id,
                'stale',
                sprintf('no heartbeat for %ds, asked pid %d to restart', $now - $heartbeat, $pid)
            );
        }

        return $this->action($id, 'healthy', sprintf('pid %d, heartbeat %ds ago', $pid, max(0, $now - $heartbeat)));
    }

    /**
     * @return array{daemon:string,action:string,detail:string}
     */
    private function start(string $id, bool $dryRun, string $why): array
    {
        $daemon = $this->daemons[$id];
        $command = sprintf(
            '%s %s %s',
            escapeshellcmd(PHP_BINARY),
            escapeshellarg($this->root . '/' . (string) $daemon['script']),
            implode(' ', array_map('escapeshellarg', (array) $daemon['args']))
        );

        if ($dryRun) {
            return $this->action($id, 'would-start', $why . ': ' . $command);
        }

        $pid = ($this->spawner)($command);

        return $this->action($id, 'started', sprintf('%s (pid %s)', $why, $pid > 0 ? $pid : 'unknown'));
    }

    // ------------------------------------------------------------------
    // Stop requests
    // ------------------------------------------------------------------

    /**
     * Ask a daemon to stop after what it is doing. Never a kill: a job cut in half is
     * worse than a job finished late.
     */
    public function requestStop(string $lockPath): void
    {
        @file_put_contents($lockPath . '.stop', (string) ($this->clock)());
    }

    public function requestStopAll(): void
    {
        foreach (array_keys($this->daemons) as $id) {
            $this->requestStop($this->lockPath($id));
        }
    }

    public function stopRequested(string $lockPath): bool
    {
        return file_exists($lockPath . '.stop');
    }

    public function clearStopRequest(string $lockPath): void
    {
        @unlink($lockPath . '.stop');
    }

    // ------------------------------------------------------------------
    // Deployments
    // ------------------------------------------------------------------

    /**
     * The deployed commit, read straight from .git/HEAD - no external process, and no
     * dependency on git being installed.
     *
     * Empty when this is not a checkout (a release built by copying files), in which
     * case deployment detection is simply skipped rather than guessed at.
     */
    public function deployHash(): string
    {
        $head = $this->root . '/.git/HEAD';

        if (!is_file($head)) {
            return '';
        }

        $contents = trim((string) @file_get_contents($head));

        if (strlen($contents) === 40 && ctype_xdigit($contents)) {
            return $contents; // detached HEAD
        }

        if (str_starts_with($contents, 'ref: ')) {
            $ref = $this->root . '/.git/' . substr($contents, 5);

            if (is_file($ref)) {
                return trim((string) @file_get_contents($ref));
            }

            // Packed refs, after `git gc`.
            $packed = $this->root . '/.git/packed-refs';
            if (is_file($packed)) {
                $needle = ' ' . substr($contents, 5);
                foreach (explode("\n", (string) @file_get_contents($packed)) as $line) {
                    if (str_ends_with(trim($line), $needle)) {
                        return substr(trim($line), 0, 40);
                    }
                }
            }
        }

        return '';
    }

    /**
     * Whether a new commit has been deployed since the last check. Rate-limited to
     * DEPLOY_CHECK_SECONDS, and never true on the first call - that one only records
     * what is running now.
     */
    public function deployChanged(): bool
    {
        $now = ($this->clock)();

        if ($this->lastDeployCheck > 0 && ($now - $this->lastDeployCheck) < self::DEPLOY_CHECK_SECONDS) {
            return false;
        }

        $this->lastDeployCheck = $now;
        $hash = $this->deployHash();

        if ($hash === '') {
            return false;
        }

        if ($this->lastDeployHash === '') {
            $this->lastDeployHash = $hash;

            return false;
        }

        if ($hash === $this->lastDeployHash) {
            return false;
        }

        $this->lastDeployHash = $hash;

        return true;
    }

    // ------------------------------------------------------------------
    // Status
    // ------------------------------------------------------------------

    /**
     * What each daemon is doing, for the CLI and the admin panel.
     *
     * @return list<array<string,mixed>>
     */
    public function status(): array
    {
        $now = ($this->clock)();
        $rows = [];

        foreach ($this->daemons as $id => $daemon) {
            $lock = $this->lockPath($id);
            $state = $this->readLock($lock);
            $pid = (int) ($state['pid'] ?? 0);
            $heartbeat = (int) ($state['heartbeat_at'] ?? 0);
            $alive = ($this->isAlive)($pid);

            $rows[] = [
                'instance' => $this->instance(),
                'root' => Installation::root(),
                'daemon' => $id,
                'description' => $daemon['description'] ?? '',
                'running' => $alive,
                'pid' => $pid > 0 ? $pid : null,
                'heartbeat_age_seconds' => $heartbeat > 0 ? max(0, $now - $heartbeat) : null,
                'stale' => $alive && $heartbeat > 0 && ($now - $heartbeat) > self::HEARTBEAT_STALE_SECONDS,
                'stop_requested' => $this->stopRequested($lock),
                'started_at' => $state['started_at'] ?? null,
                'jobs_processed' => isset($state['jobs_processed']) ? (int) $state['jobs_processed'] : null,
                'lock' => $lock,
            ];
        }

        return $rows;
    }

    /**
     * @return array<string,mixed>
     */
    private function readLock(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) @file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array{daemon:string,action:string,detail:string}
     */
    private function action(string $id, string $action, string $detail): array
    {
        return ['daemon' => $id, 'action' => $action, 'detail' => $detail];
    }
}
