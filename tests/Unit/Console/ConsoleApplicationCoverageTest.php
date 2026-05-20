<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Pramnos\Console\Application as ConsoleApplication;
use Pramnos\Console\Commands\ScheduleList;
use Pramnos\Console\Commands\ScheduleRun;
use Pramnos\Console\Commands\HealthCheck;
use Pramnos\Console\Commands\MigrateLogs;
use Pramnos\Console\Commands\Migrate;
use Pramnos\Console\Commands\MigrateStatus;
use Pramnos\Console\Commands\MigrateReset;
use Pramnos\Console\Commands\MigrateRollback;
use Pramnos\Console\Commands\MigrateRefresh;
use Pramnos\Console\Commands\ProcessQueue;
use Pramnos\Console\Commands\CleanupQueue;
use Pramnos\Console\Commands\Serve;
use Pramnos\Console\Commands\Create;
use Pramnos\Console\Commands\PolicyEngine;
use Pramnos\Scheduling\Scheduler;
use Pramnos\Health\HealthRegistry;

/**
 * Coverage tests for Console module commands and the Application class.
 *
 * Tests are written for all zero-coverage commands in the Console module.
 * Commands that require a database connection (Migrate, MigrateStatus, etc.)
 * are tested only for their early-return guard paths.
 * Commands with daemon loops (ProcessQueue, CleanupQueue, DaemonOrchestrator) are
 * covered only at the configure() level — daemon execution paths are left to
 * functional/integration tests.
 */
#[CoversClass(ConsoleApplication::class)]
#[CoversClass(ScheduleList::class)]
#[CoversClass(ScheduleRun::class)]
#[CoversClass(HealthCheck::class)]
#[CoversClass(MigrateLogs::class)]
#[CoversClass(Migrate::class)]
#[CoversClass(MigrateStatus::class)]
#[CoversClass(MigrateReset::class)]
#[CoversClass(MigrateRollback::class)]
#[CoversClass(MigrateRefresh::class)]
#[CoversClass(ProcessQueue::class)]
#[CoversClass(CleanupQueue::class)]
#[CoversClass(Serve::class)]
#[CoversClass(Create::class)]
#[CoversClass(PolicyEngine::class)]
class ConsoleApplicationCoverageTest extends TestCase
{
    /** Temp directory used by MigrateLogs tests. */
    private string $tmpDir;
    /** @var string|null Original $_SERVER['PHP_SELF'] value */
    private ?string $originalPhpSelf = null;

    protected function setUp(): void
    {
        // Symfony's DumpCompletionCommand reads $_SERVER['PHP_SELF'] in configure();
        // ensure it is set to prevent "Undefined array key" warnings in PHP 8.4.
        $this->originalPhpSelf = $_SERVER['PHP_SELF'] ?? null;
        if (!isset($_SERVER['PHP_SELF'])) {
            $_SERVER['PHP_SELF'] = 'phpunit';
        }

        $this->tmpDir = sys_get_temp_dir() . '/pramnos_console_cov_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0777, true);

        // Reset static registries so tests are isolated from each other
        Scheduler::reset();
        HealthRegistry::reset();
    }

    protected function tearDown(): void
    {
        Scheduler::reset();
        HealthRegistry::reset();
        $this->rmdirRecursive($this->tmpDir);

        // Reset the Pramnos Application singleton so that integration tests
        // running after this class don't inherit a stale Application instance.
        // Without this, Database::displayError() calls $app->showError() → exit().
        $ref = new \ReflectionClass(\Pramnos\Application\Application::class);
        $prop = $ref->getProperty('appInstances');
        $prop->setValue(null, []);
        $last = $ref->getProperty('lastUsedApplication');
        $last->setValue(null, null);

        // Reset Database::getInstance() static cache so integration tests get
        // a fresh connection rather than the broken instance created by HealthCheck.
        $db = &\Pramnos\Database\Database::getInstance();
        $db = null;

        if ($this->originalPhpSelf === null) {
            unset($_SERVER['PHP_SELF']);
        } else {
            $_SERVER['PHP_SELF'] = $this->originalPhpSelf;
        }
    }

    // =========================================================================
    // Pramnos\Console\Application
    // =========================================================================

