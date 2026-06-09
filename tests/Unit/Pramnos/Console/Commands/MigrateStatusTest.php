<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console\Commands;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\MigrateStatus;
use Pramnos\Database\Database;
use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unit tests for the MigrateStatus console command.
 *
 * MigrateStatus reads migration files from disk and the migrations history
 * from the database, then renders a formatted table showing each migration's
 * current status (Ran / Failed / Pending / removed).
 *
 * Because MigrationRunner::getHistory() requires a live DB, unit tests focus
 * on:
 *  1. Guard paths (wrong application, no database).
 *  2. configure() coverage (name, options).
 *  3. The "no migrations and no history" empty-output path.
 *
 * Full table rendering with real data is covered by the Integration test suite
 * which runs against Docker databases.
 */
#[CoversClass(MigrateStatus::class)]
class MigrateStatusTest extends TestCase
{
    private string $tmpDir;

    // =========================================================================
    // Setup / Teardown
    // =========================================================================

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pramnos_mstatus_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0777, true);

        if (!isset($_SERVER['PHP_SELF'])) {
            $_SERVER['PHP_SELF'] = 'phpunit';
        }
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new \DirectoryIterator($dir) as $item) {
            if ($item->isDot()) {
                continue;
            }
            $item->isDir() ? $this->removeDir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Build a CommandTester for MigrateStatus backed by a lightweight Pramnos
     * console application stub. $db may be null to test the no-database guard.
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

        $command = new MigrateStatus();
        $consoleApp->add($command);
        $command = $consoleApp->find('migrate:status');

        return new CommandTester($command);
    }

    /**
     * Build a Database mock that mimics the minimum API required by
     * MigrationRunner::ensureHistoryTable() + getHistory():
     *  - type (mysql)
     *  - query() returning a no-row result
     *  - schema() returning a stub with hasColumn() → true (so no ALTER is attempted)
     */
    private function makeDbMockWithEmptyHistory(): Database
    {
        $db = $this->createMock(Database::class);
        $db->type = 'mysql';

        // Stub query() to return a fake result set with no rows
        $result = new class {
            public array $fields = [];
            public function fetch(): bool { return false; }
        };
        $db->method('query')->willReturn($result);

        // Stub schema() → hasColumn() returns true so ensureHistoryTable() skips ALTERs
        $schema = new class {
            public function hasColumn(string $table, string $col): bool { return true; }
        };
        $db->method('schema')->willReturn($schema);

        return $db;
    }

    // =========================================================================
    // Guard: non-Pramnos application
    // =========================================================================

    /**
     * Running migrate:status inside a plain Symfony Application (not a Pramnos
     * console Application) must return exit code 1 with an error message.
     *
     * This tests the `instanceof \Pramnos\Console\Application` guard.
     */
    public function testNonPramnosApplicationReturnsError(): void
    {
        // Arrange
        $symfonyApp = new SymfonyApplication('Test', '0.0');
        $command    = new MigrateStatus();
        $symfonyApp->add($command);
        $tester = new CommandTester($symfonyApp->find('migrate:status'));

        // Act
        $code = $tester->execute([]);

        // Assert
        $this->assertSame(1, $code, 'Guard must return 1 for non-Pramnos application');
        $this->assertStringContainsString('Pramnos console application', $tester->getDisplay());
    }

    // =========================================================================
    // Guard: no database connection
    // =========================================================================

    /**
     * When no database is available (database === null) execute() must return
     * exit code 1 and print an appropriate error.
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
     * The command must be registered as 'migrate:status' with the single
     * expected option (--path).
     */
    public function testCommandIsConfiguredCorrectly(): void
    {
        // Arrange
        $command = new MigrateStatus();
        $def     = $command->getDefinition();

        // Assert — command name
        $this->assertSame('migrate:status', $command->getName());

        // Assert — --path option is declared
        $this->assertTrue($def->hasOption('path'), '--path option must be defined');
    }

    /**
     * The --path option must be VALUE_REQUIRED so callers can pass a custom
     * migrations directory path.
     */
    public function testPathOptionIsValueRequired(): void
    {
        // Arrange
        $command = new MigrateStatus();
        $def     = $command->getDefinition();

        // Assert — Symfony 5.x: use isValueRequired() instead of getMode()
        $this->assertTrue(
            $def->getOption('path')->isValueRequired(),
            '--path must be VALUE_REQUIRED'
        );
    }

    // =========================================================================
    // Empty directory + empty history → "No migrations found"
    // =========================================================================

    /**
     * When both the migration directory (--path) is empty and the history
     * table returns no rows, execute() must exit 0 and print "No migrations
     * found."
     *
     * This tests the `empty($migrations) && empty($history)` branch.
     */
    public function testEmptyDirectoryAndEmptyHistoryReportsNoMigrationsFound(): void
    {
        // Arrange — DB mock with empty history, point to empty directory
        $db     = $this->makeDbMockWithEmptyHistory();
        $tester = $this->makeTester($db);

        // Act
        $code = $tester->execute(['--path' => $this->tmpDir]);

        // Assert
        $this->assertSame(0, $code, 'Empty directory + empty history must exit 0');
        $this->assertStringContainsString('No migrations found', $tester->getDisplay());
    }

    // =========================================================================
    // --path option: explicit path is used
    // =========================================================================

    /**
     * When --path is provided, the command must use that directory instead of
     * the default resolveMigrationDirectories() result.
     *
     * We verify this by pointing at a non-existent path — if resolveMigrationDirectories()
     * were used instead, the result would vary by environment, not be consistently empty.
     */
    public function testExplicitPathUsedWhenProvided(): void
    {
        // Arrange — point to a non-existent path
        $nonexistentPath = $this->tmpDir . '/nonexistent_migrations';
        $db     = $this->makeDbMockWithEmptyHistory();
        $tester = $this->makeTester($db);

        // Act
        $code = $tester->execute(['--path' => $nonexistentPath]);

        // Assert — command must complete without crashing
        $this->assertSame(0, $code);
    }

    // =========================================================================
    // resolveMigrationDirectories() — covered via reflection
    // =========================================================================

    /**
     * The private resolveMigrationDirectories() method must return a non-empty
     * array that includes an 'app/Migrations' entry as the first element.
     *
     * This verifies the method builds the expected default directory list
     * without requiring a live filesystem with real migrations.
     */
    public function testResolveMigrationDirectoriesIncludesAppMigrations(): void
    {
        // Arrange
        $command = new MigrateStatus();
        $method  = new \ReflectionMethod($command, 'resolveMigrationDirectories');

        // Act
        $dirs = $method->invoke($command);

        // Assert — must be a non-empty array
        $this->assertIsArray($dirs, 'resolveMigrationDirectories() must return an array');
        $this->assertNotEmpty($dirs, 'resolveMigrationDirectories() must return at least one directory');

        // Assert — first entry is the app/Migrations directory
        $this->assertStringContainsString('Migrations', $dirs[0],
            'First directory must be the app/Migrations path');
    }

    // =========================================================================
    // Table rendering with pending migration
    // =========================================================================

    /**
     * When a migration file exists in the directory but has no history entry,
     * it should appear as "Pending" in the table and trigger the "Run migrate"
     * hint message.
     *
     * We use a real migration file (with the correct class structure) and a
     * DB mock that returns an empty history.
     */
    public function testPendingMigrationAppearsPendingInTable(): void
    {
        // Arrange — write a minimal valid migration file.
        // NOTE: Do NOT redeclare typed properties ($scope, $feature) that are
        // already declared in the base Migration class; PHP 8.5 enforces type
        // consistency and will fatal-error on redeclaration without matching type.
        $className = 'MigrateStatusTest_PendingMigration' . bin2hex(random_bytes(2));
        $php = <<<PHP
<?php
use Pramnos\\Application\\Application;
use Pramnos\\Database\\Migration;
class {$className} extends Migration
{
    public \$description = 'pending test';
    public function up(): void  {}
    public function down(): void {}
}
PHP;
        $filename = '2026_01_02_000001_pending_test_' . bin2hex(random_bytes(2));
        file_put_contents($this->tmpDir . '/' . $filename . '.php', $php);

        $db     = $this->makeDbMockWithEmptyHistory();
        $tester = $this->makeTester($db);

        // Act
        $code = $tester->execute(['--path' => $this->tmpDir]);

        // Assert — exit code 0
        $this->assertSame(0, $code, 'Status command must exit 0 even with pending migrations');

        // Assert — "Pending" status and the hint to run migrate
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Pending', $display,
            'An unrun migration must show as Pending');
        $this->assertStringContainsString('migrate', $display,
            'A hint to run migrate must appear when there are pending migrations');
    }

    // =========================================================================
    // Table rendering with history-only migration (removed from codebase)
    // =========================================================================

    /**
     * When the history table contains a migration slug that no longer has a
     * corresponding file, execute() must show that migration as "(removed)"
     * in the table below a TableSeparator.
     *
     * We mock getHistory() by stubbing query() to return a single history row
     * and use an empty migration directory.
     */
    public function testRemovedMigrationShowsRemovedLabel(): void
    {
        // Arrange — DB mock that returns one history row for a "removed" migration
        $db = $this->createMock(Database::class);
        $db->type = 'mysql';

        // schema() stub needed by ensureHistoryTable() on MySQL path
        $schema = new class {
            public function hasColumn(string $table, string $col): bool { return true; }
        };
        $db->method('schema')->willReturn($schema);

        $callCount = 0;
        $db->method('query')->willReturnCallback(
            function (string $sql) use (&$callCount) {
                $callCount++;
                // For CREATE TABLE and column-addition queries (ensureHistoryTable),
                // return a trivial result object.
                if (str_contains(strtolower($sql), 'create') || str_contains(strtolower($sql), 'alter')) {
                    return new class {
                        public array $fields = [];
                        public function fetch(): bool { return false; }
                    };
                }
                // For SELECT (getHistory), return a single row on first call, then stop
                $row = [
                    'key'            => '2020_01_01_000001_removed_migration',
                    'scope'          => 'app',
                    'feature'        => '',
                    'result'         => '1',
                    'batch'          => '1',
                    'execution_time' => '0.1234',
                    'when'           => '2020-01-01 00:00:00',
                    'ran_at'         => '2020-01-01 00:00:00',
                ];
                return new class($row) {
                    public array $fields;
                    private bool $done = false;
                    private array $rowData;
                    public function __construct(array $row)
                    {
                        $this->rowData = $row;
                        $this->fields  = [];
                    }
                    public function fetch(): bool
                    {
                        if (!$this->done) {
                            $this->done   = true;
                            $this->fields = $this->rowData;
                            return true;
                        }
                        return false;
                    }
                };
            }
        );

        $tester = $this->makeTester($db);

        // Act — point to an empty directory so there are no local migration files
        $code = $tester->execute(['--path' => $this->tmpDir]);

        // Assert — exit code 0
        $this->assertSame(0, $code, 'Status command must exit 0 for removed migrations');

        // Assert — removed migration label visible in output
        $display = $tester->getDisplay();
        $this->assertStringContainsString('removed', $display,
            'A history-only migration must be shown as "(removed)"');
    }
}
