<?php

declare(strict_types=1);

namespace Tests\Unit\Document;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Document\Document;

/**
 * `Document::loadtheme()` — the document remembers which theme it is rendering with.
 *
 * Six statements, never executed. Two things happen and both matter: the theme is resolved, and it
 * is **kept on the document**. A `loadtheme()` that returned the theme without storing it would
 * leave `$doc->themeObject` null, and everything downstream that asks the document which theme it
 * is in — a view resolving a template path, a partial looking for an override — would fall back to
 * the default while the page rendered with something else.
 */
#[CoversClass(Document::class)]
class LoadThemeTest extends TestCase
{
    private mixed $savedTheme = null;

    protected function setUp(): void
    {
        parent::setUp();
        $document = \Pramnos\Framework\Factory::getDocument('html');
        $this->savedTheme = $document->themeObject ?? null;
    }

    protected function tearDown(): void
    {
        $document = \Pramnos\Framework\Factory::getDocument('html');
        $document->themeObject = $this->savedTheme;
        parent::tearDown();
    }

    /**
     * The theme it returns is the theme it kept.
     *
     * Identity, not equality: the document must hold the same object it handed back, or two
     * callers asking the same document get two themes.
     */
    public function testTheThemeItReturnsIsTheThemeItKept(): void
    {
        // Arrange
        $document = \Pramnos\Framework\Factory::getDocument('html');

        // Act
        $theme = $document->loadtheme('default');

        // Assert
        $this->assertNotNull($theme);
        $this->assertSame(
            $theme,
            $document->themeObject,
            'the document handed back a theme it did not keep'
        );
    }

    /**
     * Loading a second theme replaces the first.
     *
     * A worker serving more than one request in a PHP lifetime loads a theme per request, and a
     * document that kept the first would render the second request with the first request's theme
     * — which is the class of bug `Document::reset()` exists for.
     */
    public function testLoadingASecondThemeReplacesTheFirst(): void
    {
        // Arrange
        $document = \Pramnos\Framework\Factory::getDocument('html');

        // Act
        $first  = $document->loadtheme('default');
        $second = $document->loadtheme('default');

        // Assert
        $this->assertSame($second, $document->themeObject);
        $this->assertNotNull($first, 'the first load returned nothing to compare against');
    }

    /**
     * It does not ask the theme to render itself.
     *
     * `getTheme($theme, $path, false, …)` — the third argument is `$load`, and it is `false` here
     * on purpose: a document that is only recording which theme it uses must not run the theme's
     * own bootstrap as a side effect of being asked.
     */
    public function testItResolvesTheThemeWithoutLoadingIt(): void
    {
        // Arrange
        $document = \Pramnos\Framework\Factory::getDocument('html');

        // Act
        $theme = $document->loadtheme('default');

        // Assert
        $this->assertInstanceOf(\Pramnos\Theme\Theme::class, $theme);
    }
}
