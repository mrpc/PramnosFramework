<?php

namespace Pramnos\Tests\Unit\Console\Commands;

use PHPUnit\Framework\TestCase;
use Pramnos\Console\Application;
use Pramnos\Console\Commands\DbSeed;
use Pramnos\Database\Seeder;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unit tests for the DbSeed console command.
 *
 * Seeds are PHP files in a directory (default: database/seeds/). Each file
 * contains a class that extends Seeder and implements run(). The command
 * discovers and executes them, reporting success or failure per seeder.
 *
 * All tests use a temporary directory so no real project files are touched.
 * Seeder classes are generated on-the-fly as actual PHP files so that
 * require_once and class_exists work without a live autoloader.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(DbSeed::class)]
class DbSeedTest extends TestCase
{
    // =========================================================================
    // Infrastructure
    // =========================================================================

    private string $seedsDir;

    /**
     * Create a fresh temp directory for each test.
     * The Pramnos\Console\Application is used so the getApplication() guard
     * inside execute() passes.
     */
    /** @var string|null Original $_SERVER['PHP_SELF'] value */
    private ?string $originalPhpSelf = null;

    protected function setUp(): void
    {
        $this->seedsDir = sys_get_temp_dir() . '/pramnos_seeds_' . bin2hex(random_bytes(4));
        mkdir($this->seedsDir, 0777, true);

        // Symfony's DumpCompletionCommand reads $_SERVER['PHP_SELF'] at configure()
        // time; ensure it is set to avoid "Undefined array key" warnings.
        $this->originalPhpSelf = $_SERVER['PHP_SELF'] ?? null;
        if (!isset($_SERVER['PHP_SELF'])) {
            $_SERVER['PHP_SELF'] = 'phpunit';
        }
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->seedsDir);

        if ($this->originalPhpSelf === null) {
            unset($_SERVER['PHP_SELF']);
        } else {
            $_SERVER['PHP_SELF'] = $this->originalPhpSelf;
        }
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

    /**
     * Build a CommandTester wired to a Pramnos\Console\Application so the
     * internal `instanceof Application` guard in execute() passes.
     */
    private function makeTester(): CommandTester
    {
        $app = new Application('Test', '0.0');
        $command = $app->find('db:seed');
        return new CommandTester($command);
    }

    /**
     * Write a valid seeder PHP file to the temp directory.
     * The generated class extends Seeder and appends the class name to a
     * shared static log array so tests can verify execution order.
     *
     * @param string $className  Simple class name (no namespace).
     * @param bool   $throws     When true, run() throws \RuntimeException.
     * @param bool   $notSeeder  When true, the class does NOT extend Seeder.
     */
    private function writeSeederFile(
        string $className,
        bool   $throws    = false,
        bool   $notSeeder = false
    ): void {
        $extends = $notSeeder ? '' : 'extends \\Pramnos\\Database\\Seeder';
        $body    = $throws
            ? 'throw new \\RuntimeException("deliberate failure");'
            : 'DbSeedTestLog::$ran[] = \'' . $className . '\';';

        $php = <<<PHP
<?php
class {$className} {$extends}
{
    public function run(): void
    {
        {$body}
    }
}
PHP;
        file_put_contents($this->seedsDir . '/' . $className . '.php', $php);
    }

    // =========================================================================
    // Missing / empty directory
    // =========================================================================

    /**
     * When the seeds directory does not exist the command exits successfully
     * (nothing to do is not an error) and prints an informational comment.
     */
    public function testMissingSeedsDirectoryExitsSuccessfully(): void
    {
        // Arrange
        $nonexistent = $this->seedsDir . '/does_not_exist';
        $tester = $this->makeTester();

        // Act
        $code = $tester->execute(['--path' => $nonexistent]);

        // Assert — not an error; message mentions the path
        $this->assertSame(0, $code);
        $this->assertStringContainsString('not found', $tester->getDisplay());
    }

