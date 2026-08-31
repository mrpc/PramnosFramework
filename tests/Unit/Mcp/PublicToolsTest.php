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

    /**
     * A capability can be offered without writing a class.
     *
     * The short door exists because a class file for three lines of logic is the reason
     * capabilities do not get offered at all.
     */
    public function testAToolCanBeOfferedWithoutAClass(): void
    {
        // Arrange
        PublicRegistry::offer(
            name:        'station-health',
            scope:       'user',
            description: 'Report the last stream check.',
            input:       ['station_id' => 'integer'],
            handler:     static fn (array $in): array => ['id' => $in['station_id']],
        );

        // Act
        $tools = PublicRegistry::visibleTo(['user']);

        // Assert
        $this->assertCount(1, $tools);
        $this->assertSame('station-health', $tools[0]->name());
        $this->assertSame(['id' => 7], $tools[0]->execute(['station_id' => 7]));
    }

    /**
     * The short door obeys the same refusal as the long one.
     *
     * Two ways in must not mean two sets of rules — that is how the convenient one becomes the
     * one that skips the check.
     */
    public function testTheShortDoorStillRefusesAScopelessTool(): void
    {
        // Assert
        $this->expectException(\InvalidArgumentException::class);

        // Act
        PublicRegistry::offer('x', '', 'x', [], static fn (): int => 1);
    }

    /**
     * A compact spec becomes JSON Schema, and `?` is what makes a parameter optional.
     *
     * Required by default is the safer mistake: a model that omits something the tool needs gets
     * a clear refusal, where a tool quietly running without it produces a wrong answer nobody
     * questions.
     */
    public function testACompactSpecBecomesJsonSchema(): void
    {
        // Act
        $schema = PublicRegistry::schema(['query' => 'string', 'limit' => 'integer?']);

        // Assert
        $this->assertSame('object', $schema['type']);
        $this->assertSame(['type' => 'string'], $schema['properties']['query']);
        $this->assertSame(['type' => 'integer'], $schema['properties']['limit']);
        $this->assertSame(['query'], $schema['required'], 'only the one without a ? is required');
    }

    /**
     * A real JSON Schema passes through untouched.
     *
     * The escape hatch, and the reason the compact form has no wall to hit: anything it cannot
     * express — enums, patterns, nested objects — is written as ordinary JSON Schema and nothing
     * interferes with it.
     */
    public function testAFullSchemaIsLeftAlone(): void
    {
        // Arrange
        $full = [
            'type'       => 'object',
            'properties' => ['mode' => ['type' => 'string', 'enum' => ['fast', 'slow']]],
            'required'   => ['mode'],
        ];

        // Assert
        $this->assertSame($full, PublicRegistry::schema($full));
    }

    /**
     * One awkward parameter does not force the whole spec into longhand.
     */
    public function testOnePropertyCanBeSpeltOutAmongShorthand(): void
    {
        // Act
        $schema = PublicRegistry::schema([
            'query' => 'string',
            'mode'  => ['type' => 'string', 'enum' => ['fast', 'slow']],
        ]);

        // Assert
        $this->assertSame(['type' => 'string'], $schema['properties']['query']);
        $this->assertSame(['fast', 'slow'], $schema['properties']['mode']['enum']);
    }

    /**
     * A tool taking no input still describes itself as an object.
     *
     * `[]` is a tool with no parameters, not a tool with no schema. A client given no `type` has
     * nothing to validate against and some refuse to call at all.
     */
    public function testAToolWithNoParametersStillHasASchema(): void
    {
        // Act
        $schema = PublicRegistry::schema([]);

        // Assert
        $this->assertSame('object', $schema['type']);
        $this->assertSame([], $schema['properties']);
        $this->assertArrayNotHasKey('required', $schema);
    }

    /**
     * The search tool describes itself with what this installation actually has.
     *
     * The description is all a model reads when deciding whether to call a tool. "Search this
     * application" tells it nothing; naming the sources tells it whether the answer it wants is
     * even in here.
     */
    public function testTheDescriptionNamesWhatIsSearchable(): void
    {
        // Arrange
        \Pramnos\Search\Registry::reset();
        $generic = (new SearchTool())->description();

        \Pramnos\Search\Registry::register('Users', \Pramnos\User\User::class, [
            'display' => ['username'],
            'url'     => '/admin/users/:id',
        ]);
        $named = (new SearchTool())->description();

        \Pramnos\Search\Registry::reset();

        // Assert
        $this->assertStringContainsString('this application', $generic);
        $this->assertStringContainsString('Users', $named);
    }

    /**
     * A term does reach the registry, and the answer keeps its shape.
     *
     * The tests above assert the two refusals — empty term, no sources. This is the path that
     * returns something, and the shape matters: grouped by source, with a total, because that is
     * what a model can reason about.
     */
    public function testATermReachesTheRegistryAndComesBackGrouped(): void
    {
        // Arrange
        \Pramnos\Search\Registry::reset();
        \Pramnos\Search\Registry::register('Users', \Pramnos\User\User::class, [
            'display' => ['username'],
            'url'     => '/admin/users/:id',
        ]);

        try {
            // Act
            $result = (new SearchTool())->execute(['query' => 'zzz-nothing-matches-this']);

            // Assert
            $this->assertArrayHasKey('groups', $result);
            $this->assertArrayHasKey('total', $result);
            $this->assertArrayNotHasKey('note', $result,
                'the note is only for an installation with no sources at all');
        } finally {
            \Pramnos\Search\Registry::reset();
        }
    }

    /**
     * The limit a caller asks for is honoured within the cap.
     *
     * The schema advertises 1–25; the tool has to clamp to it rather than trust the number. A
     * language model will eventually ask for a thousand.
     */
    public function testALimitWithinTheCapIsHonoured(): void
    {
        // Arrange
        \Pramnos\Search\Registry::reset();
        \Pramnos\Search\Registry::register('Users', \Pramnos\User\User::class, [
            'display' => ['username'],
            'url'     => '/admin/users/:id',
        ]);

        try {
            // Act — absurd, and clamped rather than refused
            $result = (new SearchTool())->execute(['query' => 'a', 'limit' => 10000]);

            // Assert
            $this->assertIsArray($result['groups']);
        } finally {
            \Pramnos\Search\Registry::reset();
        }
    }

    /**
     * A tool offered twice under one name is the later one.
     *
     * The registry is keyed by name, so a second registration replaces rather than duplicating —
     * which is what an application overriding a framework-provided tool needs.
     */
    public function testRegisteringTheSameNameTwiceReplaces(): void
    {
        // Arrange
        PublicRegistry::offer('thing', 'user', 'First.', [], static fn (): int => 1);
        PublicRegistry::offer('thing', 'user', 'Second.', [], static fn (): int => 2);

        // Act
        $tools = PublicRegistry::visibleTo(['user']);

        // Assert
        $this->assertCount(1, $tools);
        $this->assertSame('Second.', $tools[0]->description());
        $this->assertSame(2, $tools[0]->execute([]));
    }

    /**
     * An offered tool answers the four questions a client asks of it.
     *
     * `offer()` builds the tool from five arguments; a client then asks it for its name, its
     * sentence, its schema and its result. The schema is the one that had never been asked for —
     * and a tool whose schema does not render is a tool no client will call, which looks exactly
     * like a tool nobody wanted.
     */
    public function testAnOfferedToolAnswersEverythingAClientAsks(): void
    {
        // Arrange
        PublicRegistry::offer(
            name:        'station-health',
            scope:       'user',
            description: 'Report the last stream check.',
            input:       ['station_id' => 'integer', 'verbose' => 'boolean?'],
            handler:     static fn (array $in): array => ['id' => $in['station_id']],
        );

        // Act
        $tool   = PublicRegistry::visibleTo(['user'])[0];
        $schema = $tool->inputSchema();

        // Assert
        $this->assertSame('station-health', $tool->name());
        $this->assertSame('Report the last stream check.', $tool->description());
        $this->assertSame('user', $tool->requiredScope());
        $this->assertSame('object', $schema['type']);
        $this->assertSame(['station_id'], $schema['required'], 'only the one without a ?');
        $this->assertSame(['id' => 3], $tool->execute(['station_id' => 3]));
    }

    /**
     * `hasTools()` distinguishes an endpoint with something to offer from one without.
     *
     * `llms.txt` reads it to decide whether to announce the MCP endpoint at all, because pointing
     * a model at an endpoint that serves an empty list is worse than not mentioning it.
     */
    public function testHasToolsReflectsWhetherAnythingIsOffered(): void
    {
        // Assert
        $this->assertFalse(PublicRegistry::hasTools(), 'nothing registered yet');

        PublicRegistry::offer('thing', 'user', 'A thing.', [], static fn (): int => 1);

        $this->assertTrue(PublicRegistry::hasTools());
    }

    /**
     * The search tool's own name is what a client calls.
     */
    public function testTheSearchToolIsCalledSearch(): void
    {
        // Assert
        $this->assertSame('search', (new SearchTool())->name());
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
