<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Console\Commands;

use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\ApiDocs;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `api:docs` looks where the API is, writes where the site is served, and says which.
 *
 * A consumer ran it against an application serving **72** endpoints and got
 * `Wrote 1 path(s), 1 operation(s)`. Nothing in that line was false. Two defaults
 * were wrong for that layout and neither appeared in the output: it scanned
 * `src/Controllers`, which in that application holds the single *MVC* controller,
 * and wrote into `www/`, which that application does not serve.
 *
 * The scan is the half worth the test class. A file written to the wrong place is
 * noticed the first time somebody opens it; a document describing one endpoint of
 * seventy-two is **published and believed**, because it is indistinguishable from an
 * application that genuinely has one endpoint.
 *
 * A fifth defect turned up while fixing it, and it is why the documented workaround
 * did not work either: `detectNamespace()` appended a fixed `\Controllers`, so
 * `--controllers=src/Api/Controllers` without `--namespace` looked for
 * `App\Controllers\*` inside `src/Api/Controllers`, found nothing, and reported a
 * successful run with zero operations.
 */
class ApiDocsDefaultsTest extends TestCase
{
    /** @var string Temporary project root */
    private string $tmp;

    /**
     * Creates an empty project root.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/apidocs_def_' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0775, true);
    }

    /**
     * Removes the temporary tree.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->removeTree($this->tmp);
    }

    /**
     * Removes a directory tree without spawning a process.
     *
     * `exec('rm -rf …')` is one fork per test. It is small — measured at a few
     * milliseconds — but it is also avoidable, the file next door already does it
     * this way, and the suite's runtime is something this project measures rather
     * than assumes.
     *
     * @param  string $dir Directory to remove
     * @return void
     */
    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * Writes `app/app.php` so the namespace can be detected.
     *
     * @param  string $namespace Application namespace
     * @return void
     */
    private function writeAppFile(string $namespace = 'DemoApp'): void
    {
        mkdir($this->tmp . '/app', 0775, true);
        file_put_contents(
            $this->tmp . '/app/app.php',
            "<?php return ['namespace' => '{$namespace}'];\n"
        );
    }

    /**
     * Creates a controllers directory holding one attribute-routed controller.
     *
     * The classes are never autoloaded by these tests — the generator reflects over
     * files — so what matters is that the directory exists and is scannable.
     *
     * @param  string $relative Directory relative to the project root
     * @param  int    $routes   How many `#[Route]` methods to emit
     * @return void
     */
    private function writeControllers(string $relative, int $routes): void
    {
        $dir = $this->tmp . '/' . $relative;
        mkdir($dir, 0775, true);

        $methods = '';
        for ($i = 1; $i <= $routes; $i++) {
            $methods .= "    #[Route('/thing{$i}', methods: 'GET')]\n"
                . "    public function thing{$i}(): array { return []; }\n";
        }

        file_put_contents($dir . '/DemoController.php', "<?php\n" . $methods);
    }

    /**
     * Runs the command.
     *
     * @param  array<string, mixed> $options Options to pass
     * @return CommandTester
     */
    private function runCommand(array $options = []): CommandTester
    {
        $command                = new ApiDocs();
        $command->targetBaseDir = $this->tmp;
        $tester                 = new CommandTester($command);
        $tester->execute($options + ['--no-html' => true]);

        return $tester;
    }

    /**
     * Calls a private method for the cases that are about a decision, not an outcome.
     *
     * @param  string $method Method name
     * @param  mixed  ...$args Arguments
     * @return mixed
     */
    private function call(string $method, ...$args)
    {
        $command                = new ApiDocs();
        $command->targetBaseDir = $this->tmp;

        return (new \ReflectionMethod($command, $method))->invokeArgs($command, $args);
    }

    /**
     * The namespace follows the directory instead of a fixed `\Controllers`.
     *
     * This is the defect that made the documented workaround useless.
     *
     * @return void
     */
    public function testNamespaceFollowsTheControllersDirectory(): void
    {
        // Arrange
        $this->writeAppFile('DemoApp');

        // Act & Assert
        $this->assertSame(
            'DemoApp\\Api\\Controllers',
            $this->call('detectNamespace', $this->tmp, 'src/Api/Controllers')
        );
    }

