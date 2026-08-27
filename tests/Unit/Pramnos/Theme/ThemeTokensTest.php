<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Theme;

use PHPUnit\Framework\TestCase;
use Pramnos\Theme\ThemeTokens;

/**
 * One palette, in daisyUI's format, read and re-emitted for everything else.
 *
 * The file is written by hand — pasted out of daisyUI's theme generator — so the
 * parser has to survive what a person's stylesheet actually contains: comments, an
 * `@import`, a trailing semicolon or none, quoted names, a property this framework has
 * never heard of. Every one of those is a real line in a real theme file, and refusing
 * to read the rest of a file because of one of them would be worse than reading it.
 */
class ThemeTokensTest extends TestCase
{
    protected function setUp(): void
    {
        ThemeTokens::flush();
    }

    private const PALETTE = <<<'CSS'
    /* The project's colours. */
    @import "tailwindcss";

    @plugin "daisyui/theme" {
        name: "acme";
        default: true;
        color-scheme: light;
        --color-primary: oklch(54.6% 0.215 262.9);
        --color-base-100: oklch(100% 0 0);
        --radius-box: 0.75rem;
    }

    @plugin "daisyui/theme" {
        name: "acme-dark";
        prefersdark: true;
        color-scheme: dark;
        --color-primary: oklch(65% 0.19 262.9);
        --color-base-100: oklch(20.8% 0.04 265.8);
    }
    CSS;

    /**
     * Both blocks are read, with their names, flags and tokens.
     */
    public function testEveryThemeBlockIsRead(): void
    {
        // Act
        $themes = ThemeTokens::parse(self::PALETTE);

        // Assert
        $this->assertSame(['acme', 'acme-dark'], array_keys($themes));
        $this->assertTrue($themes['acme']['default']);
        $this->assertFalse($themes['acme']['prefersdark']);
        $this->assertTrue($themes['acme-dark']['prefersdark']);
        $this->assertSame('dark', $themes['acme-dark']['color_scheme']);
        $this->assertSame(
            'oklch(54.6% 0.215 262.9)',
            $themes['acme']['tokens']['--color-primary']
        );
        $this->assertSame('0.75rem', $themes['acme']['tokens']['--radius-box']);
    }

    /**
     * A block with no name is skipped, and the rest of the file still reads.
     *
     * daisyUI needs the name to register the theme, so a block without one is an
     * unfinished paste — not a reason to leave the project with no palette at all.
     */
    public function testAnUnnamedBlockIsSkippedWithoutLosingTheOthers(): void
    {
        // Arrange
        $css = "@plugin \"daisyui/theme\" {\n  --color-primary: red;\n}\n" . self::PALETTE;

        // Act
        $themes = ThemeTokens::parse($css);

        // Assert
        $this->assertSame(['acme', 'acme-dark'], array_keys($themes));
    }

    /**
     * A stylesheet with no theme blocks parses to nothing, not to an error.
     *
     * "This project declares no palette" is a normal state — every project scaffolded
     * before the file existed is in it.
     */
    public function testAStylesheetWithNoBlocksIsEmpty(): void
    {
        // Act & Assert
        $this->assertSame([], ThemeTokens::parse("body { color: red; }\n"));
    }

    /**
     * The default theme is `:root` as well, so a page that sets no attribute is styled.
     */
    public function testTheDefaultThemeAlsoLandsOnRoot(): void
    {
        // Act
        $css = ThemeTokens::toCss(ThemeTokens::parse(self::PALETTE));

        // Assert
        $this->assertStringContainsString(':root,' . "\n" . '[data-theme="acme"] {', $css);
        $this->assertStringContainsString('[data-theme="acme-dark"] {', $css);
        $this->assertStringNotContainsString(':root,' . "\n" . '[data-theme="acme-dark"]', $css);
    }

    /**
     * The dark theme is applied by OS preference — but only when nothing was chosen.
     *
     * Scoped `:root:not([data-theme])`, which is the whole difference between a theme
     * switch that works and one that works only for visitors whose OS is already
     * light: without it, an explicit choice of the light theme is overruled by the
     * media query every time.
     */
    public function testThePrefersDarkThemeDoesNotOverrideAnExplicitChoice(): void
    {
        // Act
        $css = ThemeTokens::toCss(ThemeTokens::parse(self::PALETTE));

        // Assert
        $this->assertStringContainsString('@media (prefers-color-scheme: dark)', $css);
        $this->assertStringContainsString(':root:not([data-theme])', $css);
    }

