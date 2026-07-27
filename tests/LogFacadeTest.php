<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use Pramnos\Logs\Logger;
use RadioChatBox\Log;

/**
 * Covers the RadioChatBox\Log facade adoption of the framework Logger.
 *
 * Log::write is a hybrid: error_log() (STDERR — unchanged) PLUS the framework
 * Logger (a LogViewer-readable channel file). Here we point the Logger at an
 * in-memory stream to assert the message is routed to it on the "radiochatbox"
 * channel, without touching real log files.
 */
class LogFacadeTest extends TestCase
{
    protected function tearDown(): void
    {
        Logger::setStreamTarget(null);
        Logger::setOutputMode(Logger::OUTPUT_FILE); // restore the bootstrap default
    }

    public function testWriteRoutesMessageToFrameworkLogger(): void
    {
        $stream = fopen('php://memory', 'r+');
        Logger::setOutputMode(Logger::OUTPUT_STREAM);
        Logger::setStreamTarget($stream);

        $marker = 'facade-marker-' . substr(md5(__METHOD__), 0, 6);

        // error_log() (the STDERR half of the hybrid) surfaces as test output here;
        // expecting it both proves that half fired and satisfies strict output mode.
        $this->expectOutputRegex('/' . preg_quote($marker, '/') . '/');

        Log::write($marker);

        // The framework-Logger half routed the same line to the (memory) channel.
        rewind($stream);
        $this->assertStringContainsString($marker, (string) stream_get_contents($stream));
    }
}
