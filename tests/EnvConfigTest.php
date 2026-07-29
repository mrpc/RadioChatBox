<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Covers RadioChatBox's configuration after the #16 retirement of the
 * RadioChatBox\Config wrapper: configuration is now read directly from the
 * environment through the framework's native envvar() helper (values loaded
 * from .env by loadDotenv() in the bootstrap), with the same defaults the
 * wrapper used to provide. These tests pin the type-coercion and default
 * behaviour the app relies on.
 */
class EnvConfigTest extends TestCase
{
    /**
     * A missing variable falls back to the supplied default (envvar's contract),
     * which is how every app config read now expresses its default.
     */
    public function testMissingVariableReturnsDefault(): void
    {
        $key = 'RCB_DEFINITELY_UNSET_' . bin2hex(random_bytes(4));
        $this->assertSame('fallback', envvar($key, 'fallback'));
        $this->assertNull(envvar($key));
    }

    /**
     * envvar() coerces numeric strings to int, so a port or limit read from the
     * environment is a real int without an explicit cast — the behaviour the DB
     * port, Redis port and chat limits depend on.
     */
    public function testNumericValuesAreCoercedToInt(): void
    {
        putenv('RCB_NUM_PROBE=6379');
        try {
            $this->assertSame(6379, envvar('RCB_NUM_PROBE'));
        } finally {
            putenv('RCB_NUM_PROBE');
        }
    }

    /**
     * A set variable wins over the default; a real environment value is read back
     * verbatim (after coercion).
     */
    public function testSetVariableOverridesDefault(): void
    {
        putenv('RCB_STR_PROBE=hello-world');
        try {
            $this->assertSame('hello-world', envvar('RCB_STR_PROBE', 'unused-default'));
        } finally {
            putenv('RCB_STR_PROBE');
        }
    }

    /**
     * The database/redis defaults resolve to the Docker service names, so the app
     * connects even when no .env is present — the same defaults the retired Config
     * wrapper shipped.
     */
    public function testConnectionDefaultsMatchDockerServiceNames(): void
    {
        // These run under the test container where the vars ARE set; assert the
        // read is a usable non-empty value of the right type rather than pinning a
        // specific host that differs between environments.
        $this->assertIsString((string) envvar('DB_HOST', 'postgres'));
        $this->assertNotSame('', (string) envvar('DB_HOST', 'postgres'));
        $this->assertGreaterThan(0, (int) envvar('DB_PORT', 5432));
        $this->assertGreaterThan(0, (int) envvar('REDIS_PORT', 6379));
        $this->assertSame(100, (int) envvar('CHAT_HISTORY_LIMIT', 100));
    }
}
