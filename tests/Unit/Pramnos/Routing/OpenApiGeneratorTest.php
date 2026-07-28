<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Routing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Routing\Attributes\Route;
use Pramnos\Routing\OpenApiGenerator;

/**
 * A fixture controller exercising the attribute shapes the generator reads:
 * a plain GET, a secured route (via middleware), a path parameter and a
 * multi-line docblock.
 */
class OpenApiFixtureController
{
    /**
     * List the widgets.
     *
     * Returns every widget the caller can see.
     */
    #[Route('/api/widgets', methods: 'GET', name: 'widgets.list')]
    public function index(): void
    {
    }

    /** Show one widget. */
    #[Route('/api/widgets/{id}', methods: 'GET', name: 'widgets.show')]
    public function show(): void
    {
    }

    #[Route('/api/widgets', methods: ['POST'], name: 'widgets.create', middleware: ['App\\Middleware\\AdminAuthMiddleware'])]
    public function create(): void
    {
    }
}

/**
 * Unit tests for the attribute-native OpenAPI generator.
 *
 * Drives the generator with an in-code fixture controller so the reflection of
 * #[Route] attributes, docblock parsing, path-parameter extraction, security
 * derivation and overrides deep-merge are all verified without scanning the repo.
 */
#[CoversClass(OpenApiGenerator::class)]
class OpenApiGeneratorTest extends TestCase
{
    private function generate(array $overrides = []): array
    {
        return (new OpenApiGenerator(
            ['title' => 'Widget API', 'version' => '2.1.0'],
            [['url' => 'https://example.test']],
            $overrides
        ))->fromClasses([OpenApiFixtureController::class]);
    }

    /**
     * The document carries the OpenAPI version, the supplied info/servers and one
     * path entry per route, keyed by the route's HTTP method.
     */
    public function testGeneratesInfoServersAndPaths(): void
    {
        $doc = $this->generate();

        $this->assertSame('3.0.3', $doc['openapi']);
        $this->assertSame('Widget API', $doc['info']['title']);
        $this->assertSame('2.1.0', $doc['info']['version']);
        $this->assertSame('https://example.test', $doc['servers'][0]['url']);

        $this->assertArrayHasKey('/api/widgets', $doc['paths']);
        $this->assertArrayHasKey('get', $doc['paths']['/api/widgets']);
        $this->assertArrayHasKey('post', $doc['paths']['/api/widgets']);
        $this->assertArrayHasKey('/api/widgets/{id}', $doc['paths']);
    }

    /**
     * operationId comes from the route name, and the docblock is split into a
     * one-line summary and a longer description.
     */
    public function testOperationIdAndDocblock(): void
    {
        $get = $this->generate()['paths']['/api/widgets']['get'];

        $this->assertSame('widgets.list', $get['operationId']);
        $this->assertSame('List the widgets.', $get['summary']);
        $this->assertSame('Returns every widget the caller can see.', $get['description']);
        $this->assertSame(['OpenApiFixtureController'], $get['tags']);
    }

    /**
     * A {param} segment becomes a required path parameter.
     */
    public function testPathParameterExtraction(): void
    {
        $show = $this->generate()['paths']['/api/widgets/{id}']['get'];

        $this->assertSame([
            ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
        ], $show['parameters']);
    }

    /**
     * A route with an auth middleware is marked secured (bearerAuth), and the
     * security scheme is declared in components; unsecured routes carry no security.
     */
    public function testSecurityDerivedFromAuthMiddleware(): void
    {
        $doc = $this->generate();

        $this->assertSame([['bearerAuth' => []]], $doc['paths']['/api/widgets']['post']['security']);
        $this->assertArrayNotHasKey('security', $doc['paths']['/api/widgets']['get']);
        $this->assertSame(
            ['type' => 'http', 'scheme' => 'bearer'],
            $doc['components']['securitySchemes']['bearerAuth']
        );
    }

    /**
     * An overrides document is deep-merged over the generated one, so an app can
     * enrich a specific operation (e.g. add a response schema) without losing the
     * generated paths.
     */
    public function testOverridesAreDeepMerged(): void
    {
        $doc = $this->generate([
            'info'  => ['description' => 'Hand-written blurb'],
            'paths' => [
                '/api/widgets' => [
                    'get' => [
                        'responses' => ['200' => ['description' => 'A list of widgets']],
                    ],
                ],
            ],
        ]);

        // Override merged in…
        $this->assertSame('Hand-written blurb', $doc['info']['description']);
        $this->assertSame('A list of widgets', $doc['paths']['/api/widgets']['get']['responses']['200']['description']);
        // …without clobbering generated siblings.
        $this->assertSame('Widget API', $doc['info']['title']);
        $this->assertSame('widgets.list', $doc['paths']['/api/widgets']['get']['operationId']);
    }
}
