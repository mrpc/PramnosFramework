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
 * Unit tests for the `--full` (`-f`) option wiring on create:view.
 *
 * WHY this matters:
 *   create:view's underlying method — createView($name, $full) — supports a
 *   "full CRUD" mode (it is what create:crud invokes with $full = true). The
 *   documentation advertised a `--full` flag on the standalone command; these
 *   tests pin the wiring so the documented flag keeps flowing from the CLI down
 *   to the generation method.
 *
 *   NOTE: create:controller no longer has a `--full` flag. It ALWAYS generates
 *   a full CRUD controller from the table schema (the simple-skeleton mode was
 *   removed), so there is nothing to wire.
 *
 * WHY the stub-subclass pattern:
 *   The real createView() performs database introspection and writes files. We
 *   only need to assert that execute() forwards the parsed option value, so we
 *   override the generation method to capture the boolean it receives and
 *   return a fixed string — no database, no filesystem.
 */
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
     * create:controller no longer exposes a `--full` option — it always
     * generates a full CRUD controller. Passing `--full` must therefore be
     * rejected by the input definition (the option does not exist).
     */
    public function testControllerHasNoFullOption(): void
    {
        // Arrange
        $command = new MakeController();
        $this->consoleApp->add($command);
        $tester = new CommandTester($this->consoleApp->find('create:controller'));

        // Assert — the removed option is unknown to the command definition
        $this->expectException(\Symfony\Component\Console\Exception\InvalidOptionException::class);

        // Act — --full must no longer be accepted
        $tester->execute(['name' => 'Widget', '--full' => true]);
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
