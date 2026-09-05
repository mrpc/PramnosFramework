<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Mcp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Scopes;
use Pramnos\Mcp\McpServiceProvider;
use Pramnos\Mcp\McpToolInterface;
use Pramnos\Mcp\PublicRegistry;
use Pramnos\Mcp\ScopedMcpTool;
use Pramnos\Mcp\ScopedTool;

/**
 * Offering the diagnostic readers to somebody who is not on this machine.
 *
 * This is the arrangement that replaces SSH, so the interesting assertions are
 * about what it does *not* do: the type boundary that keeps a development tool
 * from becoming public by being written must survive having a door added next to
 * it, the tool that writes files must stay out, and a tool nobody classified must
 * be dropped rather than given a fallback scope.
 */
#[CoversClass(ScopedTool::class)]
#[CoversClass(McpServiceProvider::class)]
#[CoversClass(PublicRegistry::class)]
class PublicDiagnosticsTest extends TestCase
{
    protected function setUp(): void
    {
        PublicRegistry::reset();
    }

    protected function tearDown(): void
    {
        PublicRegistry::reset();
        parent::tearDown();
    }

    /**
     * The type boundary still holds: a development tool is not publicly
     * registrable on its own.
     *
     * This is the property the whole two-registry design exists for, and adding a
     * wrapper is exactly the change that could have quietly dissolved it. A
     * twenty-first development tool must still be unable to reach the internet by
     * being written — only by somebody naming it and choosing its scope.
     */
    public function testADevelopmentToolIsStillNotPubliclyRegistrable(): void
    {
        // Assert
        $this->assertFalse(
            is_subclass_of(\Pramnos\Mcp\Tools\CoverageTool::class, ScopedMcpTool::class),
            'a development tool must not satisfy the public interface by itself'
        );
        $this->assertFalse(
            is_subclass_of(\Pramnos\Mcp\Tools\StatusTool::class, ScopedMcpTool::class),
            'not even one that is offered publicly by default, because the scope is '
            . 'a property of the exposure and not of the tool'
        );
    }

    /**
     * Wrapping is the door, and everything else passes straight through.
     *
     * A wrapper that changed the name or the schema would break the client: the
     * model calls tools by the name it read from `tools/list`.
     */
    public function testTheWrapperDelegatesEverythingButTheScope(): void
    {
        // Arrange
        $inner = new class implements McpToolInterface {
            public function name(): string
            {
                return 'inner-thing';
            }
            public function description(): string
            {
                return 'A sentence.';
            }
            public function inputSchema(): array
            {
                return array('type' => 'object', 'properties' => array('id' => array()));
            }
            public function execute(array $input): mixed
            {
                return array('got' => $input['id'] ?? null);
            }
        };

        // Act
        $wrapped = ScopedTool::wrap($inner, 'mcp:diagnostics');

        // Assert
        $this->assertSame('inner-thing', $wrapped->name());
        $this->assertSame('A sentence.', $wrapped->description());
        $this->assertSame($inner->inputSchema(), $wrapped->inputSchema());
        $this->assertSame(array('got' => 7), $wrapped->execute(array('id' => 7)));
        $this->assertSame('mcp:diagnostics', $wrapped->requiredScope());
        // and the tool underneath is still reachable, so a registry of wrapped
        // tools can be asked what it is actually serving
        $this->assertSame($inner, $wrapped->inner());
    }

    /**
     * A wrapped tool is accepted, and a wrapper with no scope is refused.
     *
     * The refusal comes from `PublicRegistry` rather than from here, which is the
     * point: the wrapper adds a door and does not add an exemption.
     */
    public function testAnEmptyScopeIsStillRefused(): void
    {
        // Arrange
        $tool = ScopedTool::wrap(new \Pramnos\Mcp\Tools\ModelInspectTool(), '');

        // Assert
        $this->expectException(\InvalidArgumentException::class);

        // Act
        PublicRegistry::add($tool);
    }

    /**
     * With no application, the readers that need none are still offered.
     *
     * Which is the case worth having: a broken application is exactly when
     * somebody wants the logs, and the logs are on disk whether or not anything
     * boots.
     */
    public function testWithoutAnApplicationTheLogReadersAreStillOffered(): void
    {
        // Act
        $offered = McpServiceProvider::offerDiagnostics(null);

        // Assert
        $this->assertContains('log-errors', $offered);
        $this->assertContains('log-analytics', $offered);
        $this->assertContains('request-debug', $offered);
        // and the ones that need an application are not invented
        $this->assertNotContains('status', $offered);
        $this->assertNotContains('migration-status', $offered);
    }

