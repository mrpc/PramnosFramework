<?php

declare(strict_types=1);

namespace Tests\Unit\Theme;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every scaffold theme keeps its component styles in `components.css`, apart from its page rules,
 * and the three style the same components.
 *
 * An application with a build of its own — an administration with its own layout — imports
 * `components.css` to get the bell, the tiles, the flash messages and the rest. Importing
 * `style.css` instead would bring the page's `body` rules with it and break that layout; so the
 * split is only useful while `components.css` stays free of page rules, while `style.css` still
 * serves both, and while every UI system offers it: a component styled in one system and not in
 * another is a component that renders bare after `project:switch-ui`.
 */
class ThemeComponentsStylesheetTest extends TestCase
{
    private const THEMES = __DIR__ . '/../../../scaffolding/themes';

    /**
     * @return array<string, array{string}>
     */
    public static function themes(): array
    {
        return ['tailwind' => ['tailwind'], 'bootstrap' => ['bootstrap'], 'plain-css' => ['plain-css']];
    }

    /** A theme's stylesheet without its comments. */
    private static function read(string $theme, string $file): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(self::THEMES . '/' . $theme . '/' . $file));
    }

    /**
     * No rule in components.css reaches beyond the framework's own `pf-*` hooks: nothing for
     * `html`, `body`, `:root` or the theme's own screens, which belong to the page an
     * application owns. Keyframes named `pf-…` are a component's own movement.
     */
    #[DataProvider('themes')]
    public function testTheComponentsCarryNoPageRules(string $theme): void
    {
        // Arrange — an @media header is not a selector, its contents are; a component's own
        // @keyframes (`pf-…`) are its movement, not rules, and go whole
        $css = (string) preg_replace('/@keyframes\s+pf-[\w-]+\s*\{(?:[^{}]*\{[^{}]*\})*\s*\}/', '', self::read($theme, 'components.css'));
        $css = (string) preg_replace('/@media[^{]*\{/', '', $css);

        // Act — every selector in every selector list
        preg_match_all('/([^{}@;]+)\{/', $css, $m);
        $selectors = array_map('trim', explode(',', implode(',', $m[1])));
        $foreign   = array_values(array_filter(
            $selectors,
            static fn (string $s): bool => $s !== '' && !preg_match('/^(a)?\.pf-(?!home|ui-)/', $s)
        ));

        // Assert
        $this->assertNotEmpty($selectors, 'the sweep found the rules');
        $this->assertSame([], $foreign, $theme . '/components.css styles only the framework\'s pf-* components');
    }

    /**
     * style.css imports components.css before any rule — CSS ignores an @import that comes after
     * one — and the components it used to carry are not left behind as a second copy.
     */
    #[DataProvider('themes')]
    public function testTheStyleSheetImportsThemFirst(string $theme): void
    {
        // Arrange
        $style = self::read($theme, 'style.css');

        // Act
        $firstStatement = ltrim($style);

        // Assert
        $this->assertStringStartsWith('@import "components.css";', $firstStatement);
        $this->assertStringNotContainsString('.pf-notify', $style);
        $this->assertStringNotContainsString('.pf-stat-tile', $style);
    }

    /**
     * The three files style the same components: a hook one system styles and another does not
     * is the gap a project finds only after switching.
     */
    public function testEverySystemStylesTheSameComponents(): void
    {
        // Arrange
        $hooks = [];

        // Act — the pf-* classes each components.css styles
        foreach (array_keys(self::themes()) as $theme) {
            preg_match_all('/\.(pf-[a-z0-9_-]+)/', self::read($theme, 'components.css'), $m);
            $hooks[$theme] = array_values(array_unique($m[1]));
            sort($hooks[$theme]);
        }

        // Assert
        $this->assertGreaterThan(40, count($hooks['tailwind']), 'the sweep found the components');
        $this->assertSame($hooks['tailwind'], $hooks['bootstrap'], 'bootstrap styles what tailwind does');
        $this->assertSame($hooks['tailwind'], $hooks['plain-css'], 'plain-css styles what tailwind does');
    }
}
