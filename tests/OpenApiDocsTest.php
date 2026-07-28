<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use Pramnos\Routing\OpenApiGenerator;

/**
 * Ensures the attribute-routed API stays documentable via the framework's
 * OpenAPI generator (Phase 8 structure-alignment: `php bin/rcb api:docs`).
 *
 * Runs the same generator the api:docs command uses over src/Controllers and
 * asserts the document is well-formed and covers representative endpoints, so a
 * controller that breaks reflection (or drops its #[Route]) is caught, and the
 * committed public/api/openapi.json can be regenerated with confidence.
 */
class OpenApiDocsTest extends TestCase
{
    private function generate(): array
    {
        return (new OpenApiGenerator(['title' => 'RadioChatBox API', 'version' => '1.0.0']))
            ->fromDirectory(dirname(__DIR__) . '/src/Controllers', 'RadioChatBox\\Controllers');
    }

    /**
     * The generated document is a valid OpenAPI 3.0 skeleton with the app info
     * and a non-trivial number of documented operations.
     */
    public function testGeneratesWellFormedDocument(): void
    {
        $doc = $this->generate();

        $this->assertSame('3.0.3', $doc['openapi']);
        $this->assertSame('RadioChatBox API', $doc['info']['title']);
        $this->assertNotEmpty($doc['paths']);

        $operations = 0;
        foreach ($doc['paths'] as $methods) {
            $operations += count($methods);
        }
        $this->assertGreaterThan(50, $operations, 'the whole API surface should be documented');
    }

    /**
     * Representative public and admin endpoints are present, and an admin route is
     * documented as secured (bearerAuth) via its auth middleware.
     */
    public function testCoversRepresentativeEndpoints(): void
    {
        $doc = $this->generate();

        $this->assertArrayHasKey('/api/login', $doc['paths']);
        $this->assertArrayHasKey('post', $doc['paths']['/api/login']);
        $this->assertArrayHasKey('/api/admin/users', $doc['paths']);

        // Admin routes carry the AdminAuthMiddleware → documented as secured.
        $this->assertSame(
            [['bearerAuth' => []]],
            $doc['paths']['/api/admin/users']['get']['security']
        );
        $this->assertSame(
            ['type' => 'http', 'scheme' => 'bearer'],
            $doc['components']['securitySchemes']['bearerAuth']
        );
    }
}