    /**
     * The tool that writes files is not offered, and is not classified either.
     *
     * `changelog-add` edits the repository. A public endpoint that writes on a
     * production box is a different risk class from one that reads it, and nothing
     * about diagnosing an incident needs it — so it is absent from the catalogue,
     * not merely absent from the default call.
     */
    public function testTheToolThatWritesIsNotOffered(): void
    {
        // Act
        $offered = McpServiceProvider::offerDiagnostics(null);

        // Assert
        $this->assertNotContains('changelog-add', $offered);
        $this->assertArrayNotHasKey('changelog-add', McpServiceProvider::DIAGNOSTIC_SCOPES);
    }

    /**
     * Tools that answer questions about the codebase are not offered.
     *
     * Whoever is asking has the code checked out. Over HTTP these disclose source
     * structure and buy nothing, so they are left out on purpose — and the test
     * names them so that adding one back has to be a decision rather than a drift.
     */
    public function testCodebaseToolsAreNotOffered(): void
    {
        // Assert
        foreach (array('coverage', 'find-tests', 'find-symbol', 'framework-docs',
                       'console-commands', 'api-docs', 'theme-info', 'pramnos-check') as $name) {
            $this->assertArrayNotHasKey(
                $name,
                McpServiceProvider::DIAGNOSTIC_SCOPES,
                $name . ' answers a question about the codebase, not about the installation'
            );
        }
    }

    /**
     * `$only` narrows the offer to exactly what was named.
     *
     * For an installation that wants the migration state visible and nothing else.
     */
    public function testOnlyNarrowsTheOffer(): void
    {
        // Act
        $offered = McpServiceProvider::offerDiagnostics(null, array('log-errors'));

        // Assert
        $this->assertSame(array('log-errors'), $offered);
        $this->assertCount(1, PublicRegistry::visibleTo(array('mcp:logs')));
    }

    /**
     * Every classified tool sits behind a scope a token can actually be issued for.
     *
     * The failure this prevents is silent from both ends: the tool is registered,
     * the endpoint serves it, and no token can be minted that reaches it because
     * `hasInvalidScopes()` rejects the scope. Asserted mechanically because it is a
     * relationship between two files that nothing else compares.
     */
    public function testEveryDiagnosticScopeIsGrantable(): void
    {
        // Arrange — offering is what registers the scopes, so only the tools actually
        // offered here have theirs; `mcp:db_read` needs a database and is covered by
        // ScopesTest, which offers `db-inspect` itself.
        $offered = McpServiceProvider::offerDiagnostics(null);
        $scopes  = array_unique(array_map(
            static fn (string $name): string => McpServiceProvider::DIAGNOSTIC_SCOPES[$name],
            $offered
        ));

        $this->assertNotSame(array(), $scopes, 'nothing was offered, so nothing was checked');

        // Act
        [$hasInvalid, $invalid] = Scopes::hasInvalidScopes(implode(' ', $scopes));

        // Assert
        $this->assertFalse(
            $hasInvalid,
            'offered behind scopes no token can carry: ' . implode(', ', $invalid)
        );
    }

    /**
     * The three groups are separate grants, and a token holding one does not reach
     * the next.
     *
     * This is the reason there are three rather than one. Reading the schema, reading
     * the logs and reading the rows disclose different things, and an installation
     * granting the first must not silently be granting the third.
     */
    public function testTheGroupsDoNotReachEachOther(): void
    {
        // Arrange
        McpServiceProvider::offerDiagnostics(null);

        // Act
        $logNames = array_map(
            static fn (ScopedMcpTool $t): string => $t->name(),
            PublicRegistry::visibleTo(array('mcp:logs'))
        );
        $structureNames = array_map(
            static fn (ScopedMcpTool $t): string => $t->name(),
            PublicRegistry::visibleTo(array('mcp:diagnostics'))
        );

        // Assert
        $this->assertContains('log-errors', $logNames);
        $this->assertNotContains('log-errors', $structureNames);
        $this->assertContains('model-inspect', $structureNames);
        $this->assertNotContains('model-inspect', $logNames);
        // and holding both still does not reach the rows
        $bothNames = array_map(
            static fn (ScopedMcpTool $t): string => $t->name(),
            PublicRegistry::visibleTo(array('mcp:logs', 'mcp:diagnostics'))
        );
        $this->assertNotContains('db-inspect', $bothNames);
    }

