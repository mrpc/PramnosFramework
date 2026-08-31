<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Mcp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Mcp\PublicRegistry;
use Pramnos\Mcp\ScopedMcpTool;
use Pramnos\Mcp\Tools\SearchTool;

/**
 * What may be served to a caller outside this machine, and what may not.
 *
 * The internal MCP server carries nineteen development tools — coverage reports, the style
 * checker, the framework's own docs — every one written for somebody who already has a shell
 * here. The single most important property of this feature is that none of them can ever answer
 * an authenticated stranger, and that the property holds without anybody remembering to maintain
 * a list of exclusions.
 */
#[CoversClass(PublicRegistry::class)]
#[CoversClass(SearchTool::class)]
class PublicToolsTest extends TestCase
{
    protected function setUp(): void
    {
        PublicRegistry::reset();
    }

    protected function tearDown(): void
    {
        PublicRegistry::reset();
    }

    /**
     * A development tool cannot be registered publicly, because it is the wrong type.
     *
     * This is the whole safety of the arrangement, and it is why exposure is a type rather than a
     * flag. A list of names to exclude is a list somebody forgets to extend, and the failure is
     * silent and remote: a twentieth development tool is written, and it answers over HTTP before
     * anybody notices it is reachable.
     */
    public function testADevelopmentToolIsNotEvenTypeCompatible(): void
    {
        // Assert
        $this->assertFalse(
            is_subclass_of(\Pramnos\Mcp\Tools\CoverageTool::class, ScopedMcpTool::class),
            'a development tool must not satisfy the public interface'
        );
    }

    /**
     * A tool declaring no scope is refused at registration, loudly.
     *
     * Dropping it quietly would make it absent at run time for a reason nobody can see: the
     * endpoint answers "no such tool" and the author goes looking in the wrong place.
     */
    public function testAToolWithoutAScopeIsRefused(): void
    {
        // Arrange
        $tool = $this->tool('anything', '');

        // Assert
        $this->expectException(\InvalidArgumentException::class);

        // Act
        PublicRegistry::add($tool);
    }

    /**
     * A caller sees only the tools their scopes reach.
     */
    public function testScopesDecideWhatIsVisible(): void
    {
        // Arrange
        PublicRegistry::add($this->tool('read-thing', 'user'));
        PublicRegistry::add($this->tool('write-thing', 'admin'));

        // Act
        $visible = PublicRegistry::visibleTo(['user']);

        // Assert
        $this->assertCount(1, $visible);
        $this->assertSame('read-thing', $visible[0]->name());
    }

    /**
     * A token with no scopes sees nothing.
     *
     * The direction that matters. An empty scope list arrives whenever a token's scopes could not
     * be read, and the safe reading of "I do not know what this caller may do" is nothing at all.
     */
    public function testNoScopesSeesNothing(): void
    {
        // Arrange
        PublicRegistry::add($this->tool('read-thing', 'user'));

        // Assert
        $this->assertSame([], PublicRegistry::visibleTo([]));
    }

    /**
     * The wildcard a same-origin session carries sees everything.
     *
     * `UnifiedAuthMiddleware` grants `['*']` to a cookie rather than a bearer token, and the
     * router already treats it that way. A second, stricter rule here would mean the same person
     * gets different answers from the search box and the MCP endpoint.
     */
    public function testTheSessionWildcardSeesEverything(): void
    {
        // Arrange
        PublicRegistry::add($this->tool('read-thing', 'user'));
        PublicRegistry::add($this->tool('write-thing', 'admin'));

        // Assert
        $this->assertCount(2, PublicRegistry::visibleTo(['*']));
    }

    /**
     * The search tool asks for a scope, and it is a scope this installation defines.
     *
     * A tool requiring a scope no authorization server will ever issue is a tool nobody can call,
     * and the failure appears as an empty tool list with no explanation.
     */
    public function testTheSearchToolAsksForARealScope(): void
    {
        // Act
        $scope = (new SearchTool())->requiredScope();

        // Assert
        $this->assertNotSame('', $scope);
        $this->assertArrayHasKey($scope, \Pramnos\Auth\Scopes::getScopeDescriptions());
    }

    /**
     * An empty term returns nothing rather than the first page of everything.
     *
     * The one answer this must never give. `Registry::query()` already refuses it; asserted here
     * because the refusal has to survive this tool being the caller.
     */
    public function testAnEmptyTermReturnsNothing(): void
    {
        // Act
        $result = (new SearchTool())->execute(['query' => '   ']);

        // Assert
        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['groups']);
    }

    /**
     * An installation with no sources says so instead of reporting no matches.
     *
     * A model told "0 results" concludes the thing does not exist and tells the person so. Told
     * that nothing is searchable, it says that instead — which is true, and actionable.
     */
    public function testNoSourcesIsSaidOutLoud(): void
    {
        // Arrange
        \Pramnos\Search\Registry::reset();

        // Act
        $result = (new SearchTool())->execute(['query' => 'anything']);

        // Assert
        $this->assertSame(0, $result['total']);
        $this->assertArrayHasKey('note', $result);
    }

    /**
     * The per-group limit is clamped, whatever was asked for.
     *
     * `limit` comes from a language model, which will eventually ask for a thousand. The cap is
     * the difference between a large answer and every row of every source.
     */
    public function testTheLimitIsClamped(): void
    {
        // Arrange
        $tool   = new SearchTool();
        $schema = $tool->inputSchema();

        // Assert
        $this->assertSame(25, $schema['properties']['limit']['maximum']);
        $this->assertSame(1, $schema['properties']['limit']['minimum']);
    }

    private function tool(string $name, string $scope): ScopedMcpTool
    {
        return new class ($name, $scope) implements ScopedMcpTool {
            public function __construct(private string $n, private string $s)
            {
            }

            public function name(): string
            {
                return $this->n;
            }

            public function description(): string
            {
                return 'test';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object'];
            }

            public function execute(array $input): mixed
            {
                return [];
            }

            public function requiredScope(): string
            {
                return $this->s;
            }
        };
    }
}
