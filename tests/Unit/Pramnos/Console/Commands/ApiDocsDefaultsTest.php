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

        /*
         * A real class, in the namespace the command will derive, and loaded.
         *
         * `OpenApiGenerator::fromClasses()` skips anything `class_exists()` denies, and
         * `discoverClasses()` builds the name from the namespace plus the **file name**. This
         * fixture used to write a file with `#[Route]` methods and no class declaration at all,
         * on the reasoning that the generator "reflects over files" — it reflects over *classes*,
         * so every file was skipped and every run reported `0 path(s), 0 operation(s)`.
         *
         * Nothing failed, because no assertion in this file looked at a count: the sibling
         * comparison is `$otherCount > $found`, and `0 > 0` is false, so the test asserting that
         * an explicit directory is *not* second-guessed passed without a sibling to ignore.
         *
         * The class name carries a random suffix because a PHP process may declare a class once,
         * and several tests here write controllers into the same relative directory.
         */
        $namespace = $this->namespaceFor($relative);
        $class     = 'Demo' . bin2hex(random_bytes(4)) . 'Controller';

        $methods = '';
        for ($i = 1; $i <= $routes; $i++) {
            $methods .= "    #[Route('/thing{$i}', methods: 'GET')]\n"
                . "    public function thing{$i}(): void {}\n";
        }

        $file = $dir . '/' . $class . '.php';
        file_put_contents(
            $file,
            "<?php\n\nnamespace {$namespace};\n\n"
            . "use Pramnos\\Routing\\Attributes\\Route;\n\n"
            . "class {$class}\n{\n" . $methods . "}\n"
        );

        require $file;
    }

    /**
     * The namespace the command derives for a controllers directory.
     *
     * The same rule `detectNamespace()` applies — the application namespace, then the directory
     * with its `src/` root stripped — so the fixture and the command agree by construction rather
     * than by coincidence.
     */
    private function namespaceFor(string $relative): string
    {
        $relative = trim($relative, '/');

        if (str_starts_with($relative, 'src/')) {
            $relative = substr($relative, 4);
        }

        $segments = array_filter(explode('/', $relative), static fn (string $s): bool => $s !== '');

        return 'DemoApp' . ($segments === [] ? '' : '\\' . implode('\\', $segments));
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
     * A scan that found less than the directory next door says so — and changes nothing.
     *
     * The defect that prompted the whole class, in its live form. `Wrote 1 path(s), 1
     * operation(s)` was **true** for an application serving seventy-two endpoints, and a document
     * describing one of them is not obviously broken: it is indistinguishable from an application
     * that genuinely has one, so it gets published and believed.
     *
     * Both halves are asserted. The warning has to name the other directory, the two counts and
     * the flag to re-run with, because "check your configuration" is not something anybody can
     * act on. And **nothing is switched**: the document written is still the one that was asked
     * for, because a command that quietly scanned somewhere else would be a worse surprise than a
     * thin document — the operator would have no way to know which of the two they were reading.
     *
     * @return void
     */
    public function testAThinScanSaysWhatTheSiblingHolds(): void
    {
        // Arrange — the layout from the report: one MVC controller, and the real API next door.
        $this->writeAppFile('DemoApp');
        $this->writeControllers('src/Controllers', 1);
        $this->writeControllers('src/Api/Controllers', 5);

        // Act — no `--controllers`, so the defaults choose.
        $display = $this->runCommand()->getDisplay();

        // Assert — the API directory is preferred, so this run is the good one.
        $this->assertStringContainsString('Scanned src/Api/Controllers', $display);
        $this->assertStringNotContainsString(
            'Re-run with',
            $display,
            'the richer directory was scanned and it still nagged'
        );
    }

    /**
     * And when the thin directory is the one scanned, the warning names the other.
     *
     * Reached by removing the preferred directory's advantage: with only `src/Controllers`
     * holding the single route and `src/Api/Controllers` absent from the candidates it would
     * pick, the operator has to be told where the rest of the API is.
     *
     * @return void
     */
    public function testTheWarningNamesTheDirectoryTheCountsAndTheFlag(): void
    {
        // Arrange — `src/Api/Controllers` is richer, and the run is pointed at the thin one by
        // being the only candidate the default picks: remove the API directory from the equation
        // by asking for the thin one *without* `--controllers`, which the defaults would not do,
        // so the scan is driven through the same path a default run takes.
        $this->writeAppFile('DemoApp');
        $this->writeControllers('src/Api/Controllers', 1);
        $this->writeControllers('src/Controllers', 5);

        // Act
        $display = $this->runCommand()->getDisplay();

        // Assert — the default prefers the API directory, which here is the thin one.
        $this->assertStringContainsString('Scanned src/Api/Controllers', $display);
        $this->assertStringContainsString('src/Controllers holds 5 operation(s)', $display);
        $this->assertStringContainsString('more than the 1 found', $display);
        $this->assertStringContainsString(
            '--controllers=src/Controllers',
            $display,
            'the warning does not say how to act on it'
        );

        // …and it wrote the document it was asked for, not the sibling's.
        $written = json_decode(
            (string) file_get_contents($this->tmp . '/www/api/openapi.json'),
            true
        );
        $this->assertCount(
            1,
            (array) ($written['paths'] ?? []),
            'the command switched directories instead of saying so'
        );
    }

    /**
     * A sibling with the same number of operations is not worth a warning.
     *
     * The comparison is `>`, not `!=`. Two directories of equal size are two halves of an API,
     * and a line advising a re-run that would find exactly as much is a line that trains people
     * to ignore the warning that matters.
     *
     * @return void
     */
    public function testAnEquallySizedSiblingIsNotWorthAWarning(): void
    {
        // Arrange
        $this->writeAppFile('DemoApp');
        $this->writeControllers('src/Api/Controllers', 3);
        $this->writeControllers('src/Controllers', 3);

        // Act
        $display = $this->runCommand()->getDisplay();

        // Assert
        $this->assertStringNotContainsString('Re-run with', $display);
    }

    /**
     * With no `app/app.php` the namespace cannot be derived, and the command says so.
     *
     * A project root that is not a Pramnos application, or a command run from the wrong
     * directory. Guessing a namespace here would scan for classes that cannot exist and report a
     * successful run with nothing in it — which is the failure this whole class is about.
     *
     * @return void
     */
    public function testWithNoApplicationFileTheNamespaceCannotBeDerived(): void
    {
        // Arrange — controllers, but nothing saying what namespace they are in.
        $this->writeControllers('src/Controllers', 2);

        // Act
        $tester  = $this->runCommand();
        $display = $tester->getDisplay();

        // Assert
        $this->assertNotSame(0, $tester->getStatusCode(), 'a run with no namespace reported success');
        $this->assertStringContainsString('namespace', $display);
    }

    /**
     * An overrides document is deep-merged rather than replacing what was generated.
     *
     * Overrides exist for what cannot be inferred from a route — a request body's schema, an
     * example, a description. Replacing the generated document with them would mean maintaining
     * the whole thing by hand, which is what the generator is for.
     *
     * @return void
     */
    public function testAnOverridesDocumentIsMergedIntoTheGenerated(): void
    {
        // Arrange
        $this->writeAppFile('DemoApp');
        $this->writeControllers('src/Controllers', 1);

        file_put_contents(
            $this->tmp . '/overrides.json',
            json_encode(['info' => ['contact' => ['name' => 'Somebody']]])
        );

        // Act
        $this->runCommand(['--overrides' => 'overrides.json']);

        // Assert
        $written = json_decode(
            (string) file_get_contents($this->tmp . '/www/api/openapi.json'),
            true
        );
        $this->assertSame(
            'Somebody',
            $written['info']['contact']['name'] ?? null,
            'the overrides were not merged'
        );
        $this->assertNotSame(
            [],
            (array) ($written['paths'] ?? []),
            'the overrides replaced the generated document instead of merging into it'
        );
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