    /**
     * An existing but empty seeds directory exits successfully with an
     * informational "no seeders found" message.
     */
    public function testEmptySeedsDirectoryExitsSuccessfully(): void
    {
        // Arrange — directory exists but contains no .php files
        $tester = $this->makeTester();

        // Act
        $code = $tester->execute(['--path' => $this->seedsDir]);

        // Assert
        $this->assertSame(0, $code);
        $this->assertStringContainsString('No seeders found', $tester->getDisplay());
    }

    // =========================================================================
    // Run all seeders
    // =========================================================================

    /**
     * With no seeder argument all .php files in the directory are loaded and
     * executed in alphabetical order (ksort). Each run() is reported as seeded.
     */
    public function testRunsAllSeedersInAlphabeticalOrder(): void
    {
        // Arrange — three seeders; names chosen so alphabetical order is predictable
        $this->writeSeederFile('AlphaSeeder');
        $this->writeSeederFile('BetaSeeder');
        $this->writeSeederFile('GammaSeeder');

        // Shared static log is initialised here so it survives require_once across tests
        if (!class_exists('DbSeedTestLog')) {
            eval('class DbSeedTestLog { public static array $ran = []; }');
        }
        \DbSeedTestLog::$ran = [];

        $tester = $this->makeTester();

        // Act
        $code = $tester->execute(['--path' => $this->seedsDir]);
        $output = $tester->getDisplay();

        // Assert — all three ran, exit 0
        $this->assertSame(0, $code);
        $this->assertStringContainsString('AlphaSeeder', $output);
        $this->assertStringContainsString('BetaSeeder', $output);
        $this->assertStringContainsString('GammaSeeder', $output);
        $this->assertStringContainsString('3 seeder(s) ran', $output);

        // Alphabetical order guaranteed by ksort in loadSeeders()
        $pos = [
            array_search('AlphaSeeder', \DbSeedTestLog::$ran),
            array_search('BetaSeeder',  \DbSeedTestLog::$ran),
            array_search('GammaSeeder', \DbSeedTestLog::$ran),
        ];
        $this->assertSame([0, 1, 2], $pos, 'Seeders ran out of alphabetical order');
    }

    /**
     * A single valid seeder is reported as seeded and returns exit code 0.
     */
    public function testRunsSingleValidSeeder(): void
    {
        // Arrange
        $this->writeSeederFile('SingleSeeder');

        if (!class_exists('DbSeedTestLog')) {
            eval('class DbSeedTestLog { public static array $ran = []; }');
        }
        \DbSeedTestLog::$ran = [];

        $tester = $this->makeTester();

        // Act
        $code = $tester->execute(['--path' => $this->seedsDir]);

        // Assert
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Seeded', $tester->getDisplay());
        $this->assertStringContainsString('SingleSeeder', $tester->getDisplay());
    }

    // =========================================================================
    // Named seeder argument
    // =========================================================================

    /**
     * When a seeder class name is given as an argument, only that seeder runs —
     * other files in the directory are ignored.
     */
    public function testRunsNamedSeederOnly(): void
    {
        // Arrange — two seeders; only TargetSeeder should run
        $this->writeSeederFile('TargetSeeder');
        $this->writeSeederFile('OtherSeeder');

        if (!class_exists('DbSeedTestLog')) {
            eval('class DbSeedTestLog { public static array $ran = []; }');
        }
        \DbSeedTestLog::$ran = [];

        $tester = $this->makeTester();

        // Act
        $code = $tester->execute([
            'seeder'  => 'TargetSeeder',
            '--path'  => $this->seedsDir,
        ]);
        $output = $tester->getDisplay();

        // Assert — only TargetSeeder in output and in the run log
        $this->assertSame(0, $code);
        $this->assertStringContainsString('TargetSeeder', $output);
        $this->assertStringNotContainsString('OtherSeeder', $output);
        $this->assertSame(['TargetSeeder'], \DbSeedTestLog::$ran);
    }

