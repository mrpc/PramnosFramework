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
     * A comment inside a block is a comment, not a token.
     *
     * The palette file invites annotating colours, and the parser splits the block on
     * `;` — so an un-stripped `/* … *\/` reads as one more `name: value` pair: the
     * comment's own text becomes a JSON key, and the declaration on the line after it
     * is swallowed into that key's value. Both halves are asserted, because the
     * swallowed token is the half a reader notices last.
     */
    public function testACommentInsideABlockIsNotReadAsAToken(): void
    {
        // Arrange — a comment between two declarations, the shape a person writes.
        $css = <<<'CSS'
        @plugin "daisyui/theme" {
            name: "acme";
            /* The surfaces: white cards on a light grey page. */
            --color-base-100: #ffffff;
            --color-base-200: #f7f7f7; /* the page behind them */
        }
        CSS;

        // Act
        $themes = ThemeTokens::parse($css);

        // Assert — nothing but the two declarations survived.
        $this->assertSame(
            ['--color-base-100', '--color-base-200'],
            array_keys($themes['acme']['tokens'])
        );
        // The token after the comment kept its own value rather than being absorbed.
        $this->assertSame('#ffffff', $themes['acme']['tokens']['--color-base-100']);
        // A trailing comment does not end up appended to the value before it.
        $this->assertSame('#f7f7f7', $themes['acme']['tokens']['--color-base-200']);
    }

    /**
     * A declaration daisyUI understands and this does not is carried through.
     *
     * The palette file belongs to daisyUI, not to this parser, so a property added by a
     * version of it that postdates this code has to survive: dropping a line somebody
     * wrote is the worst answer available, and the generated outputs are what the rest
     * of the project reads. Worth asserting beside the comment tests because the two
     * look alike from inside the parser — both are lines it does not recognise, and only
     * one of them is a declaration.
     */
    public function testAnUnrecognisedDeclarationIsKept(): void
    {
        // Arrange — a bare property, no leading `--`.
        $css = "@plugin \"daisyui/theme\" {\n  name: \"acme\";\n  depth: 1;\n}\n";

        // Act
        $themes = ThemeTokens::parse($css);

        // Assert
        $this->assertSame('1', $themes['acme']['tokens']['depth']);
    }

    /**
     * A commented-out `}` does not end the block early.
     *
     * Comments are stripped before the block regex runs, and the regex stops at the
     * first `}`. Without the strip, commenting a line out truncates the block and
     * every declaration below it disappears — silently, since a short block is still
     * a valid one.
     */
    public function testACommentedOutBraceDoesNotTruncateTheBlock(): void
    {
        // Arrange
        $css = <<<'CSS'
        @plugin "daisyui/theme" {
            name: "acme";
            /* was: } */
            --color-primary: red;
        }
        CSS;

        // Act
        $themes = ThemeTokens::parse($css);

        // Assert — the declaration below the comment is still there.
        $this->assertSame('red', $themes['acme']['tokens']['--color-primary']);
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
        $this->assertStringContainsString('theme.css', $css);
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