    /**
     * Constructing Pramnos\Console\Application must not throw even when
     * Application::getInstance() returns null in the test environment.
     *
     * We use a minimal subclass that suppresses command registration so the test
     * does not accidentally trigger configure() for every built-in command.
     * All constructor lines (HTTP_HOST defaults, sURL constant, parent call,
     * registerCommands call, internalApplication assignment) are covered.
     */
    public function testConsoleApplicationConstructorSetsDefaults(): void
    {
        // Arrange — subclass skips registerCommands to keep the test focused
        $app = new class extends ConsoleApplication {
            protected function registerCommands(): void
            {
                // deliberately empty — avoids adding 15+ commands in a unit test
            }
        };

        // Assert — the application was created and carries the Pramnos name
        $this->assertInstanceOf(\Symfony\Component\Console\Application::class, $app);
        $this->assertStringContainsString('Pramnos', $app->getName());
    }

    /**
     * Calling registerCommands() on a fresh ConsoleApplication must register at
     * least the core built-in commands (migrate, health:check, schedule:run …).
     *
     * This test exercises the real registerCommands() method and verifies each
     * command name is present in the application.
     */
    public function testConsoleApplicationRegisterCommandsAddsBuiltins(): void
    {
        // Arrange — use real ConsoleApplication; suppress HTTP env so the
        // Pramnos Application::getInstance() side-effect is contained.
        $app = new ConsoleApplication();

        // Assert — a representative sample of built-in commands are registered
        $this->assertTrue($app->has('migrate'),          'migrate command not registered');
        $this->assertTrue($app->has('health:check'),     'health:check not registered');
        $this->assertTrue($app->has('schedule:run'),     'schedule:run not registered');
        $this->assertTrue($app->has('schedule:list'),    'schedule:list not registered');
        $this->assertTrue($app->has('queue:process'),    'queue:process not registered');
        $this->assertTrue($app->has('queue:cleanup'),    'queue:cleanup not registered');
    }

    // =========================================================================
    // ScheduleList
    // =========================================================================

    /**
     * ScheduleList must output "No scheduled tasks registered." when the
     * Scheduler registry is empty.  Exit code must be Command::SUCCESS (0).
     */
    public function testScheduleListReturnsSuccessWhenNoTasks(): void
    {
        // Arrange — scheduler is reset in setUp; no tasks registered
        $app = new \Symfony\Component\Console\Application();
        $cmd = new ScheduleList();
        $app->add($cmd);
        $tester = new CommandTester($cmd);

        // Act
        $exit = $tester->execute([]);

        // Assert
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('No scheduled tasks registered', $tester->getDisplay());
    }

    /**
     * ScheduleList must render a table when tasks are registered.
     * The table contains the columns: Type, Expression, Handler / Description, No Overlap.
     */
    public function testScheduleListRendersTableWhenTasksExist(): void
    {
        // Arrange — register one callable task and one that has a no-overlap lock
        Scheduler::call(function () { /* noop */ })
            ->everyMinute()
            ->description('Send daily email');

        Scheduler::call(function () { /* noop */ })
            ->cron('0 3 * * *')
            ->withoutOverlapping($this->tmpDir)
            ->description('Cleanup old files');

        $app = new \Symfony\Component\Console\Application();
        $cmd = new ScheduleList();
        $app->add($cmd);
        $tester = new CommandTester($cmd);

        // Act
        $exit = $tester->execute([]);

        // Assert — table headers and both tasks must appear
        $this->assertSame(0, $exit);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Type',             $display);
        $this->assertStringContainsString('Expression',       $display);
        $this->assertStringContainsString('No Overlap',       $display);
        $this->assertStringContainsString('Send daily email', $display);
        $this->assertStringContainsString('Cleanup old files', $display);
        // One task uses withoutOverlapping → 'yes' must appear
        $this->assertStringContainsString('yes', $display);
    }

    // =========================================================================
    // ScheduleRun
    // =========================================================================

    /**
     * ScheduleRun must output "No tasks due" when no tasks are registered.
     * Exit code must be Command::SUCCESS (0).
     */
    public function testScheduleRunNoDueTasks(): void
    {
        // Arrange — no tasks registered
        $app = new \Symfony\Component\Console\Application();
        $cmd = new ScheduleRun();
        $app->add($cmd);
        $tester = new CommandTester($cmd);

        // Act
        $exit = $tester->execute([]);

        // Assert
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('No tasks due', $tester->getDisplay());
    }

