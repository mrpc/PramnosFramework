<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\Init;
use Pramnos\Console\Commands\ScaffoldSpa;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `scaffold:spa` — a front end for an application that already exists.
 *
 * Named the highest-value addition in a consumer's review, and the reason is the
 * gap it closes: the SPA was only reachable through a full `init`, which refuses to
 * run in a project that already has one, and `project:resync` only refreshes files a
 * project already has. So the documented path for "I have an application and want a
 * Svelte front end" was to copy fifteen stubs by hand with the right token
 * substitutions. Somebody did exactly that.
 *
 * The property that makes it usable is that it **cannot damage the project**: every
 * write goes through one funnel in `Init`, and this command sets `skipExisting`, so a
 * file the project already has is left alone and reported as kept. Running it twice
 * does nothing the second time.
 */
#[CoversClass(ScaffoldSpa::class)]
class ScaffoldSpaTest extends TestCase
{
    private string $tmpDir;
    private ScaffoldSpa $command;
    private ?string $originalPhpSelf = null;

    protected function setUp(): void
    {
        $this->originalPhpSelf = $_SERVER['PHP_SELF'] ?? null;
        if (!isset($_SERVER['PHP_SELF'])) {
            $_SERVER['PHP_SELF'] = 'phpunit';
        }

        $this->tmpDir = sys_get_temp_dir() . '/pramnos_scaffold_spa_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir . '/app', 0777, true);
        mkdir($this->tmpDir . '/www', 0777, true);

        $this->command = new ScaffoldSpa();
        // The command resolves the project root from ROOT/getcwd(); the scaffolder is
        // the seam, and it carries the target directory.
        $scaffolder = new Init();
        $scaffolder->targetBaseDir  = $this->tmpDir;
        $scaffolder->scaffoldingDir = dirname(__DIR__, 3) . '/scaffolding';
        $scaffolder->skipDockerRun  = true;
        $this->command->scaffolder  = $scaffolder;
        $this->command->projectRoot = $this->tmpDir;
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

    /** An application that exists: an app.php with a name and a namespace. */
    private function seedProject(string $extra = ''): void
    {
        file_put_contents(
            $this->tmpDir . '/app/app.php',
            "<?php\nreturn [\n    'name' => 'Existing',\n    'namespace' => 'Existing',\n"
            . $extra
            . "];\n"
        );
    }

    /**
     * @param  array<string, string|bool> $options
     * @return CommandTester
     */
    private function scaffold(array $options = []): CommandTester
    {
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);
        $tester->execute($options, ['interactive' => false]);

        return $tester;
    }

