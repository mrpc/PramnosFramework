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
    protected function setUp(): void
    {
        $this->seedsDir = sys_get_temp_dir() . '/pramnos_seeds_' . bin2hex(random_bytes(4));
        mkdir($this->seedsDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->seedsDir);
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
}