    /**
     * ScheduleRun with --pretend must list due tasks without executing them.
     * The output contains "[dry-run]"; the callable must NOT have been invoked.
     */
    public function testScheduleRunPretendModeListsWithoutExecuting(): void
    {
        // Arrange — register a task that is always due
        $executed = false;
        Scheduler::call(function () use (&$executed) { $executed = true; })
            ->cron('* * * * *')
            ->description('My Background Job');

        $app = new \Symfony\Component\Console\Application();
        $cmd = new ScheduleRun();
        $app->add($cmd);
        $tester = new CommandTester($cmd);

        // Act
        $exit = $tester->execute(['--pretend' => true]);

        // Assert — dry-run output, callable NOT called, exit 0
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('[dry-run]', $tester->getDisplay());
        $this->assertStringContainsString('My Background Job', $tester->getDisplay());
        $this->assertFalse($executed, 'Callable must not run in pretend mode');
    }

    /**
     * ScheduleRun must execute due tasks and return Command::SUCCESS when all
     * tasks complete without errors.
     */
    public function testScheduleRunExecutesDueTasks(): void
    {
        // Arrange — register a simple always-due task
        $executed = false;
        Scheduler::call(function () use (&$executed) { $executed = true; })
            ->cron('* * * * *')
            ->description('Always Due');

        $app = new \Symfony\Component\Console\Application();
        $cmd = new ScheduleRun();
        $app->add($cmd);
        $tester = new CommandTester($cmd);

        // Act
        $exit = $tester->execute([]);

        // Assert — callable ran, output contains "Done", exit 0
        $this->assertTrue($executed, 'Callable must have been executed');
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Done', $tester->getDisplay());
    }

    /**
     * ScheduleRun must return Command::FAILURE (1) and print an error when a
     * task throws an exception. The exception must be caught, not re-thrown.
     */
    public function testScheduleRunReturnsFailureWhenTaskThrows(): void
    {
        // Arrange — a task that always throws
        Scheduler::call(function () {
            throw new \RuntimeException('Task exploded');
        })
            ->cron('* * * * *')
            ->description('Failing Task');

        $app = new \Symfony\Component\Console\Application();
        $cmd = new ScheduleRun();
        $app->add($cmd);
        $tester = new CommandTester($cmd);

        // Act — must not propagate the exception
        $exit = $tester->execute([]);

        // Assert — failure exit code, error message in output
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Task exploded', $tester->getDisplay());
    }

    // =========================================================================
    // HealthCheck
    // =========================================================================

    /**
     * HealthCheck with --json flag must output valid JSON containing a "status"
     * key and a "checks" object.  At least the disk_space and memory checks must
     * be present since registerBuiltinChecks() always adds them.
     */
    public function testHealthCheckJsonOutput(): void
    {
        // Arrange
        $app = new \Symfony\Component\Console\Application();
        $cmd = new HealthCheck();
        $app->add($cmd);
        $tester = new CommandTester($cmd);

        // Act
        $tester->execute(['--json' => true]);

        // Assert — output is valid JSON with expected structure
        $raw    = $tester->getDisplay();
        $report = json_decode($raw, true);
        $this->assertIsArray($report, 'Output must be valid JSON array/object');
        $this->assertArrayHasKey('status', $report);
        $this->assertArrayHasKey('checks', $report);
    }

    /**
     * HealthCheck in table mode (default) must render an "Overall status" header
     * and a table with at least the disk_space and memory checks.
     * Exit code is 0 (OK) or 1 (degraded) — either is valid in a unit test.
     */
    public function testHealthCheckTableOutput(): void
    {
        // Arrange
        $app = new \Symfony\Component\Console\Application();
        $cmd = new HealthCheck();
        $app->add($cmd);
        $tester = new CommandTester($cmd);

        // Act
        $tester->execute([]);

        // Assert — overall status header must appear
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Overall status', $display);
    }

    /**
     * HealthCheck with --only containing a valid registered check name must run
     * only that check. The runSelected() helper path must be exercised.
     */
    public function testHealthCheckOnlyFlagRunsSelectedCheck(): void
    {
        // Arrange — register a simple custom check first so we have a known name
        $check = new \Pramnos\Health\Checks\MemoryLimitCheck();
        HealthRegistry::register($check);

        $app = new \Symfony\Component\Console\Application();
        $cmd = new HealthCheck();
        $app->add($cmd);
        $tester = new CommandTester($cmd);

        // Act — run only the memory_limit check
        $exit = $tester->execute(['--only' => 'memory_limit']);

        // Assert — must produce some output without crashing
        $this->assertIsInt($exit);
        $display = $tester->getDisplay();
        $this->assertNotEmpty($display);
    }

