<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console\Make;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Pramnos\Console\Application as ConsoleApplication;
use Pramnos\Console\Commands\Make\MakeController;
use Pramnos\Console\Commands\Make\MakeView;

/**
 * Unit tests for the `--full` (`-f`) option wiring on create:controller and
 * create:view.
 *
 * WHY this matters:
 *   The generators' underlying methods — createController($name, $full) and
 *   createView($name, $full) — have always supported a "full CRUD" mode (it is
 *   what create:crud invokes with $full = true). The documentation (README,
 *   Console guide) advertised a `--full` flag on the standalone commands, but
 *   the flag was never registered nor read: any `create:controller X --full`
 *   invocation aborted with "The --full option does not exist". These tests
 *   pin the wiring so the documented flag keeps flowing from the CLI down to
 *   the generation method.
 *
 * WHY the stub-subclass pattern:
 *   The real createController()/createView() perform database introspection and
 *   write files. We only need to assert that execute() forwards the parsed
 *   option value, so we override the generation method to capture the boolean
 *   it receives and return a fixed string — no database, no filesystem.
 */
#[CoversClass(MakeController::class)]
#[CoversClass(MakeView::class)]
class MakeFullOptionWiringTest extends TestCase
{
    private ConsoleApplication $consoleApp;

    protected function setUp(): void
    {
        // Some generation code paths read $_SERVER['PHP_SELF']; keep parity
        // with the sibling MakeDbCommandsTest setup so the harness is stable.
        if (!isset($_SERVER['PHP_SELF'])) {
            $_SERVER['PHP_SELF'] = 'phpunit';
        }

        // A console application that registers no real commands — we add the
        // command-under-test explicitly per case.
        $this->consoleApp = new class extends ConsoleApplication {
            protected function registerCommands(): void {}
        };
    }

    /**
     * Builds a MakeController whose createController() records the $full flag it
     * is handed instead of touching the database/filesystem.
     */
    private function makeControllerCommand(): MakeController
    {
        return new class extends MakeController {
            /** @var bool|null The $full value the command forwarded, null if never called. */
            public ?bool $capturedFull = null;
            protected function createController($name, $full = false, array $wizardColumns = [], array $wizardForeignKeys = [])
            {
                $this->capturedFull = $full;
                return 'Controller created.';
            }
        };
    }

    /**
     * Builds a MakeView whose createView() records the $full flag it is handed.
     */
    private function makeViewCommand(): MakeView
    {
        return new class extends MakeView {
            /** @var bool|null The $full value the command forwarded, null if never called. */
            public ?bool $capturedFull = null;
            protected function createView($name, $full = false)
            {
                $this->capturedFull = $full;
                return 'View created.';
            }
        };
    }

    /**
     * Without --full, create:controller must forward $full = false — i.e. the
     * default "bare controller" behaviour is preserved (backward compatibility).
     */
    public function testControllerDefaultsToNonFull(): void
    {
        // Arrange
        $command = $this->makeControllerCommand();
        $this->consoleApp->add($command);
        $tester = new CommandTester($this->consoleApp->find('create:controller'));

        // Act — no --full flag
        $exit = $tester->execute(['name' => 'Widget']);

        // Assert — command ran and passed the false default straight through
        $this->assertSame(0, $exit);
        $this->assertFalse($command->capturedFull, 'Absent --full must forward $full = false');
    }

    /**
     * With --full, create:controller must forward $full = true so the full CRUD
     * controller is generated — this is the wiring that was previously missing.
     */
    public function testControllerFullLongOption(): void
    {
        // Arrange
        $command = $this->makeControllerCommand();
        $this->consoleApp->add($command);
        $tester = new CommandTester($this->consoleApp->find('create:controller'));

        // Act — long form --full
        $exit = $tester->execute(['name' => 'Widget', '--full' => true]);

        // Assert
        $this->assertSame(0, $exit);
        $this->assertTrue($command->capturedFull, '--full must forward $full = true');
    }

    /**
     * The short alias -f must behave identically to --full. Proving the alias
     * guards against a future refactor that drops the shortcut.
     */
    public function testControllerFullShortAlias(): void
    {
        // Arrange
        $command = $this->makeControllerCommand();
        $this->consoleApp->add($command);
        $tester = new CommandTester($this->consoleApp->find('create:controller'));

        // Act — short form -f
        $exit = $tester->execute(['name' => 'Widget', '-f' => true]);

        // Assert
        $this->assertSame(0, $exit);
        $this->assertTrue($command->capturedFull, '-f must forward $full = true');
    }

    /**
     * Without --full, create:view must forward $full = false (bare view).
     */
    public function testViewDefaultsToNonFull(): void
    {
        // Arrange
        $command = $this->makeViewCommand();
        $this->consoleApp->add($command);
        $tester = new CommandTester($this->consoleApp->find('create:view'));

        // Act
        $exit = $tester->execute(['name' => 'Widget']);

        // Assert
        $this->assertSame(0, $exit);
        $this->assertFalse($command->capturedFull, 'Absent --full must forward $full = false');
    }

    /**
     * With --full, create:view must forward $full = true so the complete CRUD
     * view templates are generated.
     */
    public function testViewFullLongOption(): void
    {
        // Arrange
        $command = $this->makeViewCommand();
        $this->consoleApp->add($command);
        $tester = new CommandTester($this->consoleApp->find('create:view'));

        // Act
        $exit = $tester->execute(['name' => 'Widget', '--full' => true]);

        // Assert
        $this->assertSame(0, $exit);
        $this->assertTrue($command->capturedFull, '--full must forward $full = true');
    }
}
