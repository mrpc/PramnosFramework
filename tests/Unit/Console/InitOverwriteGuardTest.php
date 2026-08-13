<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\Init;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `init` and the directory that already holds an application.
 *
 * Reported as the dangerous finding of a review, and it is: `init` had no
 * `--force`, no `--dry-run` and no already-initialised check, and it writes
 * `app/app.php`, `composer.json`, `CLAUDE.md`, `README.md`, the Docker files,
 * `phpunit.xml` and `src/Console.php` unconditionally. It also drops ~18 stock MVC
 * controllers into `src/Controllers/`, which in an attribute-routed application
 * become **live routes**, because the loader takes whatever is in that directory.
 *
 * None of that is recoverable without version control, and a scaffolding tool is
 * exactly what somebody runs optimistically in the wrong directory.
 *
 * Three things were already non-destructive by design — the `.gitignore` append,
 * the `package.json` merge, the screens-registry edit — so the intent existed. It
 * simply was not applied to the rest.
 */
class InitOverwriteGuardTest extends TestCase
{
    private string $tmpDir;
    private Init   $command;
    private ?string $originalPhpSelf = null;

    protected function setUp(): void
    {
        $this->originalPhpSelf = $_SERVER['PHP_SELF'] ?? null;
        if (!isset($_SERVER['PHP_SELF'])) {
            $_SERVER['PHP_SELF'] = 'phpunit';
        }

        $this->tmpDir = sys_get_temp_dir() . '/pramnos_init_guard_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0777, true);

        $this->command = new Init();
        $this->command->targetBaseDir  = $this->tmpDir;
        $this->command->skipDockerRun  = true;
        $this->command->scaffoldingDir = dirname(__DIR__, 3) . '/scaffolding';
    }

    protected function tearDown(): void
    {
        $this->rmdir($this->tmpDir);

        if ($this->originalPhpSelf === null) {
            unset($_SERVER['PHP_SELF']);
        } else {
            $_SERVER['PHP_SELF'] = $this->originalPhpSelf;
        }
    }

    /**
     * Run init non-interactively, with whatever is under test on top.
     *
     * @param  array<string, string|bool> $options
     * @return CommandTester
     */
    private function runInit(array $options = []): CommandTester
    {
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        $tester->execute(array_merge([
            '--app-name'      => 'GuardApp',
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
            '--namespace'     => 'GuardApp',
            '--features'      => '',
            '--ui-system'     => 'plain-css',
            '--docker'        => 'n',
            '--cache-system'  => 'none',
            '--libraries'     => '',
            '--db-type'       => 'mysql',
            '--db-host'       => 'db',
            '--db-name'       => 'guard_db',
            '--db-user'       => 'guard',
            '--db-pass'       => 'secret',
            '--db-prefix'     => '',
            '--rest-api'      => 'n',
            '--api-docs'      => 'n',
            '--webhook'       => 'n',
            '--app-style'     => 'mvc',
            '--no-migrations' => true,
        ], $options));

        return $tester;
    }

    /**
     * Make this directory look like an application that already exists.
     *
     * `app/app.php` is the marker, and the other two files are here so the
     * assertions can prove they were left alone.
     */
    private function seedExistingProject(): void
    {
        mkdir($this->tmpDir . '/app', 0777, true);
        file_put_contents($this->tmpDir . '/app/app.php', '<?php // the real application');
        file_put_contents($this->tmpDir . '/composer.json', '{"name":"mine/app"}');
        file_put_contents($this->tmpDir . '/CLAUDE.md', '# my own instructions');
    }

