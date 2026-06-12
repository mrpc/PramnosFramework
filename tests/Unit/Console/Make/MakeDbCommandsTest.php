<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console\Make;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Pramnos\Console\Application as ConsoleApplication;
use Pramnos\Console\Commands\Make\MakeApi;
use Pramnos\Console\Commands\Make\MakeCrud;
use Pramnos\Console\Commands\Make\MakeModel;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Unit tests for the database-aware Make commands: MakeApi, MakeCrud, MakeModel.
 *
 * These commands normally require a live database connection (they introspect
 * table columns). To cover their execute() bodies without a database, we use
 * anonymous subclasses that override the generation method with a stub return,
 * exercising lines 19-25 of each execute() without a database round-trip.
 *
 * WHY this pattern:
 *   execute() is the entry point enforced by PHPUnit coverage attribution.
 *   The tested invariants are: argument validation (no name → exception),
 *   happy-path delegation (name given → generation method called, output
 *   written, exit 0). The content of the generated file is tested separately
 *   in MakeCommandBase/MakeCommandGenerators tests.
 */
#[CoversClass(MakeApi::class)]
#[CoversClass(MakeCrud::class)]
#[CoversClass(MakeModel::class)]
class MakeDbCommandsTest extends TestCase
{
    private ConsoleApplication $consoleApp;
    private string $testId;

    protected function setUp(): void
    {
        if (!isset($_SERVER['PHP_SELF'])) {
            $_SERVER['PHP_SELF'] = 'phpunit';
        }

        $this->testId = bin2hex(random_bytes(4));

        $this->consoleApp = new class extends ConsoleApplication {
            protected function registerCommands(): void {}
        };

        $this->consoleApp->internalApplication = new class {
            public string $appName = '';
            public array $applicationInfo = ['namespace' => 'TestApp'];
            public $database = null;
            public function init(): void {}
        };
    }

    // ── MakeApi ───────────────────────────────────────────────────────────────

    /**
     * create:api execute() must exit 0 and write the generation output to
     * stdout when a name is provided. Uses a stub subclass that returns a
     * fixed string instead of querying a database.
     */
    public function testMakeApiExecuteHappyPath(): void
    {
        // Arrange — stub overrides createApi() to avoid a database call
        $command = new class extends MakeApi {
            protected function createApi($name): string
            {
                return 'Api created.';
            }
        };

        $this->consoleApp->add($command);
        $tester = new CommandTester($this->consoleApp->find('create:api'));

        // Act
        $exit = $tester->execute(['name' => 'ZzzApi' . $this->testId]);

        // Assert — exit 0, output contains success text
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Api created', $tester->getDisplay());
    }

    /**
     * create:api must throw InvalidArgumentException when name is missing.
     * Covers the early-exit guard on line 21-22 of MakeApi::execute().
     */
    public function testMakeApiThrowsWhenNameMissing(): void
    {
        // Arrange
        $command = new class extends MakeApi {
            protected function createApi($name): string { return ''; }
        };
        $this->consoleApp->add($command);
        $tester = new CommandTester($this->consoleApp->find('create:api'));

        // Assert + Act
        $this->expectException(\InvalidArgumentException::class);
        $tester->execute([]);
    }

    // ── MakeCrud ──────────────────────────────────────────────────────────────

    /**
     * create:crud execute() must exit 0 and write the generation output.
     */
    public function testMakeCrudExecuteHappyPath(): void
    {
        // Arrange — stub overrides createCrud() to avoid a database call
        $command = new class extends MakeCrud {
            public function createCrud($name): string
            {
                return "Creating Model: OK\nCreating Controller: OK\nCreating View: OK\n\n";
            }
        };

        $this->consoleApp->add($command);
        $tester = new CommandTester($this->consoleApp->find('create:crud'));

        // Act
        $exit = $tester->execute(['name' => 'ZzzCrud' . $this->testId]);

        // Assert
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('OK', $tester->getDisplay());
    }

    /**
     * create:crud must throw InvalidArgumentException when name is missing.
     */
    public function testMakeCrudThrowsWhenNameMissing(): void
    {
        // Arrange
        $command = new class extends MakeCrud {
            public function createCrud($name): string { return ''; }
        };
        $this->consoleApp->add($command);
        $tester = new CommandTester($this->consoleApp->find('create:crud'));

        // Assert + Act
        $this->expectException(\InvalidArgumentException::class);
        $tester->execute([]);
    }

    // ── MakeModel ─────────────────────────────────────────────────────────────

    /**
     * create:model execute() must exit 0 and write the generation output.
     */
    public function testMakeModelExecuteHappyPath(): void
    {
        // Arrange — stub overrides createModel() to avoid a database call
        $command = new class extends MakeModel {
            protected function createModel($name, array $wizardColumns = [], array $wizardForeignKeys = []): string
            {
                return 'Model created.';
            }
        };

        $this->consoleApp->add($command);
        $tester = new CommandTester($this->consoleApp->find('create:model'));

        // Act
        $exit = $tester->execute(['name' => 'ZzzModel' . $this->testId]);

        // Assert
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Model created', $tester->getDisplay());
    }

    /**
     * create:model must throw InvalidArgumentException when name is missing.
     */
    public function testMakeModelThrowsWhenNameMissing(): void
    {
        // Arrange
        $command = new class extends MakeModel {
            protected function createModel($name, array $wizardColumns = [], array $wizardForeignKeys = []): string
            {
                return '';
            }
        };
        $this->consoleApp->add($command);
        $tester = new CommandTester($this->consoleApp->find('create:model'));

        // Assert + Act
        $this->expectException(\InvalidArgumentException::class);
        $tester->execute([]);
    }
}