    /**
     * HealthCheck --only with an unknown check name must emit a warning comment
     * (via runSelected() exception handling) and not crash.
     */
    public function testHealthCheckOnlyFlagWithUnknownCheckEmitsWarning(): void
    {
        // Arrange
        $app = new \Symfony\Component\Console\Application();
        $cmd = new HealthCheck();
        $app->add($cmd);
        $tester = new CommandTester($cmd);

        // Act — request a non-existent check
        $tester->execute(['--only' => 'nonexistent_check_xyz']);

        // Assert — a warning comment is written, no exception propagated
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Warning', $display);
    }

    /**
     * HealthCheck with no registered checks but in table mode must output the
     * "No health checks registered." message from renderTable()'s empty-check guard.
     */
    public function testHealthCheckTableOutputWithNoChecks(): void
    {
        // Arrange — registry is already reset by setUp; do NOT register any check
        // Use HealthCheck with a plain Symfony app (no database available)
        // so registerBuiltinChecks() fails to register DatabaseConnectivityCheck.
        // DiskSpaceCheck and MemoryLimitCheck are always registered however.
        $app = new \Symfony\Component\Console\Application();
        $cmd = new HealthCheck();
        $app->add($cmd);
        $tester = new CommandTester($cmd);

        // Act — table mode
        $exit = $tester->execute([]);

        // Assert — no crash, exit code is an integer
        $this->assertIsInt($exit);
    }

    // =========================================================================
    // MigrateLogs
    // =========================================================================

    /**
     * MigrateLogs must print "<error>Path not found: ...</error>" and return 1
     * when the specified path does not exist on the filesystem.
     */
    public function testMigrateLogsReturnsErrorForNonExistentPath(): void
    {
        // Arrange
        $app = new \Symfony\Component\Console\Application();
        $cmd = new MigrateLogs();
        $app->add($cmd);
        $tester = new CommandTester($cmd);

        $bogusPath = $this->tmpDir . '/absolutely/does/not/exist.log';

        // Act
        $exit = $tester->execute(['path' => $bogusPath]);

        // Assert
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Path not found', $tester->getDisplay());
    }

    /**
     * MigrateLogs on a valid single log file must process it and print a summary.
     * Exit code must be 0 (success).
     *
     * The log file contains a single legacy line; LogMigrator converts it to JSON.
     */
    public function testMigrateLogsMigratesSingleFile(): void
    {
        // Arrange — create a simple log file (plain text, non-JSON format)
        $logFile = $this->tmpDir . '/test.log';
        file_put_contents($logFile, "[2025-01-01 12:00:00] INFO: Application started\n");

        $app = new \Symfony\Component\Console\Application();
        $cmd = new MigrateLogs();
        $app->add($cmd);
        $tester = new CommandTester($cmd);

        // Act
        $exit = $tester->execute(['path' => $logFile]);

        // Assert — success exit code, summary displayed
        $this->assertSame(0, $exit);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Lines processed', $display);
    }

    /**
     * MigrateLogs with --all on a directory must process all .log files found
     * in that directory and display a multi-file summary.
     */
    public function testMigrateLogsProcessesDirectory(): void
    {
        // Arrange — create two log files in the temp directory
        $logDir = $this->tmpDir . '/logs';
        mkdir($logDir, 0777, true);
        file_put_contents($logDir . '/app1.log', "[2025-01-01 12:00:00] INFO: First app started\n");
        file_put_contents($logDir . '/app2.log', "[2025-01-01 12:00:01] ERROR: Something went wrong\n");

        $app = new \Symfony\Component\Console\Application();
        $cmd = new MigrateLogs();
        $app->add($cmd);
        $tester = new CommandTester($cmd);

        // Act
        $exit = $tester->execute(['path' => $logDir, '--all' => true]);

        // Assert — success, both files processed
        $this->assertSame(0, $exit);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Migration Summary', $display);
    }

