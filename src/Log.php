<?php

namespace RadioChatBox;

use Pramnos\Logs\Logger;

/**
 * Application logging facade.
 *
 * Routes RadioChatBox log lines through the framework Logger on the
 * "radiochatbox" channel, so they land in a file the LogViewer can read AND —
 * with the "both" output mode configured in the bootstrap — on STDERR for
 * `docker logs`. A drop-in replacement for error_log(): same single-string call
 * shape. Falls back to error_log() if the framework Logger is somehow absent.
 */
final class Log
{
    private const CHANNEL = 'radiochatbox';

    /**
     * Log a message (level-neutral, like error_log).
     *
     * Hybrid on purpose: error_log() preserves the existing STDERR destination
     * (what `docker logs` shows, and what the test-suite observes), while the
     * framework Logger additionally records the line to a channel file the
     * LogViewer can read. Together they give "both" without duplicating STDERR
     * (the Logger runs in file mode — see the bootstrap).
     */
    public static function write(string $message): void
    {
        \error_log($message);
        if (class_exists(Logger::class)) {
            Logger::log($message, self::CHANNEL);
        }
    }

    /** Log at a specific PSR-3 level with optional context. */
    public static function error(string $message, array $context = []): void
    {
        class_exists(Logger::class) ? Logger::error($message, $context, self::CHANNEL) : \error_log($message);
    }

    public static function warning(string $message, array $context = []): void
    {
        class_exists(Logger::class) ? Logger::warning($message, $context, self::CHANNEL) : \error_log($message);
    }

    public static function info(string $message, array $context = []): void
    {
        class_exists(Logger::class) ? Logger::info($message, $context, self::CHANNEL) : \error_log($message);
    }
}