    /**
     * The historical layout still resolves exactly as it always did.
     *
     * The guard against fixing one application by breaking every other one.
     *
     * @return void
     */
    public function testTheHistoricalNamespaceIsUnchanged(): void
    {
        // Arrange
        $this->writeAppFile('DemoApp');

        // Act & Assert
        $this->assertSame(
            'DemoApp\\Controllers',
            $this->call('detectNamespace', $this->tmp, 'src/Controllers')
        );
    }

    /**
     * With both directories present, the API one is the default.
     *
     * @return void
     */
    public function testTheApiDirectoryIsPreferredWhenBothExist(): void
    {
        // Arrange
        $this->writeControllers('src/Controllers', 1);
        $this->writeControllers('src/Api/Controllers', 3);

        // Act & Assert
        $this->assertSame('src/Api/Controllers', $this->call('defaultControllersDir', $this->tmp));
    }

    /**
     * An application with only the historical layout is unaffected.
     *
     * @return void
     */
    public function testTheHistoricalDirectoryIsUsedWhenItIsTheOnlyOne(): void
    {
        // Arrange
        $this->writeControllers('src/Controllers', 1);

        // Act & Assert
        $this->assertSame('src/Controllers', $this->call('defaultControllersDir', $this->tmp));
    }

    /**
     * The output goes under whichever document root the project actually has.
     *
     * `www/` was hardcoded, which stopped being right the moment `pramnos init`
     * grew `--web-root`: a project scaffolded with `--web-root=public` had this
     * command create a `www/` beside it, served by nothing.
     *
     * @return void
     */
    public function testTheOutputFollowsTheDocumentRoot(): void
    {
        // Arrange
        mkdir($this->tmp . '/public', 0775, true);
        file_put_contents($this->tmp . '/public/index.php', '<?php');

        // Act & Assert
        $this->assertSame('public/api/openapi.json', $this->call('defaultOutputFile', $this->tmp));
    }

    /**
     * With no recognisable document root, the historical default is kept.
     *
     * Guessing something else would move the file for every project that has always
     * worked.
     *
     * @return void
     */
    public function testTheOutputFallsBackToWww(): void
    {
        // Act & Assert
        $this->assertSame('www/api/openapi.json', $this->call('defaultOutputFile', $this->tmp));
    }

    /**
     * A document root is a directory with a front controller in it.
     *
     * An empty `public/` left over from something else must not win over a `www/`
     * that is actually serving the site.
     *
     * @return void
     */
    public function testAnEmptyDirectoryIsNotTreatedAsADocumentRoot(): void
    {
        // Arrange — public exists but serves nothing; www has the front controller
        mkdir($this->tmp . '/public', 0775, true);
        mkdir($this->tmp . '/www', 0775, true);
        file_put_contents($this->tmp . '/www/index.php', '<?php');

        // Act & Assert
        $this->assertSame('www/api/openapi.json', $this->call('defaultOutputFile', $this->tmp));
    }

    /**
     * The success output names what was scanned, not only where it wrote.
     *
     * This is the line whose absence cost the consumer an hour: `Wrote 1 path(s)`
     * was true and told them nothing about *why* it was one.
     *
     * @return void
     */
    public function testTheOutputNamesWhatWasScanned(): void
    {
        // Arrange
        $this->writeAppFile('DemoApp');
        $this->writeControllers('src/Controllers', 1);

        // Act
        $display = $this->runCommand()->getDisplay();

        // Assert
        $this->assertStringContainsString('Scanned src/Controllers', $display);
        $this->assertStringContainsString('namespace DemoApp\\Controllers', $display);
    }

    /**
     * A directory named explicitly is scanned without second-guessing.
     *
     * Somebody who passed `--controllers` has said where to look, and switching
     * under them — or nagging about a sibling — would be the framework overriding a
     * decision that was made deliberately.
     *
     * @return void
     */
    public function testAnExplicitDirectoryIsNotSecondGuessed(): void
    {
        // Arrange — a richer sibling exists and must be ignored
        $this->writeAppFile('DemoApp');
        $this->writeControllers('src/Controllers', 1);
        $this->writeControllers('src/Api/Controllers', 5);

        // Act
        $display = $this->runCommand(['--controllers' => 'src/Controllers'])->getDisplay();

        // Assert
        $this->assertStringContainsString('Scanned src/Controllers', $display);
        $this->assertStringNotContainsString('Re-run with', $display);
    }
}
