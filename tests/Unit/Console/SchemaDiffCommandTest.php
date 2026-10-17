<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\SchemaDiffCommand;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `schema:diff` without what it needs says so, and finds its application where a console
 * command does.
 */
#[CoversClass(SchemaDiffCommand::class)]
final class SchemaDiffCommandTest extends TestCase
{
    /** No database on the application: the command fails and says why, building nothing. */
    public function testWithoutADatabaseItSaysSo(): void
    {
        // Arrange
        $app = $this->getMockBuilder(\Pramnos\Application\Application::class)->disableOriginalConstructor()->getMock();
        $app->database = null;
        $command = new class ($app) extends SchemaDiffCommand {
            public function __construct(private \Pramnos\Application\Application $app)
            {
                parent::__construct();
            }

            /** The application without a database. */
            protected function application(): ?\Pramnos\Application\Application
            {
                return $this->app;
            }
        };
        $console = new ConsoleApplication();
        $console->add($command);
        $tester = new CommandTester($console->find('schema:diff'));

        // Act
        $code = $tester->execute([]);

        // Assert
        $this->assertSame(1, $code);
        $this->assertStringContainsString('No database connection available.', $tester->getDisplay());
    }

    /**
     * Under a plain console its application is the current one; under the framework's console,
     * the one that console was built for.
     */
    public function testItFindsItsApplication(): void
    {
        // Arrange
        $command = new SchemaDiffCommand();
        $read    = new \ReflectionMethod($command, 'application');
        (new ConsoleApplication())->add($command);
        $plain = $read->invoke($command);
        $framework = new class () extends ConsoleApplication {
            public $internalApplication;
        };
        $framework->internalApplication = $this->getMockBuilder(\Pramnos\Application\Application::class)->disableOriginalConstructor()->getMock();
        $framework->add($command);

        // Act
        $own = $read->invoke($command);

        // Assert
        $this->assertSame(\Pramnos\Application\Application::currentInstance(), $plain);
        $this->assertSame($framework->internalApplication, $own);
    }
}
