<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use Pramnos\Logs\Logger;

/**
 * Pins RadioChatBox's logging contract after the #15 retirement of the
 * RadioChatBox\Log facade: the app now logs directly through the native
 * framework {@see Logger} on the "radiochatbox" channel (Logger::log($m,
 * 'radiochatbox')), and the bootstrap runs it in OUTPUT_BOTH so lines reach
 * both the LogViewer-readable channel file and STDERR (docker logs).
 */
class LoggingTest extends TestCase
{
    protected function tearDown(): void
    {
        Logger::setStreamTarget(null);
        // Restore the mode the test bootstrap configured (phpunit.xml pins "file").
        Logger::setOutputMode(Logger::OUTPUT_FILE);
    }

    /**
     * A log line written the way every app call site now writes it — the native
     * Logger::log() with the "radiochatbox" channel — is emitted to the Logger's
     * stream target. This is the shape the ~220 former Log::write() sites compile
     * to; if the channel argument were dropped it would land in the framework's
     * default channel instead.
     */
    public function testAppLogsRouteThroughTheRadiochatboxChannel(): void
    {
        $stream = fopen('php://memory', 'r+');
        Logger::setOutputMode(Logger::OUTPUT_STREAM);
        Logger::setStreamTarget($stream);

        $marker = 'log-marker-' . substr(md5(__METHOD__), 0, 8);
        Logger::log($marker, 'radiochatbox');

        rewind($stream);
        $this->assertStringContainsString($marker, (string) stream_get_contents($stream));
    }

    /**
     * OUTPUT_BOTH fans a single line out to BOTH destinations — this is the
     * production bootstrap default, so a log line is visible in `docker logs`
     * (stream) while still being recorded to the channel file for the LogViewer.
     */
    public function testOutputBothWritesToTheStream(): void
    {
        $stream = fopen('php://memory', 'r+');
        Logger::setOutputMode(Logger::OUTPUT_BOTH);
        Logger::setStreamTarget($stream);

        $marker = 'both-marker-' . substr(md5(__METHOD__), 0, 8);
        Logger::log($marker, 'radiochatbox');

        rewind($stream);
        $this->assertStringContainsString($marker, (string) stream_get_contents($stream));
    }
}