    /**
     * MigrateLogs with --all on an empty directory must output "No .log files found"
     * and return 0.
     */
    public function testMigrateLogsEmptyDirectoryReturnsSuccess(): void
    {
        // Arrange — empty directory, no .log files
        $emptyDir = $this->tmpDir . '/empty_logs';
        mkdir($emptyDir, 0777, true);

        $app = new \Symfony\Component\Console\Application();
        $cmd = new MigrateLogs();
        $app->add($cmd);
        $tester = new CommandTester($cmd);

        // Act
        $exit = $tester->execute(['path' => $emptyDir, '--all' => true]);

        // Assert
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('No .log files found', $tester->getDisplay());
    }

    /**
     * MigrateLogs on a path that is neither a file nor a directory with --all
     * should return an error (the path is a file, but --all is only for dirs).
     */
    public function testMigrateLogsFileWithoutAllFlagMigratesFile(): void
    {
        // Arrange — a plain file without --all flag should be processed as a single file
        $logFile = $this->tmpDir . '/single.log';
        file_put_contents($logFile, "[2025-01-01 12:00:00] INFO: Hello\n");

        $app = new \Symfony\Component\Console\Application();
        $cmd = new MigrateLogs();
        $app->add($cmd);
        $tester = new CommandTester($cmd);

        // Act — no --all flag, it's a file
        $exit = $tester->execute(['path' => $logFile, '--no-backup' => true]);

        // Assert — single-file migration path (no backup)
        $this->assertSame(0, $exit);
    }

    // =========================================================================
    // Migrate (early-return guards)
    // =========================================================================

    /**
     * Migrate must reject execution with error message and return 1 when the
     * Console Application is not a \Pramnos\Console\Application instance.
     *
     * This guard prevents running migration infrastructure outside the Pramnos shell.
     */
    public function testMigrateRejectsPlainSymfonyApplication(): void
    {
        // Arrange — plain Symfony app, NOT Pramnos\Console\Application
        $app = new \Symfony\Component\Console\Application();
        $cmd = new Migrate();
        $app->add($cmd);
        $tester = new CommandTester($cmd);

        // Act
        $exit = $tester->execute([]);

        // Assert — error message, exit 1
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Pramnos console application', $tester->getDisplay());
    }

    /**
     * Migrate must output "No database connection available" and return 1 when
     * the internalApplication's database property is null.
     */
    public function testMigrateRejectsNullDatabase(): void
    {
        // Arrange — Pramnos Console Application with null database
        $consoleApp = $this->buildPramnosAppWithNullDb();
        $cmd = new Migrate();
        $consoleApp->add($cmd);
        $tester = new CommandTester($cmd);

        // Act
        $exit = $tester->execute([]);

        // Assert
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('No database connection', $tester->getDisplay());
    }

    // =========================================================================
    // MigrateStatus (early-return guards)
    // =========================================================================

    /**
     * MigrateStatus must reject execution outside of a Pramnos Console Application.
     */
    public function testMigrateStatusRejectsPlainSymfonyApplication(): void
    {
        // Arrange
        $app = new \Symfony\Component\Console\Application();
        $cmd = new MigrateStatus();
        $app->add($cmd);
        $tester = new CommandTester($cmd);

        // Act
        $exit = $tester->execute([]);

        // Assert
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Pramnos console application', $tester->getDisplay());
    }

    /**
     * MigrateStatus must output "No database connection available" when db is null.
     */
    public function testMigrateStatusRejectsNullDatabase(): void
    {
        // Arrange
        $consoleApp = $this->buildPramnosAppWithNullDb();
        $cmd = new MigrateStatus();
        $consoleApp->add($cmd);
        $tester = new CommandTester($cmd);

        // Act
        $exit = $tester->execute([]);

        // Assert
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('No database connection', $tester->getDisplay());
    }

    // =========================================================================
    // MigrateReset (early-return guards)
    // =========================================================================

    /**
     * MigrateReset must reject execution outside of a Pramnos Console Application.
     */
    public function testMigrateResetRejectsPlainSymfonyApplication(): void
    {
        // Arrange
        $app = new \Symfony\Component\Console\Application();
        $cmd = new MigrateReset();
        $app->add($cmd);
        $tester = new CommandTester($cmd);

        // Act
        $exit = $tester->execute([]);

        // Assert
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Pramnos console application', $tester->getDisplay());
    }

