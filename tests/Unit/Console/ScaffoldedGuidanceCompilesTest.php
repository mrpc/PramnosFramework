<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controller;

/**
 * The guidance a scaffolded project ships is code that runs.
 *
 * WHAT: that the class examples in `CLAUDE.md.stub` — the file `init` writes into every
 *       project — do not redeclare an inherited property with a type the parent does not
 *       have.
 * WHY:  PHP refuses to let a subclass add a type to an untyped inherited property, and it
 *       refuses at **autoload** time. The example showed
 *       `public array $actions = ['display'];` against a parent declaring
 *       `public $actions = array();`, so a controller copied from it died with
 *       `Type of …::$actions must be omitted to match the parent definition` when
 *       `class_exists()` was called — a white screen with a stack trace, not a 404 or a
 *       500 the error handler formats.
 *
 *       This file exists to be copied from. It is the first thing an assistant reads and
 *       the last thing anybody doubts, and every scaffolded project ships the same line.
 */
#[CoversNothing]
class ScaffoldedGuidanceCompilesTest extends TestCase
{
    /**
     * No example types a property the framework leaves untyped.
     *
     * Asked of the class rather than hard-coded, so this flips on its own if the property
     * is ever typed: typing `Controller::$actions` is a legitimate change — the value is
     * always an array — and the day it happens an *untyped* example becomes the fatal
     * instead. Doing both at once is the one combination that is always wrong.
     */
    public function testNoExampleTypesAnUntypedInheritedProperty(): void
    {
        // Arrange — what the framework actually declares.
        $property = new \ReflectionProperty(Controller::class, 'actions');
        $stub     = (string) file_get_contents(
            dirname(__DIR__, 3) . '/scaffolding/templates/CLAUDE.md.stub'
        );

        // Only the code fences: the file also *warns* about the wrong form in prose, and a
        // sweep that could not tell the two apart would forbid the warning.
        $code = '';
        preg_match_all('/```php\n(.*?)```/s', $stub, $blocks);
        foreach ($blocks[1] as $block) {
            $code .= $block . "\n";
        }

        // Act & Assert
        if ($property->hasType()) {
            $this->assertMatchesRegularExpression(
                '/public\s+\w+\s+\$actions\s*=|addaction\(/',
                $code,
                'Controller::$actions is typed now, so an example redeclaring it untyped fatals'
            );

            return;
        }

        $this->assertDoesNotMatchRegularExpression(
            '/public\s+(?:array|string|int|bool|iterable|mixed)\s+\$actions\b/',
            $code,
            'Controller::$actions is untyped, so a subclass may not add a type — '
            . 'a controller copied from this example dies at autoload'
        );
    }

    /**
     * The controller example registers its actions the way the framework does.
     *
     * `addaction()` is not a style preference: it is what every framework controller and
     * every controller `init` writes uses, and it is what the router's dispatcher and the
     * generated "every registered action has a method" test both read. An example that
     * assigns the property instead teaches a second convention for the same thing.
     */
    public function testTheControllerExampleUsesAddaction(): void
    {
        // Arrange
        $stub = (string) file_get_contents(
            dirname(__DIR__, 3) . '/scaffolding/templates/CLAUDE.md.stub'
        );

        // Act & Assert
        $this->assertStringContainsString('$this->addaction([', $stub);
        $this->assertTrue(
            method_exists(Controller::class, 'addaction'),
            'the example calls a method the base controller must have'
        );
    }
}
