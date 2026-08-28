<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Mcp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Mcp\Tools\ApiDocsTool;

/**
 * `api-docs` — what the API promises, and whether the promise is current.
 *
 * `route-list` answers *what URIs exist*. This answers *what the API says it does*: parameters,
 * request bodies, response codes, which credential each operation needs. Different question,
 * and the one an integration is written against.
 *
 * The freshness half is the same problem as the stylesheet, and worse in one way: the OpenAPI
 * document is a generated file that gets committed, so a controller can gain a parameter while
 * the published document keeps describing the old shape. Nothing fails — the API works and the
 * documentation lies, which is the worst available outcome because somebody believes it.
 */
#[CoversClass(ApiDocsTool::class)]
class ApiDocsToolTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/api-docs-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/www/api', 0777, true);
        mkdir($this->root . '/src/Api/Controllers', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);

        parent::tearDown();
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }

        foreach ((array) scandir($path) as $entry) {
            if (is_string($entry) && $entry !== '.' && $entry !== '..') {
                $this->remove($path . '/' . $entry);
            }
        }

        @rmdir($path);
    }

    private function write(string $relative, string $contents, ?int $mtime = null): void
    {
        $path = $this->root . '/' . $relative;

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, $contents);

        if ($mtime !== null) {
            touch($path, $mtime);
        }
    }

    /**
     * A document with the path-level keys OpenAPI allows beside the methods.
     */
    private function document(?int $mtime = null): void
    {
        $this->write('www/api/openapi.json', json_encode([
            'openapi' => '3.0.3',
            'info'    => ['title' => 'Test API', 'version' => '2.1'],
            'servers' => [['url' => 'https://example.com/api']],
            'paths'   => [
                '/me' => [
                    'get' => [
                        'summary'  => 'The current user',
                        'tags'     => ['Me'],
                        'security' => [['ApiKeyAuth' => []]],
                        'responses' => ['200' => ['description' => 'ok']],
                    ],
                ],
                '/me/tokens/{id}' => [
                    // Legal at this level, and not an operation.
                    'servers'    => [['url' => 'https://other.example.com']],
                    'parameters' => [['name' => 'id', 'in' => 'path']],
                    'delete'     => [
                        'summary'    => 'Revoke a token',
                        'tags'       => ['Me'],
                        'parameters' => [['name' => 'id', 'in' => 'path']],
                        'responses'  => ['204' => ['description' => 'gone']],
                    ],
                ],
            ],
            'components' => ['securitySchemes' => ['ApiKeyAuth' => ['type' => 'apiKey']]],
        ], JSON_PRETTY_PRINT), $mtime);
    }

    /** @return array<string, mixed> */
    private function ask(array $input = []): array
    {
        /** @var array<string, mixed> $answer */
        $answer = (new ApiDocsTool($this->root))->execute($input);

        return $answer;
    }

    /**
     * The document is summarised, and each operation carries the credential it needs.
     *
     * Whether an operation requires a credential, and which, is the first thing anybody asks;
     * the full `security` object buries it three levels down.
     */
    public function testTheOperationsAreListedWithTheirCredential(): void
    {
        // Arrange
        $this->document();

        // Act
        $answer = $this->ask();

        // Assert
        $this->assertSame('3.0.3', $answer['document']['openapi']);
        $this->assertSame('Test API', $answer['document']['title']);
        $this->assertSame(['ApiKeyAuth'], $answer['document']['security_schemes']);
        $this->assertSame(['https://example.com/api'], $answer['document']['servers']);

        $operations = $answer['operationList'];
        $this->assertSame('GET', $operations[0]['method']);
        $this->assertSame('/me', $operations[0]['path']);
        $this->assertSame(['ApiKeyAuth'], $operations[0]['security']);
        $this->assertSame(1, $operations[1]['parameters'], 'the count, not the whole array');
    }

    /**
     * `servers` under a path is not an operation.
     *
     * OpenAPI allows `servers`, `parameters`, `summary` and `$ref` beside the methods, and
     * treating every key as one invented an operation called `SERVERS /oauth/token`. On the
     * real project that inflated the count from 15 to 20 — and a fabricated endpoint in a list
     * of endpoints is the same failure as a wrong URI: somebody would have called it.
     */
    public function testPathLevelKeysAreNotMistakenForOperations(): void
    {
        // Arrange
        $this->document();

        // Act
        $answer = $this->ask();

        // Assert
        $this->assertSame(2, $answer['document']['operations'], 'two operations, not four');

        foreach ($answer['operationList'] as $operation) {
            $this->assertContains(
                $operation['method'],
                ['GET', 'PUT', 'POST', 'DELETE', 'OPTIONS', 'HEAD', 'PATCH', 'TRACE'],
                $operation['method'] . ' is not an HTTP method'
            );
        }
    }

    /**
     * One operation comes back in full, and the path is matched case-insensitively.
     *
     * Because a caller reads the path off the list and retypes it.
     */
    public function testOneOperationComesBackInFull(): void
    {
        // Arrange
        $this->document();

        // Act
        $answer = $this->ask(['operation' => 'delete /ME/tokens/{id}']);

        // Assert
        $this->assertSame('DELETE', $answer['method']);
        $this->assertSame('Revoke a token', $answer['operation']['summary']);
        $this->assertArrayHasKey('204', $answer['operation']['responses']);
    }

    /**
     * An operation that is not documented lists the ones that are.
     *
     * The usual cause is a path that differs by a prefix or a parameter's name.
     */
    public function testAnUnknownOperationListsTheRealOnes(): void
    {
        // Arrange
        $this->document();

        // Act
        $answer = $this->ask(['operation' => 'GET /me/token']);

        // Assert
        $this->assertStringContainsString('No documented operation', $answer['error']);
        $this->assertContains('GET /me', $answer['operations']);
    }

    /**
     * A malformed operation reference says what the shape should be.
     */
    public function testAMalformedOperationReferenceIsExplained(): void
    {
        // Arrange
        $this->document();

        // Act
        $answer = $this->ask(['operation' => '/me']);

        // Assert
        $this->assertStringContainsString('a method and a path', $answer['error']);
    }

    /**
     * A controller changed after the document was generated makes it stale.
     */
    public function testAChangedControllerMakesTheDocumentStale(): void
    {
        // Arrange — generated an hour ago, a controller touched a minute ago
        $this->document(time() - 3600);
        $this->write('src/Api/Controllers/Thing.php', '<?php class Thing {}', time() - 60);

        // Act
        $freshness = $this->ask(['summary_only' => true])['generate']['freshness'];

        // Assert
        $this->assertTrue($freshness['stale']);
        $this->assertContains(
            'src/Api/Controllers/Thing.php',
            $freshness['controllers_newer_than_the_document']
        );
        $this->assertStringContainsString('documentation is wrong', $freshness['why']);
    }

    /**
     * A document newer than the controllers is not called stale.
     */
    public function testACurrentDocumentIsNotReportedAsStale(): void
    {
        // Arrange
        $this->write('src/Api/Controllers/Thing.php', '<?php class Thing {}', time() - 3600);
        $this->document(time() - 60);

        // Act
        $freshness = $this->ask(['summary_only' => true])['generate']['freshness'];

        // Assert
        $this->assertFalse($freshness['stale']);
        $this->assertArrayNotHasKey('controllers_newer_than_the_document', $freshness);
    }

    /**
     * The npm pipeline is recognised when the project has one.
     *
     * Two generators exist and a project uses one or the other; reporting only the framework's
     * would tell half of them they have no API documentation.
     */
    public function testTheApidocPipelineIsRecognised(): void
    {
        // Arrange
        $this->document();
        $this->write('package.json', json_encode([
            'scripts' => ['openapi:generate' => 'node scripts/apidoc-to-openapi.cjs'],
        ]));

        // Act
        $generate = $this->ask(['summary_only' => true])['generate'];

        // Assert
        $this->assertSame('apidoc-annotations', $generate['pipeline']);
        $this->assertSame('npm run openapi:generate', $generate['command']);
        $this->assertSame('src/Api/Controllers', $generate['sources']);
    }

    /**
     * With no npm script and no `#[Route]` attributes, it says the generator would be empty.
     *
     * And points at `route-list`, which parses the routes file. "Run api:docs" would be advice
     * that produces an empty document.
     */
    public function testWithoutAttributesItSaysTheGeneratorWouldBeEmpty(): void
    {
        // Arrange
        $this->document();
        $this->write('src/Api/Controllers/Plain.php', '<?php class Plain { public function go() {} }');

        // Act
        $generate = $this->ask(['summary_only' => true])['generate'];

        // Assert
        $this->assertSame('route-attributes', $generate['pipeline']);
        $this->assertStringContainsString('empty document', $generate['note']);
        $this->assertStringContainsString('route-list', $generate['note']);
    }

    /**
     * With `#[Route]` attributes present it says so instead.
     *
     * Read rather than loaded: requiring controllers to find out is how route discovery came
     * to execute the view templates.
     */
    public function testWithAttributesItNamesTheAttributeGenerator(): void
    {
        // Arrange
        $this->document();
        $this->write(
            'src/Api/Controllers/Attributed.php',
            '<?php class Attributed { #[Route("/x", methods: ["GET"])] public function x() {} }'
        );

        // Act
        $generate = $this->ask(['summary_only' => true])['generate'];

        // Assert
        $this->assertStringContainsString('`#[Route]` attributes', $generate['note']);
        $this->assertStringNotContainsString('empty document', $generate['note']);
    }

    /**
     * No document at all says where it looked and how to make one.
     */
    public function testAMissingDocumentSaysWhereItLooked(): void
    {
        // Act
        $answer = $this->ask();

        // Assert
        $this->assertStringContainsString('No generated OpenAPI document', $answer['error']);
        $this->assertContains('www/api/openapi.json', $answer['looked']);
        $this->assertArrayHasKey('command', $answer['generate']);
    }

    /**
     * A document that is not valid JSON is reported as such, not as an empty API.
     */
    public function testUnreadableJsonIsReportedRatherThanTreatedAsEmpty(): void
    {
        // Arrange
        $this->write('www/api/openapi.json', '{not json');

        // Act
        $answer = $this->ask();

        // Assert
        $this->assertStringContainsString('not readable JSON', $answer['error']);
        $this->assertStringContainsString('regenerate', $answer['error']);
    }

    /**
     * `summary_only` leaves the operation list out.
     *
     * An MCP response is a message: eighty operations is a document dump when the question was
     * "is this current".
     */
    public function testSummaryOnlyOmitsTheList(): void
    {
        // Arrange
        $this->document();

        // Act
        $answer = $this->ask(['summary_only' => true]);

        // Assert
        $this->assertArrayNotHasKey('operationList', $answer);
        $this->assertSame(2, $answer['document']['operations'], 'the count still comes back');
    }
}