    /**
     * Requesting a seeder class that does not exist in the directory returns
     * exit code FAILURE and an error message containing the class name.
     */
    public function testNamedSeederNotFoundReturnsFailure(): void
    {
        // Arrange — directory has no matching file
        $tester = $this->makeTester();

        // Act
        $code = $tester->execute([
            'seeder' => 'NonExistentSeeder',
            '--path' => $this->seedsDir,
        ]);

        // Assert
        $this->assertSame(1, $code);
        $this->assertStringContainsString('NonExistentSeeder', $tester->getDisplay());
    }

    // =========================================================================
    // Invalid seeder files
    // =========================================================================

    /**
     * A file whose class does not extend Pramnos\Database\Seeder is rejected
     * with an error message and the command returns FAILURE.
     */
    public function testNonSeederClassIsRejected(): void
    {
        // Arrange — class that does NOT extend Seeder
        $this->writeSeederFile('NotASeederClass', throws: false, notSeeder: true);
        $tester = $this->makeTester();

        // Act
        $code = $tester->execute(['--path' => $this->seedsDir]);

        // Assert — failure, error message mentions the class
        $this->assertSame(1, $code);
        $this->assertStringContainsString('NotASeederClass', $tester->getDisplay());
    }

    /**
     * A seeder whose run() throws an exception is recorded as failed.
     * Other seeders in the batch still run (fail-slow, not fail-fast).
     * The overall exit code is FAILURE when at least one seeder failed.
     */
    public function testFailingSeederIsRecordedAndOthersStillRun(): void
    {
        // Arrange — one that throws, one that succeeds
        $this->writeSeederFile('FailingSeeder', throws: true);
        $this->writeSeederFile('SucceedingSeeder');

        if (!class_exists('DbSeedTestLog')) {
            eval('class DbSeedTestLog { public static array $ran = []; }');
        }
        \DbSeedTestLog::$ran = [];

        $tester = $this->makeTester();

        // Act
        $code = $tester->execute(['--path' => $this->seedsDir]);
        $output = $tester->getDisplay();

        // Assert — failure exit, both seeders mentioned, only SucceedingSeeder ran
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Failed', $output);
        $this->assertStringContainsString('FailingSeeder', $output);
        $this->assertStringContainsString('SucceedingSeeder', $output);
        $this->assertStringContainsString('1 seeder(s) failed', $output);
        $this->assertContains('SucceedingSeeder', \DbSeedTestLog::$ran);
        $this->assertNotContains('FailingSeeder', \DbSeedTestLog::$ran);
    }

    // =========================================================================
    // Non-.php files ignored
    // =========================================================================

    /**
     * Non-.php files (e.g. .txt, .md, hidden files) in the seeds directory
     * are silently ignored and do not affect the result.
     */
    public function testNonPhpFilesAreIgnored(): void
    {
        // Arrange — one valid seeder and one stray text file
        $this->writeSeederFile('CleanSeeder');
        file_put_contents($this->seedsDir . '/README.md', '# seeds');
        file_put_contents($this->seedsDir . '/notes.txt', 'ignore me');

        if (!class_exists('DbSeedTestLog')) {
            eval('class DbSeedTestLog { public static array $ran = []; }');
        }
        \DbSeedTestLog::$ran = [];

        $tester = $this->makeTester();

        // Act
        $code = $tester->execute(['--path' => $this->seedsDir]);

        // Assert — only CleanSeeder ran; exit 0
        $this->assertSame(0, $code);
        $this->assertStringContainsString('CleanSeeder', $tester->getDisplay());
        $this->assertStringContainsString('1 seeder(s) ran', $tester->getDisplay());
    }

    // =========================================================================
    // Summary counts
    // =========================================================================

    /**
     * When all seeders fail, the failure count in the summary equals the total
     * number of seeder files found — no "N seeder(s) ran" line is emitted.
     */
    public function testAllFailedShowsOnlyFailureCount(): void
    {
        // Arrange — two seeders both throw
        $this->writeSeederFile('FailOne', throws: true);
        $this->writeSeederFile('FailTwo', throws: true);
        $tester = $this->makeTester();

        // Act
        $code = $tester->execute(['--path' => $this->seedsDir]);
        $output = $tester->getDisplay();

        // Assert — failure, summary mentions count, no "ran successfully" line
        $this->assertSame(1, $code);
        $this->assertStringContainsString('2 seeder(s) failed', $output);
        $this->assertStringNotContainsString('ran successfully', $output);
    }

