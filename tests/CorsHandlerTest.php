<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use RadioChatBox\CorsHandler;

/**
 * The header-sending part of CorsHandler::handle() cannot be observed in CLI
 * (header() is a no-op without Xdebug) and its preflight branch calls exit, so
 * these tests target the decision methods it delegates to.
 */
class CorsHandlerTest extends TestCase
{
    private ?string $originalRequestMethod = null;

    protected function setUp(): void
    {
        $this->originalRequestMethod = $_SERVER['REQUEST_METHOD'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->originalRequestMethod === null) {
            unset($_SERVER['REQUEST_METHOD']);
        } else {
            $_SERVER['REQUEST_METHOD'] = $this->originalRequestMethod;
        }
    }

    // ------------------------------------------------------------------
    // Wildcard
    // ------------------------------------------------------------------

    public function testHandleAllowsWildcardOrigin(): void
    {
        $headers = CorsHandler::resolveHeaders('https://example.com', ['*']);

        $this->assertSame('https://example.com', $headers['Access-Control-Allow-Origin']);
        $this->assertSame('GET, POST, OPTIONS', $headers['Access-Control-Allow-Methods']);
        $this->assertSame('Content-Type', $headers['Access-Control-Allow-Headers']);
        $this->assertSame('true', $headers['Access-Control-Allow-Credentials']);
    }

    public function testWildcardWithoutAnOriginEchoesStar(): void
    {
        // A request with no Origin header (curl, same-origin) still gets '*'.
        $headers = CorsHandler::resolveHeaders('', ['*']);

        $this->assertSame('*', $headers['Access-Control-Allow-Origin']);
    }

    // ------------------------------------------------------------------
    // Explicit allow list
    // ------------------------------------------------------------------

    public function testHandleAllowsSpecificOrigin(): void
    {
        $allowed = ['https://radio.example', 'http://localhost:98'];

        $headers = CorsHandler::resolveHeaders('http://localhost:98', $allowed);

        $this->assertSame('http://localhost:98', $headers['Access-Control-Allow-Origin']);
        $this->assertArrayHasKey('Access-Control-Allow-Credentials', $headers);
    }

    public function testHandleRejectsUnauthorizedOrigin(): void
    {
        $headers = CorsHandler::resolveHeaders('https://evil.example', ['https://radio.example']);

        // No headers at all - the browser then blocks the response.
        $this->assertSame([], $headers);
    }

    public function testEmptyOriginIsNotAllowedByAnExplicitList(): void
    {
        // An empty origin must not match an empty entry in a misconfigured list.
        $this->assertSame([], CorsHandler::resolveHeaders('', ['https://radio.example']));
    }

    public function testOriginMatchingIsExact(): void
    {
        $allowed = ['https://radio.example'];

        $this->assertSame([], CorsHandler::resolveHeaders('https://radio.example.evil.com', $allowed));
        $this->assertSame([], CorsHandler::resolveHeaders('http://radio.example', $allowed));
        $this->assertSame([], CorsHandler::resolveHeaders('https://radio.example/', $allowed));
    }

    public function testNoOriginIsAllowedWhenTheListIsEmpty(): void
    {
        $this->assertSame([], CorsHandler::resolveHeaders('https://example.com', []));
    }

    // ------------------------------------------------------------------
    // Preflight
    // ------------------------------------------------------------------

    public function testHandleRespondsToOptionsRequest(): void
    {
        $this->assertTrue(CorsHandler::isPreflight('OPTIONS'));
        $this->assertTrue(CorsHandler::isPreflight('options'), 'the method should be compared case-insensitively');

        $this->assertFalse(CorsHandler::isPreflight('GET'));
        $this->assertFalse(CorsHandler::isPreflight('POST'));
    }

    public function testPreflightFallsBackToTheCurrentRequestMethod(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'OPTIONS';
        $this->assertTrue(CorsHandler::isPreflight());

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->assertFalse(CorsHandler::isPreflight());
    }

    public function testPreflightDoesNotWarnWithoutARequestMethod(): void
    {
        // CLI has no REQUEST_METHOD; reading it directly used to raise
        // "Undefined array key".
        unset($_SERVER['REQUEST_METHOD']);

        $this->assertFalse(CorsHandler::isPreflight());
    }
}
