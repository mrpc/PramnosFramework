<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console\Commands;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\MigrateRefresh;
use Pramnos\Database\Database;
use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unit tests for the MigrateRefresh console command.
 *
 * MigrateRefresh rolls back all migrations and then re-runs them from scratch.
 * The three test categories here are:
 *
 * 1. Guard tests — wrong application type or missing database.
 * 2. configure() coverage — verify all expected options are registered.
 * 3. --force / abort path — the confirmation prompt is skipped with --force;
 *    without it the command should abort when the user answers "N".
 *
 * Real migration execution (rollbackAll + run) is not tested at the unit level
 * because it requires a live database. That coverage is provided by the
 * Integration test suite.
 */
#[CoversClass(MigrateRefresh::class)]
class MigrateRefreshTest extends TestCase
{
    protected function setUp(): void
    {
        // Symfony's DumpCompletionCommand reads $_SERVER['PHP_SELF'] at configure()
        if (!isset($_SERVER['PHP_SELF'])) {
            $_SERVER['PHP_SELF'] = 'phpunit';
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Build a CommandTester for MigrateRefresh wired to a lightweight stub of
     * \Pramnos\Console\Application. The internalApplication->database is set
     * to $db (null = no DB, triggers the no-database guard).
     */
    private function makeTester(?Database $db = null): CommandTester
    {
        $consoleApp = new class('Test', '0.0') extends \Pramnos\Console\Application {
            public function __construct(string $name, string $version)
            {
                // Bypass the heavy parent constructor (registerCommands, getInstance, etc.)
                \Symfony\Component\Console\Application::__construct($name, $version);
                // MUST extend Application for MigrationLoader type-check.
                $this->internalApplication = new class extends \Pramnos\Application\Application {
                    /** @var \Pramnos\Database\Database|null */
                    public $database = null;
                    public function __construct() {}
                };
            }
        };

        if ($db !== null) {
            $consoleApp->internalApplication->database = $db;
        }

        $command = new MigrateRefresh();
        $consoleApp->add($command);
        $command = $consoleApp->find('migrate:refresh');

        return new CommandTester($command);
    }

    // =========================================================================
    // Guard: non-Pramnos application
    // =========================================================================

    /**
     * When executed inside a plain Symfony Application (not a Pramnos Console
     * Application) execute() must return exit code 1 and print an error.
     *
     * Verifies the `instanceof \Pramnos\Console\Application` guard.
     */
    public function testNonPramnosApplicationReturnsError(): void
    {
        // Arrange — register in a plain Symfony application
        $symfonyApp = new SymfonyApplication('Test', '0.0');
        $command    = new MigrateRefresh();
        $symfonyApp->add($command);
        $tester = new CommandTester($symfonyApp->find('migrate:refresh'));

        // Act
        $code = $tester->execute([]);

        // Assert
        $this->assertSame(1, $code, 'Guard must fire for non-Pramnos application');
        $this->assertStringContainsString('Pramnos console application', $tester->getDisplay());
    }

    // =========================================================================
    // Guard: no database connection
    // =========================================================================

    /**
     * When the application has no database (database === null) execute() must
     * return exit code 1 with an error message about the missing connection.
     *
     * Verifies the `$db === null` guard.
     */
    public function testNoDatabaseConnectionReturnsError(): void
    {
        // Arrange — valid Pramnos app, database is null
        $tester = $this->makeTester(null);

        // Act
        $code = $tester->execute([]);

        // Assert
        $this->assertSame(1, $code, 'No-database guard must return exit code 1');
        $this->assertStringContainsString('No database connection', $tester->getDisplay());
    }

    // =========================================================================
    // configure() — command metadata
    // =========================================================================

    /**
     * The command must be named 'migrate:refresh' and declare both expected
     * options (--force and --path).
     */
    public function testCommandIsConfiguredCorrectly(): void
    {
        // Arrange
        $command = new MigrateRefresh();
        $def     = $command->getDefinition();

        // Assert — name
        $this->assertSame('migrate:refresh', $command->getName());

        // Assert — options
        $this->assertTrue($def->hasOption('force'), '--force option must be defined');
        $this->assertTrue($def->hasOption('path'),  '--path option must be defined');
    }

    /**
     * The --force option must be VALUE_NONE (a simple flag without a value).
     * Passing -f must not consume the next argument as a value.
     */
    public function testForceOptionIsValueNone(): void
    {
        // Arrange
        $command = new MigrateRefresh();
        $def     = $command->getDefinition();
        $option  = $def->getOption('force');

        // Assert — Symfony 5.x: VALUE_NONE is neither required nor optional-value
        $this->assertFalse($option->isValueRequired(),
            '--force must not require a value');
        $this->assertFalse($option->isValueOptional(),
            '--force must not be VALUE_OPTIONAL');
    }

    /**
     * The --path option must be VALUE_REQUIRED so callers can supply a custom
     * migrations directory.
     */
    public function testPathOptionIsValueRequired(): void
    {
        // Arrange
        $command = new MigrateRefresh();
        $def     = $command->getDefinition();

        // Assert
        $this->assertTrue(
            $def->getOption('path')->isValueRequired(),
            '--path must be VALUE_REQUIRED'
        );
    }

    // =========================================================================
    // Abort without --force
    // =========================================================================

    /**
     * When --force is NOT passed and the user answers "N" to the confirmation
     * prompt, execute() must exit 0 and print "Aborted." without touching
     * any migrations.
     *
     * This covers the `!$input->getOption('force')` branch and the
     * `$helper->ask()` false-return path.
     *
     * CommandTester's `setInputs(['n'])` simulates the user typing "n" + Enter.
     */
    public function testAbortWhenUserAnswersNoToConfirmation(): void
    {
        // Arrange — a valid DB mock so we pass the DB guard
        // (abort happens before any DB interaction so schema() is not needed here)
        $db = $this->createMock(Database::class);
        $db->type = 'mysql';
        $tester   = $this->makeTester($db);

        // Act — answer "n" to the confirmation prompt
        $tester->setInputs(['n']);
        $code = $tester->execute([]);

        // Assert — aborted cleanly
        $this->assertSame(0, $code, 'Answering N must exit 0 (not an error)');
        $this->assertStringContainsString('Aborted', $tester->getDisplay());
    }

    /**
     * When the user answers a blank string (just pressing Enter) to the
     * confirmation prompt the default answer is "no" (the second argument to
     * ConfirmationQuestion is false), so it should also abort.
     */
    public function testAbortOnEmptyInputToConfirmation(): void
    {
        // Arrange — abort happens before any DB call; no schema() stub needed
        $db = $this->createMock(Database::class);
        $db->type = 'mysql';
        $tester   = $this->makeTester($db);

        // Act — simulate pressing Enter without typing anything
        $tester->setInputs(['']);
        $code = $tester->execute([]);

        // Assert — default is "No" so it aborts
        $this->assertSame(0, $code, 'Empty input must default to abort (exit 0)');
        $this->assertStringContainsString('Aborted', $tester->getDisplay());
    }

    // =========================================================================
    // --force skips the confirmation prompt
    // =========================================================================

    /**
     * When --force is provided the confirmation prompt must be skipped entirely
     * and execution must proceed past it (to the MigrationLoader call).
     *
     * Since we have a mock DB that does not implement the real query interface,
     * MigrationLoader::loadFromDirectory() will be called on the default path
     * (app/Migrations in the test environment), which will likely be empty or
     * not exist — either way execute() must not crash from the prompt code.
     *
     * We just verify it did NOT output "Aborted."
     */
    public function testForceSkipsConfirmationPrompt(): void
    {
        // Arrange — DB mock that survives rollbackAll + run (both call ensureHistoryTable)
        $db = $this->createMock(Database::class);
        $db->type = 'mysql';

        $result = new class {
            public array $fields = [];
            public function fetch(): bool { return false; }
        };
        $db->method('query')->willReturn($result);

        $schema = new class {
            public function hasColumn(string $table, string $col): bool { return true; }
        };
        $db->method('schema')->willReturn($schema);
        // MigrationLoader tries to load from a path; provide a real (empty) tmp dir
        $tmpPath = sys_get_temp_dir() . '/pramnos_refresh_force_' . bin2hex(random_bytes(4));
        mkdir($tmpPath, 0777, true);

        $tester = $this->makeTester($db);

        try {
            // Act — --force + explicit empty path so no real migrations are discovered
            $tester->execute([
                '--force' => true,
                '--path'  => $tmpPath,
            ]);
        } catch (\Throwable $e) {
            // A DB-level exception is acceptable here (we have a mock DB); what matters
            // is that "Aborted." was NOT written before any error occurred.
        } finally {
            rmdir($tmpPath);
        }

        // Assert — confirmation prompt was skipped (no "Aborted." in output)
        $this->assertStringNotContainsString('Aborted', $tester->getDisplay(),
            '--force must bypass the confirmation prompt');
    }

    // =========================================================================
    // defaultMigrationPath() — private method via reflection
    // =========================================================================

    /**
     * The private defaultMigrationPath() method must return a path containing
     * 'app' and 'Migrations', using ROOT or getcwd() as the base.
     *
     * This covers the path-building logic without a full execution cycle.
     */
    public function testDefaultMigrationPathContainsAppMigrations(): void
    {
        // Arrange
        $command = new MigrateRefresh();
        $method  = new \ReflectionMethod($command, 'defaultMigrationPath');

        // Act
        $path = $method->invoke($command);

        // Assert — must contain the expected path components
        $this->assertStringContainsString('app', $path,
            'defaultMigrationPath() must include "app" directory');
        $this->assertStringContainsString('Migrations', $path,
            'defaultMigrationPath() must include "Migrations" directory');
    }
}
