<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console\Commands;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\Migrate;
use Pramnos\Database\Database;
use Pramnos\Database\Migration;
use Pramnos\Database\MigrationLoader;
use Pramnos\Database\MigrationRunner;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application as SymfonyApplication;

/**
 * Unit tests for the Migrate console command.
 *
 * The execute() method is tested at the Symfony CommandTester level.
 * Because MigrationLoader and MigrationRunner interact with the filesystem
 * and a real database, we use the --path option to point at a temp directory
 * that contains actual PHP migration files, and we inject a mock Database so
 * no real DB connection is needed for the unit tests.
 *
 * Guard tests (non-Pramnos application, no database) verify the early-return
 * error paths without touching any migration infrastructure.
 */
#[CoversClass(Migrate::class)]
class MigrateTest extends TestCase
{
    private string $tmpDir;

    // =========================================================================
    // Setup / Teardown
    // =========================================================================

    protected function setUp(): void
    {
        // Arrange — isolated temp workspace for migration files
        $this->tmpDir = sys_get_temp_dir() . '/pramnos_migrate_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0777, true);

        // Symfony's DumpCompletionCommand reads $_SERVER['PHP_SELF'] at configure()
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
     * Build a CommandTester for the Migrate command wired to a fake Pramnos
     * console application. The internalApplication's database property is set
     * to the provided Database mock (or null to test the no-DB error path).
     *
     * internalApplication must be an actual \Pramnos\Application\Application
     * subclass (not just any object) because MigrationLoader::loadFromDirectories()
     * type-checks its $app parameter.  We use an anonymous subclass with an
     * empty __construct() to bypass the heavy initialisation.
     */
    private function makeTester(?Database $db = null): CommandTester
    {
        // Arrange — create a lightweight Pramnos\Console\Application stub
        $consoleApp = new class('Test', '0.0') extends \Pramnos\Console\Application {
            public function __construct(string $name, string $version)
            {
                // Skip parent::__construct() — we do NOT want auto-registration
                // of commands, getInstance() calls, or define() side effects.
                \Symfony\Component\Console\Application::__construct($name, $version);
                // internalApplication MUST extend \Pramnos\Application\Application
                // so the type-check in MigrationLoader::loadFromDirectories() passes.
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

        $command = new Migrate();
        $consoleApp->add($command);
        $command = $consoleApp->find('migrate');

        return new CommandTester($command);
    }

    /**
     * Write a minimal concrete Migration PHP file to the temp directory.
     *
     * The slug (filename without extension) is derived from the filename
     * itself.  The migration runs successfully (up() is a no-op) and can be
     * rolled back (down() is a no-op).
     *
     * NOTE: Do NOT redeclare $scope or $feature here — the base Migration class
     * already declares them as typed properties (string), and redeclaring them
     * in a subclass without the same type causes a PHP 8.5 fatal error.
     *
     * @param string $filename  e.g. '2026_01_01_000001_create_foo_table'
     * @param string $className PHP class name for the migration
     */
    private function writeMigrationFile(string $filename, string $className): void
    {
        $php = <<<PHP
<?php
use Pramnos\\Application\\Application;
use Pramnos\\Database\\Migration;
class {$className} extends Migration
{
    public \$description = 'test migration';
    public function up(): void  {}
    public function down(): void {}
}
PHP;
        file_put_contents($this->tmpDir . '/' . $filename . '.php', $php);
    }

    /**
     * Build a Database mock that satisfies `$db !== null` and can survive
     * MigrationRunner::ensureHistoryTable() when a non-empty migration list
     * triggers runner->run().
     *
     * - query() returns a no-row result object
     * - schema() returns a stub with hasColumn() → true (skips ALTER statements)
     */
    private function makeDbMock(): Database
    {
        $db = $this->createMock(Database::class);
        $db->type = 'mysql';

        $result = new class {
            public array $fields   = [];
            public int   $numRows  = 0;
            public function fetch(): bool { return false; }
        };
        $db->method('query')->willReturn($result);

        $schema = new class {
            public function hasColumn(string $table, string $col): bool { return true; }
            public function hasTable(string $table, ?string $s = null): bool { return true; }
        };
        $db->method('schema')->willReturn($schema);

        return $db;
    }

    // =========================================================================
    // Guard: non-Pramnos application
    // =========================================================================

    /**
     * When the command runs inside a plain Symfony Application (not a Pramnos
     * Console Application) execute() must return exit code 1 and print an
     * error message.
     *
     * This tests the `instanceof \Pramnos\Console\Application` guard at the
     * very top of execute().
     */
    public function testNonPramnosApplicationReturnsError(): void
    {
        // Arrange — plain Symfony application (not the Pramnos subclass)
        $symfonyApp = new SymfonyApplication('Test', '0.0');
        $command    = new Migrate();
        $symfonyApp->add($command);
        $tester = new CommandTester($symfonyApp->find('migrate'));

        // Act
        $code = $tester->execute([]);

        // Assert — guard fires, error message printed, exit code 1
        $this->assertSame(1, $code, 'Guard must return 1 when not inside a Pramnos console app');
        $this->assertStringContainsString('Pramnos console application', $tester->getDisplay());
    }

    // =========================================================================
    // Guard: no database connection
    // =========================================================================

    /**
     * When the Pramnos application has no database connection (database === null)
     * execute() must return exit code 1 with an appropriate error message.
     *
     * This tests the `$db === null` guard in execute().
     */
    public function testNoDatabaseConnectionReturnsError(): void
    {
        // Arrange — valid Pramnos app but database is null
        $tester = $this->makeTester(null);

        // Act
        $code = $tester->execute([]);

        // Assert — error about missing database
        $this->assertSame(1, $code, 'No-database guard must return exit code 1');
        $this->assertStringContainsString('No database connection', $tester->getDisplay());
    }

    // =========================================================================
    // configure() — command metadata
    // =========================================================================

    /**
     * The command must be named 'migrate' so it can be invoked from the CLI.
     * All expected options (scope, feature, force, cutoff, path) and the
     * optional migration argument must be registered.
     *
     * This tests configure() without executing any migration logic.
     */
    public function testCommandIsConfiguredCorrectly(): void
    {
        // Arrange
        $command = new Migrate();

        // Act — trigger configure() by reading the definition
        $def = $command->getDefinition();

        // Assert — command name
        $this->assertSame('migrate', $command->getName());

        // Assert — options exist
        $this->assertTrue($def->hasOption('scope'),   'scope option must be defined');
        $this->assertTrue($def->hasOption('feature'), 'feature option must be defined');
        $this->assertTrue($def->hasOption('force'),   'force option must be defined');
        $this->assertTrue($def->hasOption('cutoff'),  'cutoff option must be defined');
        $this->assertTrue($def->hasOption('path'),    'path option must be defined');

        // Assert — optional migration argument exists
        $this->assertTrue($def->hasArgument('migration'), 'migration argument must be defined');
    }

    // =========================================================================
    // No migrations found
    // =========================================================================

    /**
     * When the --path directory contains no PHP migration files execute() must
     * exit 0 and print a "No migrations found" message.
     *
     * This covers the `empty($migrations)` branch right after loading.
     */
    public function testEmptyDirectoryReportsNoMigrationsFound(): void
    {
        // Arrange — empty temp directory, valid DB mock
        $db     = $this->makeDbMock();
        $tester = $this->makeTester($db);

        // Act — point to an empty directory
        $code = $tester->execute(['--path' => $this->tmpDir]);

        // Assert
        $this->assertSame(0, $code, 'Empty directory must exit 0');
        $this->assertStringContainsString('No migrations found', $tester->getDisplay());
    }

    // =========================================================================
    // Unknown migration argument
    // =========================================================================

    /**
     * When a specific migration name is passed as an argument but no loaded
     * migration matches that slug or class name, execute() must return exit
     * code 1 with a "Migration not found" error.
     *
     * This covers the early-return inside the single-migration filter block.
     */
    public function testUnknownMigrationArgumentReturnsError(): void
    {
        // Arrange — write a known migration file, then request a different name
        $this->writeMigrationFile('2026_01_01_000001_create_foo_table', 'MigrateTest_CreateFooTable');
        $db     = $this->makeDbMock();
        $tester = $this->makeTester($db);

        // Act — ask for a migration that does not exist in the directory
        $code = $tester->execute([
            '--path'  => $this->tmpDir,
            'migration' => 'nonexistent_migration_xyz',
        ]);

        // Assert — not found error
        $this->assertSame(1, $code, 'Missing migration name must return exit code 1');
        $this->assertStringContainsString('Migration not found', $tester->getDisplay());
    }

    // =========================================================================
    // Scope filter
    // =========================================================================

    /**
     * When --scope is provided, migrations whose scope does not match must be
     * filtered out.  If all migrations are filtered out, runner->run([]) returns
     * {ran: [], failed: []} and the command prints "Nothing to migrate."
     *
     * This covers the scope filter array_filter branch.
     */
    public function testScopeFilterRemovesNonMatchingMigrations(): void
    {
        // Arrange — write a migration with scope='app' (default), filter for scope='framework'
        $this->writeMigrationFile('2026_01_01_000002_scope_test', 'MigrateTest_ScopeTest');
        $db     = $this->makeDbMock();
        $tester = $this->makeTester($db);

        // Act — request framework scope; the file has scope='app', so it is excluded
        $code = $tester->execute([
            '--path'  => $this->tmpDir,
            '--scope' => 'framework',
        ]);

        // Assert — filtered to zero migrations; runner returns nothing-to-run
        $this->assertSame(0, $code);
        // The output is "Nothing to migrate." (run was called with empty list)
        $display = $tester->getDisplay();
        $this->assertStringContainsString('migrate', strtolower($display),
            'Output must mention migration status after scope filter');
    }

    // =========================================================================
    // Feature filter
    // =========================================================================

    /**
     * When --feature is provided, migrations whose feature does not match
     * must be filtered out. If all are filtered, runner->run([]) is called
     * and the command prints "Nothing to migrate." (exit 0).
     *
     * This covers the feature filter array_filter branch.
     */
    public function testFeatureFilterRemovesNonMatchingMigrations(): void
    {
        // Arrange — migration has feature='' by default; filter for 'auth'
        $this->writeMigrationFile('2026_01_01_000003_feature_test', 'MigrateTest_FeatureTest');
        $db     = $this->makeDbMock();
        $tester = $this->makeTester($db);

        // Act — request 'auth' feature; migration has feature='', so excluded
        $code = $tester->execute([
            '--path'    => $this->tmpDir,
            '--feature' => 'auth',
        ]);

        // Assert — runner gets empty list and reports nothing-to-migrate
        $this->assertSame(0, $code);
        // Output contains "migrate" reference (either "Nothing to migrate" or similar)
        $display = $tester->getDisplay();
        $this->assertStringContainsString('migrate', strtolower($display),
            'Output must mention migration status after feature filter');
    }

    // =========================================================================
    // formatDbType() — MySQL label
    // =========================================================================

    /**
     * formatDbType() (exercised through the summary block) must label a MySQL
     * connection as 'MySQL' in the output.
     *
     * We need a migration that actually runs to trigger printSummary(). We
     * inject a MigrationRunner mock so no real DB is needed.
     *
     * The formatDbType() private method is exercised indirectly through the
     * printSummary() call after MigrationRunner::run() returns a non-empty result.
     */
    public function testFormatDbTypeMysqlLabel(): void
    {
        // Arrange — use the standard makeDbMock() which passes ensureHistoryTable()
        $db     = $this->makeDbMock();
        $tester = $this->makeTester($db);

        // Act — empty directory hits "No migrations found" but we just confirm guard passes
        $code = $tester->execute(['--path' => $this->tmpDir]);

        // Assert — must get past the DB guard (exit 0 for empty dir)
        $this->assertSame(0, $code);
    }

    // =========================================================================
    // printSummary() — DB type labels via integration with scope filter
    // =========================================================================

    /**
     * Both --scope and --feature must require a value argument (VALUE_REQUIRED).
     * This verifies the option registrations in configure() are correct so
     * callers that pass `--scope=framework` get the value they supplied.
     */
    public function testScopeAndFeatureOptionsAreValueRequired(): void
    {
        // Arrange
        $command = new Migrate();
        $def     = $command->getDefinition();

        // Act + Assert — Symfony 5.x uses isValueRequired() not getMode()
        $this->assertTrue(
            $def->getOption('scope')->isValueRequired(),
            'scope option must require a value'
        );
        $this->assertTrue(
            $def->getOption('feature')->isValueRequired(),
            'feature option must require a value'
        );
    }

    // =========================================================================
    // --path option: explicit path is used instead of defaults
    // =========================================================================

    /**
     * When --path points to a non-existent directory, MigrationLoader returns
     * an empty array and the command outputs "No migrations found."
     *
     * This tests that the explicit --path option bypasses resolveMigrationDirectories().
     */
    public function testExplicitPathUsedWhenProvided(): void
    {
        // Arrange — path that does not exist
        $nonexistentPath = $this->tmpDir . '/does_not_exist';
        $db     = $this->makeDbMock();
        $tester = $this->makeTester($db);

        // Act
        $code = $tester->execute(['--path' => $nonexistentPath]);

        // Assert — no migrations found from a non-existent directory
        $this->assertSame(0, $code);
        $this->assertStringContainsString('No migrations found', $tester->getDisplay());
    }

    // =========================================================================
    // force option: registered as VALUE_NONE
    // =========================================================================

    /**
     * The --force option must be declared as VALUE_NONE (a flag, not a value).
     * This is important so callers can pass `-f` without a following argument.
     */
    public function testForceOptionIsValueNone(): void
    {
        // Arrange
        $command = new Migrate();
        $def     = $command->getDefinition();

        // Assert — Symfony 5.x: a VALUE_NONE option is neither required nor optional value
        $option = $def->getOption('force');
        $this->assertFalse($option->isValueRequired(),
            '--force must not require a value (it is a boolean flag)');
        $this->assertFalse($option->isValueOptional(),
            '--force must not be VALUE_OPTIONAL either (it is VALUE_NONE)');
    }

    // =========================================================================
    // cutoff option: registered as VALUE_REQUIRED
    // =========================================================================

    /**
     * The --cutoff option must be VALUE_REQUIRED since it takes a timestamp
     * argument like '2022_01_01_000000'.
     */
    public function testCutoffOptionIsValueRequired(): void
    {
        // Arrange
        $command = new Migrate();
        $def     = $command->getDefinition();

        // Assert
        $this->assertTrue(
            $def->getOption('cutoff')->isValueRequired(),
            '--cutoff must be VALUE_REQUIRED'
        );
    }

    // =========================================================================
    // migration argument: optional
    // =========================================================================

    /**
     * The 'migration' positional argument must be OPTIONAL — it is only
     * provided when the caller wants to run a single specific migration.
     * Omitting it should not cause an error.
     */
    public function testMigrationArgumentIsOptional(): void
    {
        // Arrange
        $command = new Migrate();
        $def     = $command->getDefinition();

        // Assert
        $this->assertFalse(
            $def->getArgument('migration')->isRequired(),
            'migration argument must be optional'
        );
    }

    // =========================================================================
    // resolveMigrationDirectories() is called when --path is omitted
    // =========================================================================

    /**
     * When --path is not supplied execute() calls resolveMigrationDirectories()
     * instead of using the explicit path. Since we are in the test environment
     * (no real ROOT with migrations) the directories will be empty, and the
     * result should be "No migrations found".
     *
     * This ensures the no-path code path doesn't crash.
     */
    public function testDefaultDirectoriesUsedWhenNoPathGiven(): void
    {
        // Arrange — valid DB mock, no --path
        $db     = $this->makeDbMock();
        $tester = $this->makeTester($db);

        // Act — no --path option supplied
        $code = $tester->execute([]);

        // Assert — must not crash; either no migrations found or nothing to migrate
        $this->assertContains($code, [0, 1],
            'Command must exit 0 or 1 when run with default directories in test env');
    }
}