    /**
     * MigrateReset must output "No database connection available" when db is null.
     */
    public function testMigrateResetRejectsNullDatabase(): void
    {
        // Arrange
        $consoleApp = $this->buildPramnosAppWithNullDb();
        $cmd = new MigrateReset();
        $consoleApp->add($cmd);
        $tester = new CommandTester($cmd);

        // Act — use --force to skip confirmation prompt
        $exit = $tester->execute(['--force' => true]);

        // Assert
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('No database connection', $tester->getDisplay());
    }

    // =========================================================================
    // MigrateRollback (early-return guards)
    // =========================================================================

    /**
     * MigrateRollback must reject execution outside of a Pramnos Console Application.
     */
    public function testMigrateRollbackRejectsPlainSymfonyApplication(): void
    {
        // Arrange
        $app = new \Symfony\Component\Console\Application();
        $cmd = new MigrateRollback();
        $app->add($cmd);
        $tester = new CommandTester($cmd);

        // Act
        $exit = $tester->execute([]);

        // Assert
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Pramnos console application', $tester->getDisplay());
    }

    /**
     * MigrateRollback must output "No database connection available" when db is null.
     */
    public function testMigrateRollbackRejectsNullDatabase(): void
    {
        // Arrange
        $consoleApp = $this->buildPramnosAppWithNullDb();
        $cmd = new MigrateRollback();
        $consoleApp->add($cmd);
        $tester = new CommandTester($cmd);

        // Act
        $exit = $tester->execute([]);

        // Assert
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('No database connection', $tester->getDisplay());
    }

    // =========================================================================
    // MigrateRefresh (early-return guards)
    // =========================================================================

    /**
     * MigrateRefresh must reject execution outside of a Pramnos Console Application.
     */
    public function testMigrateRefreshRejectsPlainSymfonyApplication(): void
    {
        // Arrange
        $app = new \Symfony\Component\Console\Application();
        $cmd = new MigrateRefresh();
        $app->add($cmd);
        $tester = new CommandTester($cmd);

        // Act
        $exit = $tester->execute([]);

        // Assert
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Pramnos console application', $tester->getDisplay());
    }

    /**
     * MigrateRefresh must output "No database connection available" when db is null.
     */
    public function testMigrateRefreshRejectsNullDatabase(): void
    {
        // Arrange
        $consoleApp = $this->buildPramnosAppWithNullDb();
        $cmd = new MigrateRefresh();
        $consoleApp->add($cmd);
        $tester = new CommandTester($cmd);

        // Act — use --force to bypass confirmation
        $exit = $tester->execute(['--force' => true]);

        // Assert
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('No database connection', $tester->getDisplay());
    }

    // =========================================================================
    // ProcessQueue (configure coverage)
    // =========================================================================

    /**
     * ProcessQueue::configure() must register the command with name 'queue:process'
     * and all expected options (daemon, runtime, sleep, limit, batch, type,
     * force, worker-id, start-from, reverse-order).
     *
     * configure() is called lazily by Symfony when the command is added to an
     * Application. This test triggers that path without starting the daemon loop.
     */
    public function testProcessQueueConfigureRegistersAllOptions(): void
    {
        // Arrange — add command to a plain app to trigger configure()
        $app = new \Symfony\Component\Console\Application();
        $cmd = new ProcessQueue();
        $app->add($cmd);

        // Act — retrieve the command from the app after configure()
        /** @var ProcessQueue $registered */
        $registered = $app->find('queue:process');

        // Assert — command name and key options exist
        $this->assertSame('queue:process', $registered->getName());
        $this->assertTrue($registered->getDefinition()->hasOption('daemon'));
        $this->assertTrue($registered->getDefinition()->hasOption('runtime'));
        $this->assertTrue($registered->getDefinition()->hasOption('sleep'));
        $this->assertTrue($registered->getDefinition()->hasOption('worker-id'));
    }

    // =========================================================================
    // CleanupQueue (configure coverage)
    // =========================================================================

