<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Mcp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Mcp\McpServer;
use Pramnos\Mcp\McpServiceProvider;

/**
 * An application part-way through migrating to this framework still gets an MCP
 * server.
 *
 * `registerDefaults()` asked `$app->database !== null` and then handed the
 * result to constructors typed against `Pramnos\Database\Database`. Those are
 * different questions, and an application carrying its own `Database` class —
 * the normal state of a project mid-migration, which is most of the audience —
 * passed the first and failed the second:
 *
 * ```
 * TypeError: ListTablesTool::__construct(): Argument #1 ($db) must be of type
 * Pramnos\Database\Database, App\Database\Database given
 * ```
 *
 * **And it did not cost the three database tools, it cost the server.** One call
 * registers all twenty-one, so the throw landed before anything was added:
 * `mcp:call status`, a tool that touches no database, exited 255 with nothing on
 * stdout or stderr.
 */
#[CoversClass(McpServiceProvider::class)]
class ForeignDatabaseTest extends TestCase
{
    /**
     * An application whose `database` is not this framework's class.
     */
    private function appWithForeignDatabase(): Application
    {
        $app = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()->getMock();

        // Deliberately not a Pramnos\Database\Database, and deliberately not null:
        // that combination is the whole of the bug.
        $app->database = new class {
            public bool $connected = true;
        };

        return $app;
    }

    /**
     * The eighteen tools that need no database are registered anyway.
     *
     * The assertion that matters is the count, not the absence: a fix that skipped
     * the database tools and also lost the rest would satisfy "no TypeError" and
     * leave the feature just as unusable.
     */
    public function testAForeignDatabaseCostsOnlyTheToolsThatNeedIt(): void
    {
        // Arrange
        $server = new McpServer('probe');

        // Act
        McpServiceProvider::registerDefaults($server, $this->appWithForeignDatabase());
        $names = array_map(
            static fn (object $t): string => $t->name(),
            $server->getTools()
        );

        // Assert — the ones that read a database are absent
        $this->assertNotContains('list-tables', $names);
        $this->assertNotContains('query-schema', $names);
        $this->assertNotContains('db-inspect', $names);

        // and everything else is there, which is the half that was being lost
        foreach (array('status', 'log-errors', 'framework-docs', 'find-symbol', 'route-list') as $tool) {
            $this->assertContains($tool, $names, $tool . ' was lost with the database tools');
        }
        $this->assertGreaterThan(10, count($names), 'the server came back nearly empty');
    }

    /**
     * With no database at all, the same thing happens — which is the case the code
     * always described and the one that already worked.
     *
     * Here as the control: without it, a `registerDefaults()` that registered
     * nothing at all would satisfy every assertion above.
     */
    public function testNoDatabaseIsStillTheDocumentedCase(): void
    {
        // Arrange
        $app = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()->getMock();
        $app->database = null;
        $server = new McpServer('probe');

        // Act
        McpServiceProvider::registerDefaults($server, $app);
        $names = array_map(static fn (object $t): string => $t->name(), $server->getTools());

        // Assert
        $this->assertNotContains('list-tables', $names);
        $this->assertContains('status', $names);
    }

    /**
     * A real connection still gets the database tools.
     *
     * The other control. A check that answered "no" to everything would pass both
     * tests above and remove three tools from every installation.
     */
    public function testTheFrameworksOwnDatabaseStillReachesThem(): void
    {
        // Arrange
        $app = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()->getMock();
        $app->database = $this->createMock(\Pramnos\Database\Database::class);
        $server = new McpServer('probe');

        // Act
        McpServiceProvider::registerDefaults($server, $app);
        $names = array_map(static fn (object $t): string => $t->name(), $server->getTools());

        // Assert
        $this->assertContains('list-tables', $names);
        $this->assertContains('query-schema', $names);
        $this->assertContains('db-inspect', $names);
    }
}