    /**
     * `color-scheme` is emitted, because it is not decoration.
     *
     * It is what makes a form control, a scrollbar and the browser's own default
     * background match the theme. Without it a dark page has light scrollbars and
     * white `<select>` popups — the parts a stylesheet cannot reach.
     */
    public function testColorSchemeIsEmitted(): void
    {
        // Act
        $css = ThemeTokens::toCss(ThemeTokens::parse(self::PALETTE));

        // Assert
        $this->assertStringContainsString('color-scheme: light;', $css);
        $this->assertStringContainsString('color-scheme: dark;', $css);
    }

    /**
     * The generated file says it is generated, and names what to edit instead.
     *
     * A generated file that does not is a file somebody edits once, loses, and stops
     * trusting the build over.
     */
    public function testTheGeneratedCssSaysWhereToEdit(): void
    {
        // Act
        $css = ThemeTokens::toCss(ThemeTokens::parse(self::PALETTE));

        // Assert
        $this->assertStringContainsString('theme:build', $css);
        $this->assertStringContainsString('app/theme.css', $css);
    }

    /**
     * Nothing in, nothing out — not a file with a header and no rules.
     */
    public function testNoThemesProducesNoStylesheet(): void
    {
        // Act & Assert
        $this->assertSame('', ThemeTokens::toCss([]));
    }

    /**
     * The JSON carries the same values a SPA would otherwise re-declare.
     */
    public function testTheJsonCarriesTheTokens(): void
    {
        // Act
        $decoded = json_decode(ThemeTokens::toJson(ThemeTokens::parse(self::PALETTE)), true);

        // Assert
        $this->assertSame(
            'oklch(65% 0.19 262.9)',
            $decoded['acme-dark']['tokens']['--color-primary'] ?? null
        );
    }

    /**
     * A single token can be read from PHP, for the places CSS cannot reach.
     *
     * `<meta name="theme-color">` is the case that keeps coming up: the browser chrome
     * should match the page, and the value has to be in the markup. An HTML email is
     * the other — it has no custom properties at all.
     */
    public function testATokenCanBeReadForMarkupThatCannotUseCustomProperties(): void
    {
        // Arrange
        $path = sys_get_temp_dir() . '/pf-palette-' . getmypid() . '.css';
        file_put_contents($path, self::PALETTE);
        ThemeTokens::flush();

        try {
            // Act — the loader caches by path, so seed it with this file
            $themes = ThemeTokens::load($path);

            // Assert
            $this->assertSame('acme', ThemeTokens::defaultTheme($themes)['name']);
            $this->assertSame(
                'oklch(54.6% 0.215 262.9)',
                $themes['acme']['tokens']['--color-primary']
            );
        } finally {
            @unlink($path);
            ThemeTokens::flush();
        }
    }

    /**
     * With no palette on disk, a token read is the fallback rather than an error.
     */
    public function testAMissingPaletteReadsAsTheFallback(): void
    {
        // Arrange
        ThemeTokens::flush();

        // Act
        $themes = ThemeTokens::load(sys_get_temp_dir() . '/pf-no-such-palette.css');

        // Assert
        $this->assertSame([], $themes);
        $this->assertNull(ThemeTokens::defaultTheme($themes));
    }

    /**
     * With no theme flagged `default`, the first declared one is it.
     *
     * Which is what daisyUI itself does. Guessing differently would put the framework
     * and the plugin in disagreement about the same file — and the disagreement would
     * show as one palette on the server-rendered side and another in the SPA.
     */
    public function testTheFirstThemeIsTheDefaultWhenNoneIsFlagged(): void
    {
        // Arrange
        $css = str_replace('default: true;', '', self::PALETTE);

        // Act
        $themes = ThemeTokens::parse($css);

        // Assert
        $this->assertSame('acme', ThemeTokens::defaultTheme($themes)['name']);
    }
}
