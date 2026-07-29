<?php

namespace RadioChatBox\Tests\Support;

use Pramnos\Logs\Logger;

/**
 * Test helper for asserting on RadioChatBox's application log.
 *
 * Since the #15 migration the app logs through the native framework
 * {@see Logger} on the "radiochatbox" channel (no more error_log()), and under
 * test the Logger runs file-only (see phpunit.xml) so lines never surface as
 * process output. Tests that need to assert a log side-effect therefore point
 * the Logger at an in-memory stream and read it back deterministically —
 * independent of any SAPI error_log routing — via this trait.
 *
 * Two usage styles:
 *  - Manual: {@see captureAppLog()} before the code under test, then
 *    {@see capturedAppLog()} to read it.
 *  - Declarative: {@see expectAppLogMatches()} at the top of a test (a drop-in
 *    for expectOutputRegex on the app log), verified by
 *    {@see verifyAndStopAppLogCapture()} — call that from the test's tearDown.
 *
 * Either way {@see stopCapturingAppLog()} restores the file-only mode and must
 * run in tearDown so the capture never leaks into another test in the process.
 */
trait CapturesAppLog
{
    /** @var resource|null */
    private $appLogStream = null;

    private ?string $expectedAppLogRegex = null;

    protected function captureAppLog(): void
    {
        $this->appLogStream = fopen('php://memory', 'r+');
        Logger::setOutputMode(Logger::OUTPUT_STREAM);
        Logger::setStreamTarget($this->appLogStream);
    }

    /**
     * Declare that the code under test must log a line matching $regex on the
     * app channel, and start capturing. Verified in tearDown by
     * {@see verifyAndStopAppLogCapture()}.
     */
    protected function expectAppLogMatches(string $regex): void
    {
        $this->expectedAppLogRegex = $regex;
        $this->captureAppLog();
    }

    /**
     * tearDown hook: assert the expectation set by {@see expectAppLogMatches()}
     * (if any), then stop capturing. Safe to call unconditionally.
     */
    protected function verifyAndStopAppLogCapture(): void
    {
        if ($this->expectedAppLogRegex !== null) {
            $regex = $this->expectedAppLogRegex;
            $this->expectedAppLogRegex = null;
            $this->assertMatchesRegularExpression(
                $regex,
                $this->capturedAppLog(),
                'expected an app-log line matching ' . $regex
            );
        }
        $this->stopCapturingAppLog();
    }

    protected function capturedAppLog(): string
    {
        if (!is_resource($this->appLogStream)) {
            return '';
        }
        rewind($this->appLogStream);
        return (string) stream_get_contents($this->appLogStream);
    }

    protected function stopCapturingAppLog(): void
    {
        Logger::setStreamTarget(null);
        // Restore the file-only mode the test bootstrap runs in, so the capture
        // never leaks into other tests sharing this process.
        Logger::setOutputMode(Logger::OUTPUT_FILE);
        $this->appLogStream = null;
    }
}
