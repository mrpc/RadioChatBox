<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Guards the shared-hosting-portable HTTP entry point.
 *
 * public/index.php must stay a THIN front controller (define paths, autoload,
 * hand off to the out-of-web-root kernel bootstrap/http.php) — no routing or
 * business logic in the web root — and the SPA shell (public/spa.php, alongside
 * its assets) must render with working asset cache-busting.
 */
class FrontControllerTest extends TestCase
{
    /**
     * public/index.php is thin: it defines ROOT + PUBLIC_PATH, autoloads, and
     * delegates to bootstrap/http.php — and does NOT itself build the Router or
     * dispatch (that logic lives outside the web root).
     */
    public function testFrontControllerIsThinAndDelegatesOutOfWebRoot(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../public/index.php');

        $this->assertStringContainsString("define('ROOT'", $src);
        $this->assertStringContainsString("define('PUBLIC_PATH'", $src);
        $this->assertStringContainsString("require ROOT . '/bootstrap/http.php'", $src);

        // No request-handling logic in the web-root entry point.
        $this->assertStringNotContainsString('new Router', $src);
        $this->assertStringNotContainsString('->dispatch(', $src);
        $this->assertStringNotContainsString('loadFromDirectory', $src);
    }

    /**
     * The kernel that actually handles requests lives OUTSIDE public/, so it can
     * never be fetched directly over HTTP.
     */
    public function testKernelLivesOutsideWebRoot(): void
    {
        $this->assertFileExists(__DIR__ . '/../bootstrap/http.php');
        $this->assertFileDoesNotExist(__DIR__ . '/../public/_dispatch.php');
        $this->assertFileExists(__DIR__ . '/../public/.htaccess');
    }

    /**
     * The SPA shell renders from public/spa.php with cache-busted asset URLs
     * derived from the real files under PUBLIC_PATH (the only dynamic bit of the
     * otherwise-static page).
     */
    public function testSpaTemplateRendersWithCacheBusting(): void
    {
        if (!defined('PUBLIC_PATH')) {
            define('PUBLIC_PATH', __DIR__ . '/../public');
        }

        ob_start();
        require __DIR__ . '/../public/spa.php';
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('<title id="page-title">RadioChatBox', $html);
        $this->assertStringContainsString('id="app-container"', $html);
        // filemtime cache-busting resolved to a numeric version, not a literal call.
        $this->assertMatchesRegularExpression('#css/style\.css\?v=\d+#', $html);
        $this->assertStringNotContainsString('<?php', $html);
    }
}
