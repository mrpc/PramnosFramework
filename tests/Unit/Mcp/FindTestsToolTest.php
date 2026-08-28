<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Mcp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Mcp\Tools\FindTestsTool;

/**
 * `find-tests` — where the test for this is.
 *
 * Guessed from the class name, that question has a wrong answer often enough to matter:
 * `Pramnos\Logs\LogManager` is tested in `tests/Unit/Pramnos/Logs/`, not `tests/Unit/Logs/`,
 * and writing to a directory that does not exist puts a new test somewhere nobody looks. So it
 * is read from the `#[CoversClass]` attributes, which is where the answer actually is.
 *
 * It reports the command and runs nothing. Running tests is something a shell does well, and
 * wrapping it would hide the project's rule about *how*: these projects hold a lock, and two
 * concurrent runs corrupt the shared test databases.
 */
#[CoversClass(FindTestsTool::class)]
class FindTestsToolTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/find-tests-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/tests/Unit/Deeply/Nested', 0777, true);
        mkdir($this->root . '/src/Thing', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);

        parent::tearDown();
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }

        foreach ((array) scandir($path) as $entry) {
            if (is_string($entry) && $entry !== '.' && $entry !== '..') {
                $this->remove($path . '/' . $entry);
            }
        }

        @rmdir($path);
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->root . '/' . $relative;

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, $contents);
    }

    /** @return array<string, mixed> */
    private function ask(array $input): array
    {
        /** @var array<string, mixed> $answer */
        $answer = (new FindTestsTool($this->root))->execute($input);

        return $answer;
    }

    /**
     * The test is found by its declaration, not by where its name suggests it lives.
     *
     * The whole point: this fixture puts the test three directories away from anything a
     * filename convention would predict, which is the situation that made the tool necessary.
     */
    public function testTheTestIsFoundWhereverItLives(): void
    {
        // Arrange
        $this->write('tests/Unit/Deeply/Nested/WidgetTest.php', <<<'CODE'
        <?php
        namespace App\Tests;
        use PHPUnit\Framework\Attributes\CoversClass;
        #[CoversClass(\App\Thing\Widget::class)]
        class WidgetTest extends \PHPUnit\Framework\TestCase
        {
            public function testOne(): void {}
            public function testTwo(): void {}
        }
        CODE);

        // Act
        $answer = $this->ask(['class' => 'Widget']);

        // Assert
        $this->assertTrue($answer['covered']);
        $this->assertSame(
            'tests/Unit/Deeply/Nested/WidgetTest.php',
            $answer['coveredBy'][0]['tests'][0]['file']
        );
        $this->assertSame('App\Tests\WidgetTest', $answer['coveredBy'][0]['tests'][0]['class']);
        $this->assertSame(2, $answer['coveredBy'][0]['tests'][0]['methods']);
    }

    /**
     * A fully-qualified name finds the same thing as a short one.
     */
    public function testAQualifiedNameWorksToo(): void
    {
        // Arrange
        $this->write('tests/Unit/WidgetTest.php', <<<'CODE'
        <?php
        #[\PHPUnit\Framework\Attributes\CoversClass(\App\Thing\Widget::class)]
        class WidgetTest extends \PHPUnit\Framework\TestCase { public function testA(): void {} }
        CODE);

        // Act & Assert
        $this->assertTrue($this->ask(['class' => 'App\Thing\Widget'])['covered']);
        $this->assertTrue($this->ask(['class' => '\App\Thing\Widget'])['covered']);
    }

    /**
     * The old `@covers` annotation counts as a declaration.
     *
     * Both spellings exist in a codebase with history, and answering "untested" for a class
     * covered the old way would be wrong in the direction that gets code deleted.
     */
    public function testTheAnnotationFormIsRead(): void
    {
        // Arrange
        $this->write('tests/Unit/LegacyTest.php', <<<'CODE'
        <?php
        /**
         * @covers \App\Thing\Widget
         */
        class LegacyTest extends \PHPUnit\Framework\TestCase { public function testA(): void {} }
        CODE);

        // Act & Assert
        $this->assertTrue($this->ask(['class' => 'Widget'])['covered']);
    }

    /**
     * The command runs **every** matching test class, not the first one found.
     *
     * `--filter` takes a regular expression, so the set is an alternation. Naming one of three
     * was worse than useless: it looks like the command that verifies a change and silently
     * skips two thirds of the evidence.
     */
    public function testTheCommandRunsEveryMatchingTest(): void
    {
        // Arrange
        foreach (['AlphaTest', 'BetaTest'] as $name) {
            $this->write('tests/Unit/' . $name . '.php', <<<CODE
            <?php
            #[\\PHPUnit\\Framework\\Attributes\\CoversClass(\\App\\Thing\\Widget::class)]
            class {$name} extends \\PHPUnit\\Framework\\TestCase { public function testA(): void {} }
            CODE);
        }

        // Act
        $command = $this->ask(['class' => 'Widget'])['command'];

        // Assert
        $this->assertStringContainsString('AlphaTest', $command);
        $this->assertStringContainsString('BetaTest', $command);
        $this->assertStringContainsString("'", $command, 'an alternation has to be quoted');
    }

    /**
     * The project's own runner is used when it has one.
     *
     * `./dockertest` holds a lock; two concurrent runs corrupt the shared test databases. A
     * tool that printed `vendor/bin/phpunit` would be advising somebody to break that.
     */
    public function testTheProjectsOwnRunnerIsNamed(): void
    {
        // Arrange
        $this->write('tests/Unit/WidgetTest.php', <<<'CODE'
        <?php
        #[\PHPUnit\Framework\Attributes\CoversClass(\App\Thing\Widget::class)]
        class WidgetTest extends \PHPUnit\Framework\TestCase { public function testA(): void {} }
        CODE);

        // Act — without the script, then with it
        $without = $this->ask(['class' => 'Widget'])['command'];
        $this->write('dockertest', "#!/bin/sh\n");
        $with = $this->ask(['class' => 'Widget'])['command'];

        // Assert
        $this->assertStringContainsString('vendor/bin/phpunit', $without);
        $this->assertStringStartsWith('./dockertest', $with);
    }

    /**
     * An undeclared class says so, and points at what mentions it.
     *
     * Both halves matter. "No `#[CoversClass]`" is a fact about declarations, not about
     * testing — and the file that merely names the class is where somebody would start. On the
     * real repository this found `SeoTest.php` covering `Seo` without declaring it, which is a
     * gap in the *test*, not in the coverage.
     */
    public function testAnUndeclaredClassIsHonestAboutWhatThatMeans(): void
    {
        // Arrange — a test that exercises the class without declaring coverage
        $this->write('tests/Unit/WidgetBehaviourTest.php', <<<'CODE'
        <?php
        class WidgetBehaviourTest extends \PHPUnit\Framework\TestCase
        {
            public function testA(): void { $widget = new \App\Thing\Widget(); }
        }
        CODE);

        // Act
        $answer = $this->ask(['class' => 'Widget']);

        // Assert
        $this->assertFalse($answer['covered']);
        $this->assertContains('tests/Unit/WidgetBehaviourTest.php', $answer['mentioned_in']);
        $this->assertStringContainsString('not proof it is untested', $answer['note']);
    }

    /**
     * `uncovered` lists the classes nothing declares coverage of.
     */
    public function testUncoveredListsTheUndeclaredClasses(): void
    {
        // Arrange
        $this->write('src/Thing/Widget.php', '<?php namespace App\Thing; class Widget {}');
        $this->write('src/Thing/Gadget.php', '<?php namespace App\Thing; class Gadget {}');
        $this->write('tests/Unit/WidgetTest.php', <<<'CODE'
        <?php
        #[\PHPUnit\Framework\Attributes\CoversClass(\App\Thing\Widget::class)]
        class WidgetTest extends \PHPUnit\Framework\TestCase { public function testA(): void {} }
        CODE);

        // Act
        $answer = $this->ask(['uncovered' => true, 'path' => 'src/Thing']);

        // Assert
        $classes = array_column($answer['uncovered'], 'class');
        $this->assertContains('App\Thing\Gadget', $classes);
        $this->assertNotContains('App\Thing\Widget', $classes);
        $this->assertSame(1, $answer['count']);
    }

    /**
     * With neither a class nor `uncovered`, it says what to ask for.
     */
    public function testWithNoArgumentsItSaysWhatToAsk(): void
    {
        // Act
        $answer = $this->ask([]);

        // Assert
        $this->assertStringContainsString('class name is required', $answer['error']);
        $this->assertArrayHasKey('command', $answer['tests']);
    }

    /**
     * The description tells a caller to ask before writing a test.
     */
    public function testTheDescriptionSaysWhenToUseIt(): void
    {
        // Arrange
        $tool = new FindTestsTool($this->root);

        // Assert
        $this->assertSame('find-tests', $tool->name());
        $this->assertStringContainsString('before writing a test', $tool->description());
        $this->assertStringContainsString('Does not run anything', $tool->description());
    }
}