    /**
     * With an application, the readers that need one are offered too — including
     * `db-inspect`, which needs its database.
     *
     * The half of the catalogue a log-only installation never reaches, and the half
     * that carries the `mcp:db_read` scope with it.
     */
    public function testWithAnApplicationTheWholeCatalogueIsOffered(): void
    {
        // Arrange
        $app = $this->getMockBuilder(\Pramnos\Application\Application::class)
            ->disableOriginalConstructor()->getMock();
        $app->database = $this->createMock(\Pramnos\Database\Database::class);

        // Act
        $offered = McpServiceProvider::offerDiagnostics($app);

        // Assert — every classified tool, and nothing that is not classified
        $this->assertSame(
            array_keys(McpServiceProvider::DIAGNOSTIC_SCOPES),
            array_values(array_intersect(array_keys(McpServiceProvider::DIAGNOSTIC_SCOPES), $offered)),
            'the catalogue and what was offered have drifted apart'
        );
        $this->assertContains('status', $offered);
        $this->assertContains('db-inspect', $offered);
        $this->assertCount(1, PublicRegistry::visibleTo(array('mcp:db_read')));
    }

    /**
     * An application without a database still offers everything that does not need
     * one.
     *
     * The state a broken installation is in, which is when somebody is asking.
     */
    public function testAnApplicationWithNoDatabaseStillOffersTheRest(): void
    {
        // Arrange
        $app = $this->getMockBuilder(\Pramnos\Application\Application::class)
            ->disableOriginalConstructor()->getMock();
        $app->database = null;

        // Act
        $offered = McpServiceProvider::offerDiagnostics($app);

        // Assert
        $this->assertContains('status', $offered);
        $this->assertContains('migration-status', $offered);
        $this->assertNotContains('db-inspect', $offered);
        $this->assertNotContains('list-tables', $offered);
    }

    /**
     * A tool can be withdrawn by name, without taking the rest with it.
     *
     * `reset()` is the other door and it is the wrong one for an application that
     * wants one of the framework's tools gone: it would drop that application's own
     * registrations too, and the order in which providers boot is not something
     * anybody should have to reason about to remove a tool.
     */
    public function testAToolCanBeWithdrawnByName(): void
    {
        // Arrange
        McpServiceProvider::offerDiagnostics(null);
        $before = count(PublicRegistry::visibleTo(array('mcp:logs')));

        // Act
        $removed = PublicRegistry::remove('log-errors');

        // Assert
        $this->assertTrue($removed);
        $this->assertCount($before - 1, PublicRegistry::visibleTo(array('mcp:logs')));
        // and withdrawing something that was never there says so rather than throwing
        $this->assertFalse(PublicRegistry::remove('log-errors'));
        $this->assertFalse(PublicRegistry::remove('never-registered'));
    }

    /**
     * A token holding a child scope reaches a tool that asks for the parent.
     *
     * Resolved through `Scopes` rather than by a second rule here — the router
     * applies the same one, and two implementations of scope inheritance drift.
     * Concretely: `mcp:db_read` inherits `mcp`, so the token that can query the
     * database can also call `whoami` and find out what it is holding, which is the
     * first thing anybody does when a tool is missing from a list.
     */
    public function testAChildScopeReachesAToolAskingForItsParent(): void
    {
        // Arrange — offering db-inspect is what registers `mcp:db_read` at all
        $app = $this->getMockBuilder(\Pramnos\Application\Application::class)
            ->disableOriginalConstructor()->getMock();
        $app->database = $this->createMock(\Pramnos\Database\Database::class);
        McpServiceProvider::offerDiagnostics($app);
        PublicRegistry::add(new \Pramnos\Mcp\Tools\WhoAmITool());

        // Act — a token holding only the child
        $names = array_map(
            static fn (ScopedMcpTool $t): string => $t->name(),
            PublicRegistry::visibleTo(array('mcp:db_read'))
        );

        // Assert
        $this->assertContains('db-inspect', $names, 'the scope it asks for directly');
        $this->assertContains('whoami', $names, 'and `mcp`, reached by inheritance');
        // but not the sibling groups, which are not ancestors of this one
        $this->assertNotContains('log-errors', $names);
        $this->assertNotContains('status', $names);
    }

    /**
     * A caller with only the base scope sees none of them.
     *
     * `mcp` is what `whoami` needs, and it has to stay the scope that reaches nothing
     * else — otherwise the smoke test becomes the way in.
     */
    public function testTheBaseScopeReachesNoneOfThem(): void
    {
        // Arrange
        McpServiceProvider::offerDiagnostics(null);

        // Act
        $visible = PublicRegistry::visibleTo(array('mcp'));

        // Assert
        $this->assertSame(array(), $visible);
    }
}
