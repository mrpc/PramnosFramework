<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Html;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Html\Form\FieldStyles;
use Pramnos\Html\Form\SettingsForm;

/**
 * A form takes its look from what the application already declared.
 *
 * `app/app.php` carries `scaffold_theme` — the UI framework a project was generated against, in
 * the same vocabulary `FieldStyles` is keyed by. It was read by `Controller` to resolve views and
 * by `ScaffoldingHelper`, and nothing connected it to the presets.
 *
 * So a caller had to name the theme at every call site, and the default when they forgot was
 * `plain`. A Tailwind project that omitted the argument rendered forms with inline styles instead
 * of utilities: a wrong look, no error, on a page nobody rechecks because the form works.
 */
#[CoversClass(FieldStyles::class)]
#[CoversClass(SettingsForm::class)]
class FieldStylesConfiguredTest extends TestCase
{
    private mixed $previous = null;

    protected function setUp(): void
    {
        $app = Application::getInstance();
        $this->previous = $app->applicationInfo['scaffold_theme'] ?? null;
    }

    protected function tearDown(): void
    {
        $app = Application::getInstance();

        if ($this->previous === null) {
            unset($app->applicationInfo['scaffold_theme']);
        } else {
            $app->applicationInfo['scaffold_theme'] = $this->previous;
        }
    }

    private function declare(?string $theme): void
    {
        $app = Application::getInstance();

        if ($theme === null) {
            unset($app->applicationInfo['scaffold_theme']);

            return;
        }

        $app->applicationInfo['scaffold_theme'] = $theme;
    }

    /**
     * The declared framework is what a form uses when nobody names one.
     */
    public function testTheDeclaredFrameworkIsTheDefault(): void
    {
        // Arrange
        $this->declare('tailwind');

        // Assert
        $this->assertSame('tailwind', FieldStyles::configured());
        $this->assertSame('tailwind', (new SettingsForm('s'))->theme);
    }

    /**
     * A caller who names one still wins.
     *
     * A derived default is a default, not a decision — the same rule as `og_title` falling back to
     * the page title.
     */
    public function testAnExplicitThemeOverridesTheDeclaredOne(): void
    {
        // Arrange
        $this->declare('tailwind');

        // Assert
        $this->assertSame('bootstrap', (new SettingsForm('s', 'bootstrap'))->theme);
    }

    /**
     * Nothing declared falls back to `plain`, which renders everywhere.
     */
    public function testNothingDeclaredFallsBackToPlain(): void
    {
        // Arrange
        $this->declare(null);

        // Assert
        $this->assertSame('plain', FieldStyles::configured());
    }

    /**
     * A framework this class has no preset for is not honoured.
     *
     * `scaffold_theme` names a scaffolding directory, and that set can grow past the presets here.
     * Passing an unknown key through would reach `for()` and be silently coerced anyway; refusing
     * it up front keeps the fallback in one place and the answer predictable.
     */
    public function testAnUnknownFrameworkFallsBackRatherThanPropagating(): void
    {
        // Arrange
        $this->declare('some-future-css-framework');

        // Assert
        $this->assertSame('plain', FieldStyles::configured());
    }

    /**
     * And the presets it does have are the vocabulary `scaffold_theme` uses.
     *
     * If the two ever diverge, every project on the missing one silently renders as `plain`.
     */
    public function testThePresetsCoverTheScaffoldedThemes(): void
    {
        foreach (['plain', 'bootstrap', 'tailwind'] as $theme) {
            // Assert
            $this->assertNotSame([], FieldStyles::for($theme), $theme . ' has no preset');
        }
    }
}
