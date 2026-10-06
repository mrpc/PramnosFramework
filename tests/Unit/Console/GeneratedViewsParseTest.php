<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\Init;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Every PHP file the scaffold writes has to parse.
 *
 * **This is the one class of defect a template test catches outright**, and nothing was
 * looking for it. `src/Views/account/profile.html.php` shipped with two `foreach (…):`
 * blocks closed by `endif;`, in all three UI systems, so every scaffolded project with the
 * account feature had a page that could not render — `Parse error: syntax error,
 * unexpected token "endif"`.
 *
 * It was not found by opening the page. It was found because **no coverage report could be
 * produced for the whole project**: `php-code-coverage` parses every file in the source
 * filter and dies on the first one it cannot, so a broken view takes the measurement of
 * everything else with it.
 *
 * The check is `php -l` over the generated tree rather than an assertion about any
 * particular template, because the next one will be somewhere else.
 */
#[CoversClass(Init::class)]
class GeneratedViewsParseTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pramnos-parse-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0777, true);
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($this->tmpDir);
    }

    /**
     * The UI systems, because the views are written once per system.
     *
     * The defect this was written for was in all three, which is exactly what a single-
     * system test would have missed — the bootstrap variant is not the one most projects
     * use.
     *
     * @return array<string, array{0: string}>
     */
    public static function provideUiSystems(): array
    {
        return [
            'plain-css' => ['plain-css'],
            'bootstrap' => ['bootstrap'],
            'tailwind'  => ['tailwind'],
        ];
    }

    /**
     * Every generated `.php` file parses.
     *
     * Run through `php -l` in a subprocess rather than `include`, because including a view
     * executes it — these expect a `$this` that is a View and a booted application, and the
     * question here is only whether the file is syntactically PHP.
     */
    #[DataProvider('provideUiSystems')]
    public function testEveryGeneratedPhpFileParses(string $uiSystem): void
    {
        // Arrange & Act — a project with the features that write the most views.
        $command = new Init();
        $command->targetBaseDir = $this->tmpDir;
        $command->skipDockerRun = true;

        $app = new Application();
        $app->add($command);
        (new CommandTester($command))->execute([
            '--app-name'    => 'Parse App',
            '--no-install'  => true,
            '--no-download' => true,
            '--namespace'   => 'ParseApp',
            '--features'    => 'auth,authserver,queue,messaging',
            '--ui-system'   => $uiSystem,
            '--docker'      => 'n',
            '--libraries'   => '',
            '--db-type'     => 'mysql',
            '--db-host'     => 'localhost',
            '--db-name'     => 'parse_db',
            '--db-user'     => 'parse',
            '--db-pass'     => 'pass',
            '--db-prefix'   => '',
        ], ['interactive' => false]);

        // Assert
        $broken = [];
        $checked = 0;

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS)
        );

        $paths = [];
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            // vendor/ is not ours, and --no-install means it should not be here at all.
            if (str_contains($file->getPathname(), '/vendor/')) {
                continue;
            }

            $paths[] = $file->getPathname();
        }
        $checked = count($paths);

        // One `php -l` for many files (PHP 8.3+), and file by file only for a batch that
        // failed, to name the file and its error. A process per file was a second per test.
        foreach (array_chunk($paths, 200) as $batch) {
            exec('php -l ' . implode(' ', array_map('escapeshellarg', $batch)) . ' 2>&1', $ignored, $batchStatus);
            if ($batchStatus === 0) {
                continue;
            }

            foreach ($batch as $path) {
                $output = [];
                $status = 0;
                exec('php -l ' . escapeshellarg($path) . ' 2>&1', $output, $status);

                if ($status !== 0) {
                    $relative = substr($path, strlen($this->tmpDir) + 1);
                    // The message, not the first line: `php -l` prints a blank line before it
                    // in some builds, and a failure that names only the file makes whoever
                    // reads it run the linter again by hand.
                    $message = '';
                    foreach ($output as $line) {
                        if (trim($line) !== '') {
                            $message = trim($line);
                            break;
                        }
                    }
                    $broken[] = $relative . ' — ' . ($message !== '' ? $message : 'unknown error');
                }
            }
        }

        // A scaffold that wrote nothing would pass an empty loop, so the count is asserted
        // too: the views this exists for are a handful of files among a hundred.
        $this->assertGreaterThan(50, $checked, 'the scaffold should have written PHP files');
        $this->assertSame([], $broken, "Generated PHP that does not parse:\n" . implode("\n", $broken));
    }
}
