<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\JsToolCommand;
use Pramnos\Console\Commands\LintJs;
use Pramnos\Console\Commands\TestJs;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `test:js` and `lint:js`, run for real against a project in a temporary directory.
 *
 * An application tests its JavaScript with the framework's runner and helpers rather than copies
 * of them: a test there says `require('pramnos-testing')` and has the DOM, the script loader and
 * the stubs, and `lint:js` holds it to the framework's rules. What has to hold is what that
 * application relies on — the helpers resolve, a failing test fails the command, a defect fails
 * the lint — so the commands run node and ESLint here rather than being asserted on as strings.
 */
#[CoversClass(TestJs::class)]
#[CoversClass(LintJs::class)]
#[CoversClass(JsToolCommand::class)]
class JsToolCommandsTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pf-jstools-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/tests/js', 0777, true);
        mkdir($this->dir . '/app', 0777, true);
    }

    protected function tearDown(): void
    {
        if (!str_starts_with($this->dir, sys_get_temp_dir() . '/pf-jstools-')) {
            return;
        }
        if (is_link($this->dir . '/node_modules')) {
            unlink($this->dir . '/node_modules');
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->dir);
    }

    /** Run a command in the temporary project. */
    private function runCommand(JsToolCommand $command, array $input = []): CommandTester
    {
        $command->targetBaseDir = $this->dir;
        $app = new Application();
        $app->add($command);
        $tester = new CommandTester($command);
        $tester->execute($input);

        return $tester;
    }

    private function write(string $file, string $contents): void
    {
        if (!is_dir(dirname($this->dir . '/' . $file))) {
            mkdir(dirname($this->dir . '/' . $file), 0777, true);
        }
        file_put_contents($this->dir . '/' . $file, $contents);
    }

    /**
     * A test that requires `pramnos-testing` finds it and passes; a failing one fails the command,
     * with node's report on the way out.
     */
    public function testTheTestsRunWithTheHelpers(): void
    {
        // Arrange
        $this->write('tests/js/dom.test.js', "const { test } = require('node:test');\nconst assert = require('node:assert');\n"
            . "const { createDom } = require('pramnos-testing');\n"
            . "test('a dom', () => { const { document } = createDom(); assert.ok(document.body); });\n");

        // Act
        $passing = $this->runCommand(new TestJs());
        $this->write('tests/js/sub/broken.test.js', "require('node:test').test('no', () => { throw new Error('broken on purpose'); });\n");
        $failing = $this->runCommand(new TestJs());

        // Assert
        $this->assertSame(Command::SUCCESS, $passing->getStatusCode(), $passing->getDisplay());
        $this->assertMatchesRegularExpression('/pass 1/', $passing->getDisplay());
        $this->assertSame(Command::FAILURE, $failing->getStatusCode());
        $this->assertStringContainsString('broken on purpose', $failing->getDisplay(), 'a test in a subdirectory ran too');
    }

    /** The directory is `js_tests_dir`, or `--dir`, or the files named; an empty one says so. */
    public function testWhichTestsRun(): void
    {
        // Arrange
        $this->write('app/app.php', "<?php return ['js_tests_dir' => 'www/js-tests'];");
        $this->write('www/js-tests/a.test.js', "require('node:test').test('configured', () => {});\n");
        $this->write('other/b.test.js', "require('node:test').test('by option', () => {});\n");
        $this->write('other/c.test.js', "require('node:test').test('by name', () => {});\n");

        // Act
        $configured = $this->runCommand(new TestJs())->getDisplay();
        $option     = $this->runCommand(new TestJs(), ['--dir' => 'other'])->getDisplay();
        $named      = $this->runCommand(new TestJs(), ['files' => ['other/c.test.js']])->getDisplay();
        $empty      = $this->runCommand(new TestJs(), ['--dir' => 'tests/js']);
        $missing    = $this->runCommand(new TestJs(), ['--dir' => 'no/such/dir']);

        // Assert
        $this->assertStringContainsString('configured', $configured);
        $this->assertMatchesRegularExpression('/pass 2/', $option);
        $this->assertStringContainsString('by name', $named);
        $this->assertStringNotContainsString('by option', $named);
        $this->assertSame(Command::SUCCESS, $empty->getStatusCode());
        $this->assertStringContainsString('No *.test.js under tests/js/', $empty->getDisplay());
        $this->assertStringContainsString('No *.test.js under no/such/dir/', $missing->getDisplay(), 'a directory that is not there is empty');
    }

    /** Without Node where the console runs, both commands say where to run them. */
    public function testWithoutNodeTheyNameTheContainer(): void
    {
        // Arrange
        $noNode = new class extends TestJs {
            protected function has(string $binary): bool { return false; }
        };
        $noNpx = new class extends LintJs {
            protected function has(string $binary): bool { return false; }
        };

        // Act
        $test = $this->runCommand($noNode);
        $lint = $this->runCommand($noNpx);

        // Assert
        $this->assertSame(Command::FAILURE, $test->getStatusCode());
        $this->assertStringContainsString("'node' is not on the PATH", $test->getDisplay());
        $this->assertStringContainsString("'npx' is not on the PATH", $lint->getDisplay());
        $this->assertStringContainsString('./dockerbash', $lint->getDisplay());
    }

    /**
     * The framework's rules find a defect in the web root's scripts and pass clean ones, with the
     * temporary config gone afterwards; an application's own `eslint.config.mjs`, extending the
     * framework's, is the one used.
     */
    public function testLintUsesTheFrameworksRulesOrTheApplicationsOwn(): void
    {
        // Arrange — the project's ESLint is the framework's, so nothing is fetched
        symlink(ROOT . '/node_modules', $this->dir . '/node_modules');
        $this->write('public/index.php', '<?php');
        $this->write('public/assets/js/clean.js', "var ok = document.title;\nwindow.ok = ok;\n");
        $this->write('tests/js/clean.test.js', "const x = require('node:test');\nmodule.exports = x;\n");

        // Act
        $clean = $this->runCommand(new LintJs());
        $this->write('public/assets/js/broken.js', "var twice = 1;\nvar twice = 2;\nwindow.t = twice;\n");
        $broken = $this->runCommand(new LintJs());
        $leftover = is_file($this->dir . '/.pramnos-eslint.config.mjs');
        $this->write('eslint.config.mjs', "import { browser } from " . var_export(TestJs::toolingDir() . '/pramnos-testing/eslint.base.mjs', true) . ";\n"
            . "export default [browser(['public/assets/js/clean.js'])];\n");
        $own = $this->runCommand(new LintJs(), ['paths' => ['public/assets/js/clean.js']]);
        $fix = $this->runCommand(new LintJs(), ['paths' => ['public/assets/js/clean.js'], '--fix' => true]);

        // Assert
        $this->assertSame(Command::SUCCESS, $clean->getStatusCode(), $clean->getDisplay());
        $this->assertSame(Command::FAILURE, $broken->getStatusCode());
        $this->assertStringContainsString('no-redeclare', $broken->getDisplay());
        $this->assertFalse($leftover, 'the temporary config is removed');
        $this->assertSame(Command::SUCCESS, $own->getStatusCode(), $own->getDisplay());
        $this->assertSame(Command::SUCCESS, $fix->getStatusCode(), $fix->getDisplay());
    }

    /** Nothing to lint is said, not an ESLint error about missing paths. */
    public function testNothingToLint(): void
    {
        // Arrange
        rmdir($this->dir . '/tests/js');
        symlink(ROOT . '/node_modules', $this->dir . '/node_modules');

        // Act
        $tester = $this->runCommand(new LintJs());

        // Assert
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('No JavaScript to lint.', $tester->getDisplay());
    }
}
