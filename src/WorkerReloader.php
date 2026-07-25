<?php

namespace RadioChatBox;

/**
 * Keeps a long-running worker current, because a PHP daemon otherwise runs the code
 * and the configuration it started with.
 *
 * Two different problems with two different answers:
 *
 * - **Settings** can be picked up in place. They live in the database behind a Redis
 *   cache, but the objects built from them (the LLM client and its key, model,
 *   temperature and budget) are snapshots taken at construction. So the worker
 *   watches a version stamp and rebuilds those objects when it moves, without
 *   dropping the lock or losing queued work.
 * - **Code** cannot be reloaded into a running PHP process at all, so the reload has
 *   to be a new process. Under a supervisor, exiting cleanly between jobs is enough
 *   (`Restart=always`); with nothing supervising it, exiting would just stop the
 *   worker, so it starts its own replacement instead. Nothing is lost either way:
 *   jobs live in Redis and the lock is released on the way out.
 */
class WorkerReloader
{
    /**
     * Files whose modification means the running process is out of date. Paths are
     * relative to the project root.
     */
    public const WATCHED = ['src', 'worker.php', 'composer.lock'];

    private string $root;
    private ?\Redis $redis = null;
    private string $prefix = '';

    private ?string $codeFingerprint = null;
    private ?string $settingsVersion = null;

    public function __construct(?string $root = null)
    {
        $this->root = rtrim($root ?? dirname(__DIR__), '/');

        try {
            $this->redis = Database::getRedis();
            $this->prefix = Database::getRedisPrefix();
        } catch (\Throwable) {
            // Without Redis the settings check falls back to the database.
            $this->redis = null;
        }
    }

    // ------------------------------------------------------------------
    // Code
    // ------------------------------------------------------------------

    /**
     * A fingerprint of the code the process is running: file names, sizes and
     * modification times of everything watched.
     *
     * Content hashing would be more precise and much more expensive on every tick;
     * size plus mtime changes on any edit a deploy makes.
     */
    public function codeFingerprint(): string
    {
        $parts = [];

        foreach (self::WATCHED as $relative) {
            $path = $this->root . '/' . $relative;

            if (is_dir($path)) {
                $files = glob($path . '/*.php') ?: [];
                sort($files);
                foreach ($files as $file) {
                    $parts[] = basename($file) . ':' . filesize($file) . ':' . filemtime($file);
                }
                continue;
            }

            if (is_file($path)) {
                $parts[] = $relative . ':' . filesize($path) . ':' . filemtime($path);
            }
        }

        return md5(implode('|', $parts));
    }

    /**
     * Remember the current code as the baseline. Call once at startup.
     */
    public function baseline(): void
    {
        $this->codeFingerprint = $this->codeFingerprint();
        $this->settingsVersion = $this->settingsVersion();
    }

    /**
     * Whether the code on disk has changed since the baseline. The caller should
     * finish what it is doing, release its lock and exit; the supervisor brings it
     * back on the new code.
     */
    public function codeChanged(): bool
    {
        if ($this->codeFingerprint === null) {
            $this->baseline();

            return false;
        }

        return $this->codeFingerprint() !== $this->codeFingerprint;
    }

    /**
     * Whether something will restart this process if it exits.
     *
     * It matters because "exit and let the supervisor restart me" is only a reload
     * when a supervisor exists. Run by hand, or in a container with no restart
     * policy, exiting would just stop the worker - so the caller respawns itself
     * instead.
     *
     * systemd sets INVOCATION_ID for every unit it starts, and NOTIFY_SOCKET under
     * Type=notify; supervisord sets SUPERVISOR_ENABLED. Docker's restart policy is
     * invisible from inside the container, hence the explicit override.
     *
     * @param array<string,string>|null $env Defaults to the real environment
     */
    public static function isSupervised(?array $env = null): bool
    {
        // Not array_map('strval', ...): $_SERVER holds arrays too (argv).
        $env ??= $_SERVER;

        foreach (['INVOCATION_ID', 'NOTIFY_SOCKET', 'SUPERVISOR_ENABLED', 'WORKER_SUPERVISED'] as $marker) {
            $value = $env[$marker] ?? getenv($marker);

            if (is_string($value) && trim($value) !== '') {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------
    // Settings
    // ------------------------------------------------------------------

    /**
     * A stamp that moves whenever the settings are saved.
     *
     * Written by SettingsService::invalidateCache(), which every admin save goes
     * through. When it is missing (a fresh Redis, or a direct database edit) the
     * table's own last-modified time is used instead, so a change is never missed.
     */
    public function settingsVersion(): string
    {
        if ($this->redis !== null) {
            try {
                $stamp = $this->redis->get($this->prefix . SettingsService::VERSION_KEY);
                if (is_string($stamp) && $stamp !== '') {
                    return $stamp;
                }
            } catch (\Throwable) {
                // Fall through to the database.
            }
        }

        try {
            $stmt = Database::getPDO()->query('SELECT MAX(updated_at) FROM settings');
            $max = $stmt === false ? null : $stmt->fetchColumn();

            return (string) ($max ?: 'none');
        } catch (\Throwable) {
            return 'unknown';
        }
    }

    /**
     * Whether the settings have been saved since the baseline. Consumes the change:
     * the caller rebuilds whatever it derived from settings, and the next call
     * reports false again.
     */
    public function settingsChanged(): bool
    {
        $current = $this->settingsVersion();

        if ($this->settingsVersion === null) {
            $this->settingsVersion = $current;

            return false;
        }

        if ($current === $this->settingsVersion) {
            return false;
        }

        $this->settingsVersion = $current;

        return true;
    }
}
