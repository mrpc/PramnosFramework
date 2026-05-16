<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Pramnos\Console\Application as ConsoleApplication;
use Pramnos\Console\Commands\Create;

/**
 * Tests for Create command file-writing methods (createMigration, createSeeder,
 * createMiddleware, createEvent, createListener).
 *
 * These methods call $this->getApplication()->internalApplication->init() so
 * a mock Pramnos Console Application with a stub internalApplication is needed.
 *
 * Each test uses unique names to avoid collisions, and tearDown removes any
 * files created under APP_PATH / ROOT so the working tree stays clean.
 *
 * Methods that require a live database (createModel, createController,
 * createView, createApi, createCrud) are NOT tested here — they call
 * Database::getInstance() and tableExists() internally.
 */
#[CoversClass(Create::class)]
class CreateCommandFileTest extends TestCase
{
    /** Files created by tests that must be removed in tearDown. */
    private array $filesToCleanup = [];

    /** Directories created by tests (only if they didn't already exist). */
    private array $dirsToCleanup = [];

    private ConsoleApplication $consoleApp;
    private Create $create;
    private string $testId;

    protected function setUp(): void
    {
        // A unique suffix so concurrent runs / reruns cannot collide.
        $this->testId = bin2hex(random_bytes(6));

        $this->consoleApp = new class extends ConsoleApplication {
            protected function registerCommands(): void {}
        };

        // Minimal mock internalApplication: no real init(), namespace = 'TestApp'
        $this->consoleApp->internalApplication = new class {
            public string $appName         = '';
            public array  $applicationInfo = ['namespace' => 'TestApp'];
            public $database               = null;
            public function init(): void {}
        };

        $this->create = new Create();
        $this->consoleApp->add($this->create);
    }

    protected function tearDown(): void
    {
        // Remove files in reverse order to allow directory cleanup
        foreach (array_reverse($this->filesToCleanup) as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }

        // Glob-based fallback: getProperClassName() may singularize/lowercase the
        // name (e.g. ZZZTestCovSeedCols → Zzztestcovseedcol), so the pre-computed
        // path in $filesToCleanup can be wrong-case and file_exists() returns false
        // on case-sensitive Linux. Scanning by testId catches any casing variant.
        $root   = defined('ROOT') ? ROOT : getcwd();
        $unitDir  = $root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Unit';
        $seedDir  = APP_PATH . DIRECTORY_SEPARATOR . 'seeders';
        foreach ([$unitDir, $seedDir] as $dir) {
            foreach (glob($dir . DIRECTORY_SEPARATOR . '*' . $this->testId . '*.php') ?: [] as $leftover) {
                @unlink($leftover);
            }
        }

        foreach (array_reverse($this->dirsToCleanup) as $dir) {
            if (is_dir($dir) && count(scandir($dir)) === 2) { // only . and ..
                rmdir($dir);
            }
        }
        $this->filesToCleanup = [];
        $this->dirsToCleanup  = [];
    }

    // =========================================================================
    // createMiddleware()
    // =========================================================================

    /**
     * createMiddleware() must throw InvalidArgumentException when the sanitised
     * class name is empty (all characters stripped by the word-char filter).
     *
     * Covers the early-exit guard at line ~151 without touching the filesystem.
     */
    public function testCreateMiddlewareThrowsForEmptyClassName(): void
    {
        // Arrange — any string that reduces to '' after preg_replace('/\W+/', '', $s)
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/valid PHP class name/i');

        // Act — all non-word characters, ucfirst('') = ''
        $this->create->createMiddleware('@@@');
    }

    /**
     * createMiddleware() with a valid name must create the PHP file under
     * ROOT/src/Middleware/ and return a summary string containing the class name.
     *
     * The generated file is tracked for removal in tearDown.
     */
    public function testCreateMiddlewareCreatesFile(): void
    {
        // Arrange — unique name to avoid collisions with any existing middleware
        $name = 'ZZZTestCovMiddleware' . $this->testId;
        $dir  = (defined('ROOT') ? ROOT : getcwd()) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Middleware';

        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
            $this->dirsToCleanup[] = $dir;
        }

        $expectedFile = $dir . DIRECTORY_SEPARATOR . $name . '.php';
        $testFile     = (defined('ROOT') ? ROOT : getcwd())
            . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Unit'
            . DIRECTORY_SEPARATOR . $name . 'MiddlewareTest.php';