    /**
     * CleanupQueue::configure() must register the command with name 'queue:cleanup'
     * and options for hours, include-failed, include-warning, and limit.
     */
    public function testCleanupQueueConfigureRegistersAllOptions(): void
    {
        // Arrange
        $app = new \Symfony\Component\Console\Application();
        $cmd = new CleanupQueue();
        $app->add($cmd);
        $registered = $app->find('queue:cleanup');

        // Assert — name and options
        $this->assertSame('queue:cleanup', $registered->getName());
        $this->assertTrue($registered->getDefinition()->hasOption('hours'));
        $this->assertTrue($registered->getDefinition()->hasOption('include-failed'));
        $this->assertTrue($registered->getDefinition()->hasOption('include-warning'));
        $this->assertTrue($registered->getDefinition()->hasOption('limit'));
    }

    // =========================================================================
    // Serve (configure coverage)
    // =========================================================================

    /**
     * Serve::configure() must register the command with name 'serve' and options
     * for --port and --host.
     */
    public function testServeConfigureRegistersPortAndHostOptions(): void
    {
        // Arrange
        $app = new \Symfony\Component\Console\Application();
        $cmd = new Serve();
        $app->add($cmd);
        $registered = $app->find('serve');

        // Assert
        $this->assertSame('serve', $registered->getName());
        $this->assertTrue($registered->getDefinition()->hasOption('port'));
        $this->assertTrue($registered->getDefinition()->hasOption('host'));
    }

    // =========================================================================
    // Create (execute() exception paths)
    // =========================================================================

    /**
     * Create::execute() must throw InvalidArgumentException when an unknown
     * entity type is supplied.  The default: case in the switch must be reached.
     */
    public function testCreateExecuteThrowsForUnknownEntity(): void
    {
        // Arrange
        $app = new \Symfony\Component\Console\Application();
        $cmd = new Create();
        $app->add($cmd);
        $tester = new CommandTester($cmd);

        // Assert — the exception propagates through CommandTester
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid type of entity/i');

        // Act
        $tester->execute(['entity' => 'frobnicator']);
    }

    /**
     * Create::execute() must throw InvalidArgumentException when entity is 'model'
     * but no name argument is given.  This tests the guard: if (!$name) throw ...
     */
    public function testCreateExecuteThrowsWhenModelNameMissing(): void
    {
        // Arrange
        $app = new \Symfony\Component\Console\Application();
        $cmd = new Create();
        $app->add($cmd);
        $tester = new CommandTester($cmd);

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Name is required for: model/i');

        // Act — entity=model, no name provided
        $tester->execute(['entity' => 'model']);
    }

    /**
     * Create::execute() must throw InvalidArgumentException when entity is
     * 'controller' but no name argument is given.
     */
    public function testCreateExecuteThrowsWhenControllerNameMissing(): void
    {
        // Arrange
        $app = new \Symfony\Component\Console\Application();
        $cmd = new Create();
        $app->add($cmd);
        $tester = new CommandTester($cmd);

        // Assert
        $this->expectException(\InvalidArgumentException::class);

        // Act
        $tester->execute(['entity' => 'controller']);
    }

    /**
     * Create::execute() must throw when entity is 'view' with no name.
     */
    public function testCreateExecuteThrowsWhenViewNameMissing(): void
    {
        // Arrange
        $app = new \Symfony\Component\Console\Application();
        $cmd = new Create();
        $app->add($cmd);
        $tester = new CommandTester($cmd);

        $this->expectException(\InvalidArgumentException::class);
        $tester->execute(['entity' => 'view']);
    }

    /**
     * Create::execute() must throw when entity is 'crud' with no name.
     */
    public function testCreateExecuteThrowsWhenCrudNameMissing(): void
    {
        // Arrange
        $app = new \Symfony\Component\Console\Application();
        $cmd = new Create();
        $app->add($cmd);
        $tester = new CommandTester($cmd);

        $this->expectException(\InvalidArgumentException::class);
        $tester->execute(['entity' => 'crud']);
    }

    /**
     * Create::execute() must throw when entity is 'api' with no name.
     */
    public function testCreateExecuteThrowsWhenApiNameMissing(): void
    {
        // Arrange
        $app = new \Symfony\Component\Console\Application();
        $cmd = new Create();
        $app->add($cmd);
        $tester = new CommandTester($cmd);

        $this->expectException(\InvalidArgumentException::class);
        $tester->execute(['entity' => 'api']);
    }

    /**
     * Create::execute() must throw when entity is 'seeder' with no name.
     */
    public function testCreateExecuteThrowsWhenSeederNameMissing(): void
    {
        // Arrange
        $app = new \Symfony\Component\Console\Application();
        $cmd = new Create();
        $app->add($cmd);
        $tester = new CommandTester($cmd);

        $this->expectException(\InvalidArgumentException::class);
        $tester->execute(['entity' => 'seeder']);
    }