    // =========================================================================
    // Guard and path-discovery edge cases
    // =========================================================================

    /**
     * When execute() is called without a parent Pramnos\Console\Application the
     * command must report an error and return FAILURE.
     *
     * The guard at the top of execute() checks that getApplication() returns a
     * Pramnos\Console\Application. Running DbSeed inside a bare Symfony Application
     * triggers the false branch, covering lines 48–49.
     */
    public function testNonPramnosApplicationCausesGuardFailure(): void
    {
        // Arrange — register the command in a plain Symfony Application (not Pramnos)
        $symfonyApp = new \Symfony\Component\Console\Application('Test', '0.0');
        $command    = new DbSeed();
        $symfonyApp->add($command);
        $tester = new CommandTester($command);

        // Act
        $code   = $tester->execute(['--path' => $this->seedsDir]);
        $output = $tester->getDisplay();

        // Assert — the guard must fire and return FAILURE
        $this->assertSame(\Symfony\Component\Console\Command\Command::FAILURE, $code,
            'DbSeed must return FAILURE when run outside a Pramnos Console Application');
        $this->assertStringContainsString('must run within the Pramnos console application', $output,
            'The error message must explain why the guard fired');
    }

    /**
     * When no --path option is provided the command must compute the default seeds
     * path via defaultSeedsPath() and proceed normally.
     *
     * The default path (ROOT/database/seeds) will not exist in the test environment,
     * so the command returns SUCCESS after printing a "directory not found" comment.
     * The important contract is that defaultSeedsPath() is called (covering lines 147–150)
     * rather than throwing an error for a missing option.
     */
    public function testDefaultSeedsPathIsUsedWhenNoPathOption(): void
    {
        // Arrange — use the Pramnos application (guard passes) but omit --path
        $tester = $this->makeTester();

        // Act — no --path argument; command must fall back to defaultSeedsPath()
        $code   = $tester->execute([]);
        $output = $tester->getDisplay();

        // Assert — returns SUCCESS (missing directory is not an error) and prints info
        $this->assertSame(\Symfony\Component\Console\Command\Command::SUCCESS, $code,
            'DbSeed must return SUCCESS when the default seeds directory does not exist');
        // The output should mention the directory (either "not found" or "no seeders")
        $this->assertNotEmpty($output,
            'DbSeed must produce output when the seeds directory is absent');
    }

    /**
     * When a PHP file is found but it does not define the expected class, the
     * command must record the class as failed and continue with the remaining seeders.
     *
     * This covers the class_exists() false branch (lines 78–80).
     *
     * Uses a unique class name (with a random suffix) so that a class previously
     * loaded in the same PHP process by another test cannot shadow this test's
     * class_exists() check.
     */
    public function testClassNotFoundInSeederFileIsRecordedAsFailed(): void
    {
        // Arrange — use a unique class name guaranteed not to exist yet
        $uniqueSeeder = 'DbSeedMissingClass' . bin2hex(random_bytes(4));
        $wrongFile    = $this->seedsDir . '/' . $uniqueSeeder . '.php';
        // File defines a DIFFERENT class name — the command will not find $uniqueSeeder
        file_put_contents($wrongFile, "<?php\nclass {$uniqueSeeder}WrongName {}\n");

        $tester = $this->makeTester();

        // Act
        $code   = $tester->execute(['--path' => $this->seedsDir]);
        $output = $tester->getDisplay();

        // Assert — command reports the class as not found, returns failure
        $this->assertSame(\Symfony\Component\Console\Command\Command::FAILURE, $code,
            'DbSeed must return FAILURE when a seeder file does not define the expected class');
        $this->assertStringContainsString('not found', $output,
            'Output must mention that the class was not found in the file');
    }
}
