<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use Pramnos\Console\WorkerReloader;
use RadioChatBox\Installation;
use RadioChatBox\Services\SettingsService;

/**
 * Covers the application side of keeping a long-running worker current, now that
 * the WorkerReloader primitive lives in the framework.
 *
 * The framework class fingerprints code and detects a supervisor; this suite
 * covers what RadioChatBox supplies to it — the per-installation lock path, the
 * settings-version stamp resolver (Redis version key, falling back to the DB),
 * and that a reloader wired the way worker.php wires it notices a saved setting
 * and watches worker.php itself.
 */
class WorkerReloaderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        // A throwaway project tree, so touching files cannot disturb the real one.
        $this->root = sys_get_temp_dir() . '/reloader_' . bin2hex(random_bytes(4));
        mkdir($this->root . '/src', 0777, true);
        file_put_contents($this->root . '/src/Thing.php', "<?php // one\n");
        file_put_contents($this->root . '/worker.php', "<?php // worker\n");
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/src/*') ?: [] as $file) {
            unlink($file);
        }
        foreach (glob($this->root . '/*.php') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->root . '/src');
        @rmdir($this->root);
    }

    /** The RadioChatBox watched set, as worker.php wires it. */
    private function reloader(): WorkerReloader
    {
        return new WorkerReloader(
            $this->root,
            ['src', 'worker.php', 'composer.lock'],
            fn (): string => (new SettingsService())->versionStamp()
        );
    }

    // ------------------------------------------------------------------
    // Lock path (single source of truth: worker + dashboard must agree)
    // ------------------------------------------------------------------

    /**
     * The lock path is scoped by installation id and named for the worker, so two
     * checkouts sharing a database (or the temp fallback) never share a lock.
     */
    public function testLockPathIsInstallationScoped(): void
    {
        $path = Installation::lockPath('worker');

        $this->assertSame('worker-' . Installation::id() . '.lock', basename($path));
        $this->assertStringEndsWith('.lock', $path);
    }

    /**
     * lockPath() defaults to the 'worker' name — the same file the dashboard's
     * `new WorkerLock('worker', Installation::lockPath())` health check reads.
     */
    public function testLockPathDefaultsToTheWorkerName(): void
    {
        $this->assertSame(Installation::lockPath('worker'), Installation::lockPath());
    }

    // ------------------------------------------------------------------
    // Code
    // ------------------------------------------------------------------

    /**
     * worker.php is in the watched set, so editing the worker script itself is a
     * code change that triggers a reload.
     */
    public function testTheWorkerScriptItselfIsWatched(): void
    {
        $reloader = $this->reloader();
        $reloader->baseline();

        file_put_contents($this->root . '/worker.php', "<?php // worker, edited\n");
        touch($this->root . '/worker.php', time() + 5);

        $this->assertTrue($reloader->codeChanged());
    }

    /**
     * Files outside the watched set (logs, notes, uploads) do not trigger a reload.
     */
    public function testFilesOutsideTheWatchedSetAreIgnored(): void
    {
        $reloader = $this->reloader();
        $reloader->baseline();

        file_put_contents($this->root . '/src/notes.txt', 'not code');

        $this->assertFalse($reloader->codeChanged());

        unlink($this->root . '/src/notes.txt');
    }

    // ------------------------------------------------------------------
    // Settings-version stamp
    // ------------------------------------------------------------------

    /**
     * versionStamp() returns the Redis version key written by invalidateCache().
     */
    public function testVersionStampComesFromTheCachedStamp(): void
    {
        $settings = new SettingsService();
        $settings->invalidateCache();

        $stamp = $settings->versionStamp();
        $this->assertNotSame('', $stamp);
        $this->assertNotSame('unknown', $stamp);
        // A second read without a save returns the same stamp.
        $this->assertSame($stamp, $settings->versionStamp());
    }

    /**
     * Without the cached stamp (a fresh Redis, or a setting edited straight in
     * SQL), versionStamp() falls back to the settings table's last-modified time.
     */
    public function testVersionStampFallsBackToTheDatabase(): void
    {
        $cm = \Pramnos\Redis\ConnectionManager::getInstance();
        $cm->connection()->del($cm->prefix() . SettingsService::VERSION_KEY);

        $version = (new SettingsService())->versionStamp();

        $this->assertNotSame('', $version);
        $this->assertNotSame('unknown', $version);
    }

    /**
     * A reloader wired with the settings resolver notices a saved setting exactly
     * once: the change is consumed, so it does not repeat on every tick.
     */
    public function testASavedSettingIsNoticedOnce(): void
    {
        $reloader = $this->reloader();
        $reloader->baseline();

        $this->assertFalse($reloader->settingsChanged(), 'nothing has changed yet');

        (new SettingsService())->invalidateCache();

        $this->assertTrue($reloader->settingsChanged());
        $this->assertFalse($reloader->settingsChanged(), 'a consumed change must not repeat');
    }
}