    /** Remove a directory tree. */
    private function rmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * An existing application is refused, and nothing at all is touched.
     *
     * The refusal has to come *before* the first question as well as before the
     * first write: an interactive run that asks fifteen questions and then says no
     * is its own kind of unhelpful.
     */
    public function testAnExistingApplicationIsRefusedAndNothingIsOverwritten(): void
    {
        // Arrange
        $this->seedExistingProject();

        // Act
        $tester = $this->runInit();

        // Assert — refused, with a non-zero status a script can act on
        $this->assertSame(1, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('already holds an application', $display);
        $this->assertStringContainsString('app/app.php', $display);
        // The way out is named, both of them
        $this->assertStringContainsString('--dry-run', $display);
        $this->assertStringContainsString('--force', $display);

        // Assert — and every seeded file is exactly as it was
        $this->assertSame('<?php // the real application', file_get_contents($this->tmpDir . '/app/app.php'));
        $this->assertSame('{"name":"mine/app"}', file_get_contents($this->tmpDir . '/composer.json'));
        $this->assertSame('# my own instructions', file_get_contents($this->tmpDir . '/CLAUDE.md'));
        // Nothing was scaffolded beside them either
        $this->assertFileDoesNotExist($this->tmpDir . '/src/Controllers/Home.php');
    }

    /**
     * An empty directory is initialised as before.
     *
     * The guard must not cost the ordinary case anything — this is the command's
     * whole purpose, and it runs on a directory with nothing in it.
     */
    public function testAnEmptyDirectoryIsStillInitialised(): void
    {
        // Act
        $tester = $this->runInit();

        // Assert
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertFileExists($this->tmpDir . '/app/app.php');
        $this->assertStringNotContainsString('already holds an application', $tester->getDisplay());
    }

    /**
     * `--force` proceeds, and says that it is proceeding.
     *
     * Silence here would be worse than the original behaviour: somebody passing
     * `--force` out of habit should be told what it just allowed.
     */
    public function testForceProceedsAndSaysSo(): void
    {
        // Arrange
        $this->seedExistingProject();

        // Act
        $tester = $this->runInit(['--force' => true]);

        // Assert
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('--force was given', $tester->getDisplay());
        // …and it really did overwrite, which is what was asked for
        $this->assertStringNotContainsString(
            'the real application',
            (string) file_get_contents($this->tmpDir . '/app/app.php')
        );
    }

    /**
     * `--dry-run` reports the plan and writes nothing.
     *
     * Including into an existing project: a preview is exactly what somebody wants
     * *there*, so the guard lets a dry run through rather than refusing it.
     */
    public function testDryRunListsWhatItWouldWriteAndWritesNothing(): void
    {
        // Arrange
        $this->seedExistingProject();

        // Act
        $tester = $this->runInit(['--dry-run' => true]);
        $display = $tester->getDisplay();

        // Assert — the plan names both halves
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('nothing was written', $display);
        $this->assertStringContainsString('Would overwrite', $display);
        $this->assertStringContainsString('app/app.php', $display);
        $this->assertStringContainsString('Would create', $display);
        // A file the project does not have yet appears as a creation
        $this->assertStringContainsString('src/Controllers/Home.php', $display);

        // Assert — and the working tree is untouched, which is the whole promise
        $this->assertSame('<?php // the real application', file_get_contents($this->tmpDir . '/app/app.php'));
        $this->assertSame('{"name":"mine/app"}', file_get_contents($this->tmpDir . '/composer.json'));
        $this->assertFileDoesNotExist($this->tmpDir . '/src/Controllers/Home.php');
        $this->assertFileDoesNotExist($this->tmpDir . '/www/index.php');
    }

    /**
     * A dry run leaves the files it does not write through `writeFile()` alone too.
     *
     * `.gitignore` and `package.json` are appended to and merged rather than
     * written, and the assets are downloaded. A flag that stopped the templates but
     * still did those would be a trap rather than a preview — a "dry" run that
     * changes the working tree.
     */
    public function testDryRunDoesNotAppendMergeOrDownload(): void
    {
        // Arrange — a project with the two files init would otherwise edit
        $this->seedExistingProject();
        file_put_contents($this->tmpDir . '/.gitignore', "/vendor\n");
        file_put_contents($this->tmpDir . '/package.json', '{"name":"mine"}');

        // Act
        $this->runInit(['--dry-run' => true, '--app-style' => 'spa', '--spa-stack' => 'svelte']);

        // Assert
        $this->assertSame("/vendor\n", file_get_contents($this->tmpDir . '/.gitignore'));
        $this->assertSame('{"name":"mine"}', file_get_contents($this->tmpDir . '/package.json'));
        $this->assertFileDoesNotExist($this->tmpDir . '/www/assets/css/app.css');
    }

    /**
     * A dry run says which external steps it did not run.
     *
     * "It did not run composer" is part of what the reader is checking, so the
     * commands are printed rather than silently skipped.
     */
    public function testDryRunNamesTheExternalStepsItSkipped(): void
    {
        // Act — the external steps are the subject here, so this is the one test in
        // the class that does not opt out of them. It costs nothing: a dry run prints
        // the commands instead of running them.
        $tester = $this->runInit([
            '--dry-run'    => true,
            '--docker'     => 'n',
            '--no-install' => false,
        ]);

        // Assert
        $display = $tester->getDisplay();
        $this->assertStringContainsString('would run:', $display);
        $this->assertStringContainsString('composer', $display);
        $this->assertStringContainsString('were skipped', $display);
    }
}
