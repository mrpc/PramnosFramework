<?php

declare(strict_types=1);

namespace Tests\Unit\Theme;

use PHPUnit\Framework\TestCase;

/**
 * The tailwind theme's component styles live in `components.css`, apart from its page rules.
 *
 * An application with a Tailwind build of its own — an administration with its own drawer
 * layout — imports `components.css` to get the bell, the tiles, the flash messages and the
 * rest. Importing `style.css` instead would bring `html, body { display: flex }` and the page
 * background with it, and break that layout; so the split is only useful while
 * `components.css` stays free of page rules, and `style.css` still serves both.
 */
class TailwindComponentsStylesheetTest extends TestCase
{
    private const DIR = __DIR__ . '/../../../scaffolding/themes/tailwind';

    /**
     * No rule in components.css reaches beyond the framework's own `pf-*` hooks: nothing for
     * `html`, `body`, `:root` or the home page, which belong to the page an application owns.
     */
    public function testTheComponentsCarryNoPageRules(): void
    {
        // Arrange
        $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(self::DIR . '/components.css'));

        // Act — every selector list; an @media header is not one, its contents are
        $css = (string) preg_replace('/@media[^{]*\{/', '', $css);
        preg_match_all('/([^{}@;]+)\{/', $css, $m);
        $selectors = array_map('trim', explode(',', implode(',', $m[1])));
        $foreign   = array_values(array_filter(
            $selectors,
            static fn (string $s): bool => $s !== '' && !preg_match('/^(a)?\.pf-(?!home)/', $s)
        ));

        // Assert
        $this->assertNotEmpty($selectors, 'the sweep found the rules');
        $this->assertSame([], $foreign, 'components.css styles only pf-* components');
    }

    /**
     * style.css imports components.css before any rule — CSS ignores an @import that comes after
     * one — and the components it used to carry are not left behind as a second copy.
     */
    public function testTheStyleSheetImportsThemFirst(): void
    {
        // Arrange
        $style = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(self::DIR . '/style.css'));

        // Act
        $firstStatement = ltrim($style);

        // Assert
        $this->assertStringStartsWith('@import url("components.css");', $firstStatement);
        $this->assertStringNotContainsString('.pf-notify', $style);
        $this->assertStringNotContainsString('.pf-stat-tile', $style);
    }
}
