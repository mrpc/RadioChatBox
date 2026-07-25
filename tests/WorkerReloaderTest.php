<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use RadioChatBox\Database;
use RadioChatBox\SettingsService;
use RadioChatBox\WorkerReloader;

/**
 * Covers keeping a long-running worker current.
 *
 * A PHP daemon runs the code and the configuration it started with: settings can be
 * adopted in place, code cannot be reloaded at all and the process has to exit for a
 * supervisor to replace it. Both signals are checked here.
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
        file_put_contents($this->root . '/bot-worker.php', "<?php // worker\n");
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

    // ------------------------------------------------------------------
    // Code
    // ------------------------------------------------------------------

    public function testAnUnchangedTreeKeepsItsFingerprint(): void
    {
        $reloader = new WorkerReloader($this->root);
        $first = $reloader->codeFingerprint();

        $this->assertSame($first, $reloader->codeFingerprint());
        $this->assertFalse($reloader->codeChanged(), 'the first check establishes the baseline');
        $this->assertFalse($reloader->codeChanged());
    }

    public function testAnEditedFileIsNoticed(): void
    {
        $reloader = new WorkerReloader($this->root);
        $reloader->baseline();

        // A deploy changes size and mtime; either is enough.
        file_put_contents($this->root . '/src/Thing.php', "<?php // one, edited\n");
        touch($this->root . '/src/Thing.php', time() + 5);

        $this->assertTrue($reloader->codeChanged());
    }

    public function testANewFileIsNoticed(): void
    {
        $reloader = new WorkerReloader($this->root);
        $reloader->baseline();

        file_put_contents($this->root . '/src/Another.php', "<?php // two\n");

        $this->assertTrue($reloader->codeChanged(), 'a new class is a code change too');
    }

    public function testTheWorkerScriptItselfIsWatched(): void
    {
        $reloader = new WorkerReloader($this->root);
        $reloader->baseline();

        file_put_contents($this->root . '/bot-worker.php', "<?php // worker, edited\n");
        touch($this->root . '/bot-worker.php', time() + 5);

        $this->assertTrue($reloader->codeChanged());
    }

    public function testFilesOutsideTheWatchedSetAreIgnored(): void
    {
        $reloader = new WorkerReloader($this->root);
        $reloader->baseline();

        // Logs, caches and uploads change constantly; restarting for those would mean
        // never staying up.
        file_put_contents($this->root . '/src/notes.txt', 'not code');
        mkdir($this->root . '/src/nested');
        file_put_contents($this->root . '/src/nested/Deep.php', "<?php\n");

        $this->assertFalse($reloader->codeChanged());

        unlink($this->root . '/src/nested/Deep.php');
        rmdir($this->root . '/src/nested');
        unlink($this->root . '/src/notes.txt');
    }

    // ------------------------------------------------------------------
    // Settings
    // ------------------------------------------------------------------

    public function testASavedSettingIsNoticedOnce(): void
    {
        $settings = new SettingsService();
        $reloader = new WorkerReloader($this->root);
        $reloader->baseline();

        $this->assertFalse($reloader->settingsChanged(), 'nothing has changed yet');

        $settings->invalidateCache();

        $this->assertTrue($reloader->settingsChanged());
        // Regression: the worker used to call invalidateCache() on detecting a change,
        // which bumped the stamp again - so it saw its own write and reported a change
        // on every single tick, forever.
        $this->assertFalse($reloader->settingsChanged(), 'a consumed change must not repeat');
    }

    public function testEachSaveIsNoticedSeparately(): void
    {
        $settings = new SettingsService();
        $reloader = new WorkerReloader($this->root);
        $reloader->baseline();

        $settings->invalidateCache();
        $this->assertTrue($reloader->settingsChanged());

        usleep(1000);
        $settings->invalidateCache();
        $this->assertTrue($reloader->settingsChanged());
    }

    public function testTheVersionFallsBackToTheDatabaseWithoutTheCachedStamp(): void
    {
        $redis = Database::getRedis();
        $redis->del(Database::getRedisPrefix() . SettingsService::VERSION_KEY);

        $version = (new WorkerReloader($this->root))->settingsVersion();

        // A fresh Redis, or a setting edited straight in SQL, must still be detectable.
        $this->assertNotSame('', $version);
        $this->assertNotSame('unknown', $version);
    }
}
