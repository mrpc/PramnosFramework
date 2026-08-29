<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Mcp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Framework\Factory;
use Pramnos\Mcp\Tools\RequestDebugTool;
use Pramnos\Mcp\Tools\SchemaDriftTool;
use Pramnos\Mcp\Tools\StatusTool;

/**
 * The two diagnostic tools against a real database and the real migration files.
 *
 * Everything they say is a claim about *this installation*, so a test that supplied both sides
 * would be testing its own fixtures. What is asserted here is the part only a live run can
 * check: that the readers find something at all, that the shapes are the ones the tools
 * promise, and that nothing throws on a database that is simply there.
 */
#[CoversClass(StatusTool::class)]
#[CoversClass(SchemaDriftTool::class)]
#[CoversClass(RequestDebugTool::class)]
class DiagnosticToolsTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        Settings::loadSettings(ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php');

        $db = Factory::getDatabase();

        if (!$db->connected) {
            $db->connect(true);
        }

        /** @var Application&\PHPUnit\Framework\MockObject\MockObject $app */
        $app = $this->getMockBuilder(Application::class)->disableOriginalConstructor()->getMock();
        $app->database = $db;
        $this->app     = $app;

        // `users` is the one table every installation has, and the assertions below rely on it
        // being there — which in a shared test database depends on whichever class ran last.
        \Pramnos\User\User::setupDb();
    }

    /**
     * `status` answers all five sections against a real installation.
     *
     * The point of the tool is that it is one call rather than four, so a section that quietly
     * fails to answer is the failure — and each of them reads something different: a
     * connection, the migration history, a table that may not exist, the health registry, and
     * the log directory.
     */
    public function testStatusAnswersEverySection(): void
    {
        // Act
        $status = (new StatusTool($this->app))->execute([]);

        // Assert
        $this->assertTrue($status['database']['connected']);
        $this->assertArrayHasKey('applied', $status['migrations']);
        $this->assertArrayHasKey('pending', $status['migrations']);
        $this->assertArrayHasKey('enabled', $status['queue']);
        $this->assertArrayHasKey('checks', $status['health']);
        $this->assertIsArray($status['errors']);
        $this->assertNotSame('', $status['verdict']);
    }

    /**
     * A missing queue table is "no queue here", not an error.
     *
     * `queueitems` exists only in an application with the queue feature, and a tool that
     * reported its absence as a fault would be reporting a fault on most installations.
     */
    public function testAMissingQueueTableIsNotAFault(): void
    {
        // Arrange
        Factory::getDatabase()->query('DROP TABLE IF EXISTS `queueitems`');

        // Act
        $status = (new StatusTool($this->app))->execute([]);

        // Assert
        $this->assertFalse($status['queue']['enabled']);
        $this->assertStringNotContainsString('queue', $status['verdict']);
    }

    /**
     * `schema-drift` reads the real migration files and the real database.
     *
     * The declared side is dozens of tables across two scopes; finding a handful means the
     * directory resolution is wrong, and finding none means the token scan is.
     */
    public function testSchemaDriftReadsBothSides(): void
    {
        // Act
        $answer = (new SchemaDriftTool($this->app))->execute([]);

        // Assert
        $this->assertGreaterThan(20, $answer['declared_tables'],
            'the framework ships dozens of migrations');
        $this->assertGreaterThan(0, $answer['live_tables']);
        $this->assertNotSame('', $answer['verdict']);
    }

    /**
     * The `users` table is created by a migration, and that migration has run.
     *
     * The one table every installation of this framework has, so the answer is knowable: if
     * this reports anything else, the slug matching or the name normalisation is wrong.
     */
    public function testTheUsersTableIsAccountedFor(): void
    {
        // Act
        $answer = (new SchemaDriftTool($this->app))->execute(['table' => 'users']);

        // Assert
        $this->assertTrue($answer['exists']);
        $this->assertNotSame([], $answer['migrations'],
            'a migration creates it — if not, the token scan missed a createTable()');
    }

    /**
     * A table nobody has ever heard of is reported as exactly that.
     */
    public function testATableNothingKnowsAboutIsReportedAsSuch(): void
    {
        // Act
        $answer = (new SchemaDriftTool($this->app))->execute(['table' => 'no_such_table_anywhere']);

        // Assert
        $this->assertFalse($answer['exists']);
        $this->assertSame([], $answer['migrations']);
        $this->assertStringContainsString('does not have', $answer['verdict']);
    }

    /**
     * Each tool describes itself, and the descriptions are what a caller chooses between.
     *
     * A tool nobody calls is a tool that does not exist, and the description is the entire
     * basis on which it is picked out of a list of sixteen.
     */
    public function testEachToolSaysWhatItIsFor(): void
    {
        foreach ([
            new StatusTool($this->app),
            new SchemaDriftTool($this->app),
            new RequestDebugTool(),
        ] as $tool) {
            // Assert
            $this->assertNotSame('', $tool->name());
            $this->assertGreaterThan(60, strlen($tool->description()), $tool->name());
            $this->assertSame('object', $tool->inputSchema()['type'], $tool->name());
        }
    }

    /**
     * `request-debug` describes every option it accepts.
     *
     * An option a caller cannot see is an option nobody uses, and the listing mode is the one
     * that has to be discoverable — it is the mode somebody needs when they have no id.
     */
    public function testRequestDebugDescribesItsOptions(): void
    {
        // Act
        $schema = (new RequestDebugTool())->inputSchema();

        // Assert
        $this->assertSame(['request', 'level', 'limit'], array_keys($schema['properties']));

        foreach ($schema['properties'] as $name => $property) {
            $this->assertNotSame('', $property['description'] ?? '', $name);
        }
    }
}