    /** Read a scaffolded file. */
    private function read(string $path): string
    {
        $full = $this->tmpDir . '/' . $path;
        $this->assertFileExists($full, "expected $path to be scaffolded");
        return (string) file_get_contents($full);
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
     * A front end appears in a project that had none, from the same stubs `init`
     * uses.
     */
    public function testItAddsAFrontEndToAnExistingProject(): void
    {
        // Arrange
        $this->seedProject();

        // Act
        $tester = $this->scaffold(['--spa-stack' => 'svelte']);

        // Assert
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('mount(App', $this->read('frontend/main.js'));
        $this->assertStringContainsString('$state(', $this->read('frontend/App.svelte'));
        $this->assertStringContainsString('record', $this->read('frontend/lib/debug.js'));
        $this->assertFileExists($this->tmpDir . '/vite.config.js');
        $this->assertFileExists($this->tmpDir . '/www/spa.php');
    }

    /**
     * The style is recorded, or the rest of the tooling ignores the front end.
     *
     * `spa:dev`, `spa:build` and `project:resync` all read `app_style` and
     * `spa_stack` from `app/app.php`. Without them the front end exists and every
     * command that should help with it reports that the project has none.
     */
    public function testItRecordsTheStyleInAppPhp(): void
    {
        // Arrange
        $this->seedProject();

        // Act
        $this->scaffold(['--spa-stack' => 'svelte']);

        // Assert
        $config = $this->read('app/app.php');
        $this->assertStringContainsString("'app_style' => 'spa'", $config);
        $this->assertStringContainsString("'spa_stack' => 'svelte'", $config);
        // The project's own keys survive the edit
        $this->assertStringContainsString("'name' => 'Existing'", $config);
    }

    /**
     * Nothing the project already has is touched.
     *
     * The whole reason this command can exist. A file that is already there is left
     * byte-for-byte and reported as kept, so a reader can see what was theirs.
     */
    public function testItNeverOverwritesAFileTheProjectAlreadyHas(): void
    {
        // Arrange — a project with its own shell and its own API client
        $this->seedProject();
        mkdir($this->tmpDir . '/frontend/lib', 0777, true);
        file_put_contents($this->tmpDir . '/www/spa.php', '<?php // mine');
        file_put_contents($this->tmpDir . '/frontend/lib/api.js', '// my own client');

        // Act
        $tester = $this->scaffold(['--spa-stack' => 'svelte']);

        // Assert — untouched, and named as kept
        $this->assertSame('<?php // mine', $this->read('www/spa.php'));
        $this->assertSame('// my own client', $this->read('frontend/lib/api.js'));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('kept', $display);
        $this->assertStringContainsString('www/spa.php', $display);
        $this->assertStringContainsString('(yours)', $display);
    }

    /**
     * Running it twice does nothing the second time.
     *
     * Which is what makes it safe to run when you are not sure whether you already
     * did.
     */
    public function testASecondRunChangesNothing(): void
    {
        // Arrange
        $this->seedProject();
        $this->scaffold(['--spa-stack' => 'svelte']);
        $before = $this->read('frontend/main.js');

        // Act — a fresh scaffolder, as a second invocation would have
        $second = new Init();
        $second->targetBaseDir  = $this->tmpDir;
        $second->scaffoldingDir = dirname(__DIR__, 3) . '/scaffolding';
        $second->skipDockerRun  = true;
        $this->command->scaffolder = $second;
        $tester = $this->scaffold(['--spa-stack' => 'svelte']);

        // Assert
        $this->assertSame($before, $this->read('frontend/main.js'));
        $this->assertStringContainsString('0 created', $tester->getDisplay());
    }

    /**
     * `--dry-run` writes nothing at all.
     */
    public function testDryRunWritesNothing(): void
    {
        // Arrange
        $this->seedProject();

        // Act
        $tester = $this->scaffold(['--spa-stack' => 'svelte', '--dry-run' => true]);

        // Assert
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('nothing was written', $tester->getDisplay());
        $this->assertFileDoesNotExist($this->tmpDir . '/frontend/main.js');
        $this->assertFileDoesNotExist($this->tmpDir . '/vite.config.js');
    }

    /**
     * A directory that is not a project is refused, and says why.
     */
    public function testItRefusesADirectoryThatIsNotAProject(): void
    {
        // Arrange — no app/app.php
        // Act
        $tester = $this->scaffold(['--spa-stack' => 'svelte']);

        // Assert
        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('No app/app.php', $tester->getDisplay());
    }

    /**
     * An unknown stack is refused before anything is written.
     */
    public function testAnUnknownStackIsRefused(): void
    {
        // Arrange
        $this->seedProject();

        // Act
        $tester = $this->scaffold(['--spa-stack' => 'angular']);

        // Assert
        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('must be svelte', $tester->getDisplay());
        $this->assertFileDoesNotExist($this->tmpDir . '/frontend/main.js');
    }

    /**
     * A project that already declares a style keeps it.
     *
     * Somebody re-running this to add a missing file must not have their hybrid
     * mounting silently changed to a root-mounted SPA.
     */
    public function testADeclaredStyleIsKept(): void
    {
        // Arrange
        $this->seedProject("    'app_style' => 'hybrid',\n    'spa_stack' => 'svelte',\n");

        // Act
        $tester = $this->scaffold();

        // Assert — the hybrid shell, not the root one
        $this->assertStringContainsString('hybrid', $tester->getDisplay());
        $this->assertFileExists($this->tmpDir . '/www/app.php');
        // …and app.php is not edited again, because the keys are already there
        $this->assertSame(
            1,
            substr_count($this->read('app/app.php'), "'app_style'"),
            'the style is recorded once'
        );
    }
}