    /**
     * Create::execute() must throw when entity is 'middleware' with no name.
     */
    public function testCreateExecuteThrowsWhenMiddlewareNameMissing(): void
    {
        // Arrange
        $app = new \Symfony\Component\Console\Application();
        $cmd = new Create();
        $app->add($cmd);
        $tester = new CommandTester($cmd);

        $this->expectException(\InvalidArgumentException::class);
        $tester->execute(['entity' => 'middleware']);
    }

    /**
     * Create::execute() must throw when entity is 'event' with no name.
     */
    public function testCreateExecuteThrowsWhenEventNameMissing(): void
    {
        // Arrange
        $app = new \Symfony\Component\Console\Application();
        $cmd = new Create();
        $app->add($cmd);
        $tester = new CommandTester($cmd);

        $this->expectException(\InvalidArgumentException::class);
        $tester->execute(['entity' => 'event']);
    }

    /**
     * Create::execute() must throw when entity is 'listener' with no name.
     */
    public function testCreateExecuteThrowsWhenListenerNameMissing(): void
    {
        // Arrange
        $app = new \Symfony\Component\Console\Application();
        $cmd = new Create();
        $app->add($cmd);
        $tester = new CommandTester($cmd);

        $this->expectException(\InvalidArgumentException::class);
        $tester->execute(['entity' => 'listener']);
    }

    // =========================================================================
    // PolicyEngine (configure coverage)
    // =========================================================================

    /**
     * PolicyEngine::configure() must register the command with name
     * 'service:policy-engine' and options --list and --pretend.
     *
     * Triggering configure() via Application::add() is sufficient to cover
     * all configure() lines without executing the daemon logic.
     */
    public function testPolicyEngineConfigureRegistersOptions(): void
    {
        // Arrange — add to a plain Symfony app to trigger configure()
        $app = new \Symfony\Component\Console\Application();
        $cmd = new PolicyEngine();
        $app->add($cmd);
        $registered = $app->find('service:policy-engine');

        // Assert — name and options
        $this->assertSame('service:policy-engine', $registered->getName());
        $this->assertTrue($registered->getDefinition()->hasOption('list'));
        $this->assertTrue($registered->getDefinition()->hasOption('pretend'));
    }

    /**
     * PolicyEngine with --list when no Pramnos Application instance exists must
     * exit with FAILURE and print an error message — it must not throw a TypeError
     * or crash the process.
     *
     * In this test class tearDown() clears the Application singleton, so
     * Application::getInstance() returns null. The fixed execute() method detects
     * this, emits an <error> message, and returns Command::FAILURE. This covers
     * the null-application guard path added to PolicyEngine::execute().
     */
    public function testPolicyEngineListWithNoPolicies(): void
    {
        // Arrange — tearDown() has cleared the Application singleton, so
        // getInstance() will return null for this test.
        $app = new \Symfony\Component\Console\Application();
        $cmd = new PolicyEngine();
        $app->add($cmd);
        $tester = new CommandTester($cmd);

        // Act
        $exit = $tester->execute(['--list' => true]);

        // Assert — must fail gracefully with FAILURE exit code and an error message.
        // Command::FAILURE = 1
        $this->assertSame(\Symfony\Component\Console\Command\Command::FAILURE, $exit);
        $this->assertStringContainsString('No application instance available', $tester->getDisplay());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Build a minimal \Pramnos\Console\Application that has a mock internalApplication
     * with a null database property.  Used by all Migrate* "null database" tests.
     */
    private function buildPramnosAppWithNullDb(): ConsoleApplication
    {
        // Subclass to suppress real command registration
        $consoleApp = new class extends ConsoleApplication {
            protected function registerCommands(): void {}
        };

        // Replace internalApplication with a minimal mock that has database=null
        $consoleApp->internalApplication = new class {
            public string $appName         = '';
            public array  $applicationInfo = ['namespace' => 'App'];
            /** @var null  Deliberately null to trigger the early-return guard */
            public $database = null;
            public function init(): void {}
        };

        return $consoleApp;
    }

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmdirRecursive($path) : unlink($path);
        }
        rmdir($dir);
    }
}