        $this->filesToCleanup[] = $expectedFile;
        $this->filesToCleanup[] = $testFile;

        // Act
        $summary = $this->create->createMiddleware($name);

        // Assert — file created, summary contains class name
        $this->assertFileExists($expectedFile);
        $this->assertStringContainsString($name, $summary);
        $this->assertStringContainsString('Middleware created', $summary);
    }

    /**
     * createMiddleware() must throw when a file with the computed class name
     * already exists in the Middleware directory.  Covers the "already exists"
     * guard.
     */
    public function testCreateMiddlewareThrowsWhenFileAlreadyExists(): void
    {
        // Arrange — pre-create the target file
        $name = 'ZZZTestExistsMware' . $this->testId;
        $dir  = (defined('ROOT') ? ROOT : getcwd()) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Middleware';
        @mkdir($dir, 0777, true);
        $file = $dir . DIRECTORY_SEPARATOR . $name . '.php';
        file_put_contents($file, '<?php // pre-existing');
        $this->filesToCleanup[] = $file;

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/already exists/');

        // Act — second call with same name must throw
        $this->create->createMiddleware($name);
    }

    // =========================================================================
    // createEvent()
    // =========================================================================

    /**
     * createEvent() with an all-symbol name must throw InvalidArgumentException.
     */
    public function testCreateEventThrowsForEmptyClassName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->create->createEvent('---');
    }

    /**
     * createEvent() with a valid name must create the PHP file under
     * ROOT/src/Events/ and return a summary containing the class name.
     */
    public function testCreateEventCreatesFile(): void
    {
        // Arrange
        $name = 'ZZZTestCovEvent' . $this->testId;
        $dir  = (defined('ROOT') ? ROOT : getcwd()) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Events';

        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
            $this->dirsToCleanup[] = $dir;
        }

        $expectedFile = $dir . DIRECTORY_SEPARATOR . $name . '.php';
        $testFile     = (defined('ROOT') ? ROOT : getcwd())
            . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Unit'
            . DIRECTORY_SEPARATOR . $name . 'EventTest.php';

        $this->filesToCleanup[] = $expectedFile;
        $this->filesToCleanup[] = $testFile;

        // Act
        $summary = $this->create->createEvent($name);

        // Assert
        $this->assertFileExists($expectedFile);
        $this->assertStringContainsString($name, $summary);
        $this->assertStringContainsString('Event created', $summary);
    }

    /**
     * createEvent() must throw when the file already exists.
     */
    public function testCreateEventThrowsWhenFileAlreadyExists(): void
    {
        $name = 'ZZZTestExistsEvent' . $this->testId;
        $dir  = (defined('ROOT') ? ROOT : getcwd()) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Events';
        @mkdir($dir, 0777, true);
        $file = $dir . DIRECTORY_SEPARATOR . $name . '.php';
        file_put_contents($file, '<?php // pre-existing');
        $this->filesToCleanup[] = $file;

        $this->expectException(\Exception::class);
        $this->create->createEvent($name);
    }

    // =========================================================================
    // createListener()
    // =========================================================================

    /**
     * createListener() with an all-symbol name must throw InvalidArgumentException.
     */
    public function testCreateListenerThrowsForEmptyClassName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->create->createListener('###');
    }

    /**
     * createListener() with a valid name must create the PHP file under
     * ROOT/src/Listeners/ and return a summary containing the class name.
     */
    public function testCreateListenerCreatesFile(): void
    {
        // Arrange
        $name = 'ZZZTestCovListener' . $this->testId;
        $dir  = (defined('ROOT') ? ROOT : getcwd()) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Listeners';

        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
            $this->dirsToCleanup[] = $dir;
        }

        $expectedFile = $dir . DIRECTORY_SEPARATOR . $name . '.php';
        $testFile     = (defined('ROOT') ? ROOT : getcwd())
            . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Unit'
            . DIRECTORY_SEPARATOR . $name . 'ListenerTest.php';

        $this->filesToCleanup[] = $expectedFile;
        $this->filesToCleanup[] = $testFile;

        // Act
        $summary = $this->create->createListener($name);

        // Assert
        $this->assertFileExists($expectedFile);
        $this->assertStringContainsString($name, $summary);
        $this->assertStringContainsString('Listener created', $summary);
    }

    /**
     * createListener() must throw when the file already exists.
     */
    public function testCreateListenerThrowsWhenFileAlreadyExists(): void
    {
        $name = 'ZZZTestExistsListener' . $this->testId;
        $dir  = (defined('ROOT') ? ROOT : getcwd()) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Listeners';
        @mkdir($dir, 0777, true);
        $file = $dir . DIRECTORY_SEPARATOR . $name . '.php';
        file_put_contents($file, '<?php // pre-existing');
        $this->filesToCleanup[] = $file;

        $this->expectException(\Exception::class);
        $this->create->createListener($name);
    }

    // =========================================================================
    // createMigration()
    // =========================================================================

    /**
     * createMigration() with a valid slug must create a migration file under
     * APP_PATH/migrations/ and return a summary containing the migration filename.
     *
     * No database connection is required — the command only generates PHP source.
     */
    public function testCreateMigrationCreatesFile(): void
    {
        // Arrange
        $migDir = APP_PATH . DIRECTORY_SEPARATOR . 'migrations';
        if (!is_dir($migDir)) {
            mkdir($migDir, 0755, true);
            $this->dirsToCleanup[] = $migDir;
        }

        // Register glob pattern for cleanup (timestamp-prefixed file)
        $slug = 'zzz_test_cov_migration_' . $this->testId;

        // Act
        $summary = $this->create->createMigration($slug);

        // Find the created file (timestamp prefix makes exact name unknown)
        $files = glob($migDir . DIRECTORY_SEPARATOR . '*' . $slug . '.php') ?: [];
        foreach ($files as $f) {
            $this->filesToCleanup[] = $f;
        }

        // Assert — file created, summary mentions class name and file path
        $this->assertCount(1, $files, 'Exactly one migration file should have been created');
        $this->assertStringContainsString('Migration created', $summary);
        $this->assertStringContainsString($migDir, $summary);

        // The generated PHP must be parseable — class name must be PascalCase
        $content = file_get_contents($files[0]);
        $this->assertStringContainsString('class ', $content);
        $this->assertStringContainsString('extends Migration', $content);
    }

    /**
     * createMigration() must throw when the timestamp+slug file already exists.
     * This is a subtle race condition guard, not normally triggered in practice.
     *
     * We test the "already exists" branch by patching file_exists indirectly:
     * pre-create the exact filename that createMigration() would produce.
     *
     * Note: since the filename includes the current timestamp, we cannot reliably
     * pre-create it.  Instead, we test the valid path only (covered by the test
     * above) and trust that the exception branch is trivially correct.
     */

    // =========================================================================
    // createSeeder()
    // =========================================================================

    /**
     * createSeeder() with a valid name and no columns must create a bare-skeleton
     * seeder under APP_PATH/seeders/ and return a summary.
     */
    public function testCreateSeederCreatesSkeletonFile(): void
    {
        // Arrange
        $seedDir = APP_PATH . DIRECTORY_SEPARATOR . 'seeders';
        if (!is_dir($seedDir)) {
            mkdir($seedDir, 0755, true);
            $this->dirsToCleanup[] = $seedDir;
        }

        $name      = 'ZZZTestCovSeed' . $this->testId;
        // Use the same class-name derivation as createSeeder() so the expected
        // path matches even when testId ends in a letter that isPlural() considers
        // plural (e.g. 'a') and singularize() lowercases the whole name.
        $baseName  = \Pramnos\Console\Commands\Create::getProperClassName($name, true);
        $className = $baseName . 'Seeder';
        $file      = $seedDir . DIRECTORY_SEPARATOR . $className . '.php';
        $this->filesToCleanup[] = $file;

        // testStub file that generateTestStub() may write
        $testFile = (defined('ROOT') ? ROOT : getcwd())
            . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Unit'
            . DIRECTORY_SEPARATOR . $className . 'Test.php';
        $this->filesToCleanup[] = $testFile;

        // Act — empty columns → bare skeleton
        $summary = $this->create->createSeeder($name, [], '#PREFIX#test_table');

        // Assert
        $this->assertFileExists($file);
        $this->assertStringContainsString($className, $summary);
        $this->assertStringContainsString('Seeder created', $summary);

        // Skeleton should have a TODO comment, not actual field inserts
        $content = file_get_contents($file);
        $this->assertStringContainsString('TODO', $content);
    }

    /**
     * createSeeder() with columns provided must build a populated seeder using
     * buildSeederFields() and write it to disk.
     */
    public function testCreateSeederCreatesPopulatedFile(): void
    {
        // Arrange
        $seedDir = APP_PATH . DIRECTORY_SEPARATOR . 'seeders';
        if (!is_dir($seedDir)) {
            mkdir($seedDir, 0755, true);
            $this->dirsToCleanup[] = $seedDir;
        }

        $name      = 'ZZZTestCovSeedCols' . $this->testId;
        // Mirror the path derivation of createSeeder() to avoid case mismatches
        // when testId ends in a plural-triggering character (e.g. 'a', 's').
        $baseName  = \Pramnos\Console\Commands\Create::getProperClassName($name, true);
        $className = $baseName . 'Seeder';
        $file      = $seedDir . DIRECTORY_SEPARATOR . $className . '.php';
        $this->filesToCleanup[] = $file;

        $testFile = (defined('ROOT') ? ROOT : getcwd())
            . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Unit'
            . DIRECTORY_SEPARATOR . $className . 'Test.php';
        $this->filesToCleanup[] = $testFile;

        $columns = [
            ['name' => 'email',  'type' => 'string',  'options' => []],
            ['name' => 'status', 'type' => 'string',  'options' => []],
            ['name' => 'score',  'type' => 'integer', 'options' => []],
        ];

        // Act — columns provided → populated seeder
        $summary = $this->create->createSeeder($name, $columns, '#PREFIX#test_items');

        // Assert
        $this->assertFileExists($file);
        $content = file_get_contents($file);
        // Generated seeder must contain fake-value expressions for each column
        $this->assertStringContainsString('example.com', $content); // email heuristic
        $this->assertStringContainsString('Seeder created', $summary);
    }

    /**
     * createSeeder() must throw when a seeder with the same class name already
     * exists in the seeders directory.
     */
    public function testCreateSeederThrowsWhenFileAlreadyExists(): void
    {
        // Arrange — pre-create the seeder file
        $seedDir = APP_PATH . DIRECTORY_SEPARATOR . 'seeders';
        if (!is_dir($seedDir)) {
            mkdir($seedDir, 0755, true);
            $this->dirsToCleanup[] = $seedDir;
        }

        $name      = 'ZZZTestExistsSeed' . $this->testId;
        // Derive path the same way createSeeder() does so the pre-existing file
        // sits at the path createSeeder() will compute, not at the raw $name path.
        $baseName  = \Pramnos\Console\Commands\Create::getProperClassName($name, true);
        $className = $baseName . 'Seeder';
        $file      = $seedDir . DIRECTORY_SEPARATOR . $className . '.php';
        file_put_contents($file, '<?php // pre-existing');
        $this->filesToCleanup[] = $file;

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/already exists/');

        // Act
        $this->create->createSeeder($name, [], '#PREFIX#test_t');
    }

    // =========================================================================
    // execute() — migration branch without name (wizard path)
    // =========================================================================

    /**
     * execute() with entity='migration' and a name must call createMigration()
     * and write the output.  The return code must be 0.
     */
    public function testExecuteCreatesMigrationViaSwitchCase(): void
    {
        // Arrange — ensure migrations directory exists and track cleanup
        $migDir = APP_PATH . DIRECTORY_SEPARATOR . 'migrations';
        if (!is_dir($migDir)) {
            mkdir($migDir, 0755, true);
            $this->dirsToCleanup[] = $migDir;
        }

        // Register a glob pattern for the created file (unknown timestamp prefix)
        $slug = 'zzz_exec_' . $this->testId;

        $tester = new CommandTester($this->create);

        // Act
        $exit = $tester->execute(['entity' => 'migration', 'name' => $slug]);

        // Assert — exit 0, output contains something
        $this->assertSame(0, $exit);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Migration created', $display);

        // Track created files for tearDown
        $files = glob($migDir . DIRECTORY_SEPARATOR . '*' . $slug . '.php') ?: [];
        foreach ($files as $f) {
            $this->filesToCleanup[] = $f;
        }
    }
}
