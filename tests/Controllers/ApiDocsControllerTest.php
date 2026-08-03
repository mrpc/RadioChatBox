<?php

namespace RadioChatBox\Tests\Controllers;

use PHPUnit\Framework\TestCase;
use RadioChatBox\Controllers\ApiDocsController;

/**
 * The live API docs: a generated OpenAPI 3 spec covering the app's routes and a
 * self-contained HTML viewer.
 */
class ApiDocsControllerTest extends TestCase
{
    /** The spec endpoint returns a well-formed OpenAPI document with paths. */
    public function testSpecReturnsOpenApiDocument(): void
    {
        $response = (new ApiDocsController())->spec();
        $this->assertSame(200, $response->getStatusCode());
        $doc = json_decode($response->getBody(), true);
        $this->assertSame('RadioChatBox API', $doc['info']['title']);
        $this->assertArrayHasKey('paths', $doc);
        $this->assertNotEmpty($doc['paths']);
        // A representative endpoint added in this work is present.
        $this->assertArrayHasKey('/api/shows/upcoming', $doc['paths']);
    }

    /** The viewer returns a self-contained HTML page. */
    public function testViewerReturnsHtml(): void
    {
        $response = (new ApiDocsController())->viewer();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('text/html', (string) $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('RadioChatBox API', (string) $response->getBody());
    }
}
