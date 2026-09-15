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
     * The generated stylesheet carries the palette under each UI system's own names.
     *
     * Only the tailwind theme reads daisyUI tokens. Bootstrap's components read `--bs-*`
     * and the plain-CSS theme reads a vocabulary of its own, so without this both
     * rendered their framework's stock colours whatever the project declared — "one
     * palette, every UI system" was true of one system.
     *
     * Aliasing rather than rewriting those stylesheets is the point: Bootstrap's **own**
     * `.btn-primary` and `.navbar` read `--bs-primary`, and nothing in this repository
     * can edit Bootstrap.
     */
    public function testThePaletteIsAliasedOntoEachUiSystemsOwnNames(): void
    {
        // Act
        $css = ThemeTokens::toCss(ThemeTokens::parse(self::PALETTE));

        // Assert — Bootstrap's vocabulary, and the plain-CSS theme's.
        $this->assertStringContainsString('--bs-primary: var(--color-primary);', $css);
        $this->assertStringContainsString('--bs-body-bg: var(--color-base-100);', $css);
        $this->assertStringContainsString('--primary-color: var(--color-primary);', $css);
        $this->assertStringContainsString('--surface: var(--color-base-100);', $css);
    }

    /**
     * The aliases are emitted per theme, so the dark palette carries its own.
     *
     * `var()` inside a custom property resolves against the same element, so an alias
     * written once on `:root` would already follow a theme that redefines the token it
     * points at. The reason to repeat it is the `prefers-color-scheme` block, which is a
     * *different* selector — an alias that lived only on `:root` would be absent there,
     * and a visitor on a dark-mode machine would get the dark palette with Bootstrap's
     * light chrome on top of it.
     */
    public function testEveryThemeBlockCarriesTheAliases(): void
    {
        // Act
        $css = ThemeTokens::toCss(ThemeTokens::parse(self::PALETTE));

        // Assert — the light block, the dark block, and the OS-preference block.
        $this->assertSame(3, substr_count($css, '--bs-primary: var(--color-primary);'));
    }

    /**
     * Bootstrap's opacity utilities need a triplet, and it is computed, not aliased.
     *
     * `.bg-primary` is `rgba(var(--bs-primary-rgb), var(--bs-bg-opacity))`, and the
     * scaffolded bootstrap navbar is a `.bg-primary`. `oklch()` cannot be fed to
     * `rgba()` and CSS has no way to decompose it, so the conversion happens here —
     * without it the most visible element on the page would keep Bootstrap's blue while
     * everything around it followed the project.
     *
     * The expected value is not arbitrary: the scaffolded palette's primary is the
     * oklch spelling of `#2563eb`, so a correct conversion has to land back on it.
     */
    public function testTheRgbTripletsAreComputedFromTheOklchValues(): void
    {
        // Arrange — `oklch(54.6% 0.215 262.9)` is `#2563eb`.
        $css = <<<'CSS'
        @plugin "daisyui/theme" {
            name: "acme";
            --color-primary: oklch(54.6% 0.215 262.9);
            --color-base-100: oklch(100% 0 0);
        }
        CSS;

        // Act
        $out = ThemeTokens::toCss(ThemeTokens::parse($css));

        // Assert
        $this->assertStringContainsString('--bs-primary-rgb: 37, 99, 235;', $out);
        $this->assertStringContainsString('--bs-body-bg-rgb: 255, 255, 255;', $out);
    }

    /**
     * A hex or `rgb()` palette converts too, and anything else is left out.
     *
     * A palette is a file a designer edits, so it does not always arrive in `oklch()`.
     * The last case is the one that matters: a wrong triplet is worse than an absent
     * one, because Bootstrap falls back to its own value, which at least agrees with
     * itself — a half-converted `color-mix()` would be a colour nobody chose.
     */
    public function testOtherColourSpellingsConvertAndUnresolvableOnesAreSkipped(): void
    {
        // Arrange
        $css = <<<'CSS'
        @plugin "daisyui/theme" {
            name: "acme";
            --color-primary: #2563eb;
            --color-base-100: rgb(255, 255, 254);
            --color-base-content: #fff;
            --color-success: color-mix(in oklab, green 50%, white);
        }
        CSS;

        // Act
        $out = ThemeTokens::toCss(ThemeTokens::parse($css));

        // Assert — hex long, `rgb()`, and hex short all resolve.
        $this->assertStringContainsString('--bs-primary-rgb: 37, 99, 235;', $out);
        $this->assertStringContainsString('--bs-body-bg-rgb: 255, 255, 254;', $out);
        $this->assertStringContainsString('--bs-body-color-rgb: 255, 255, 255;', $out);

        // …and the one that cannot be resolved here is absent rather than guessed.
        $this->assertStringNotContainsString('--bs-success-rgb', $out);
        // The plain alias still ships — `var()` needs no conversion, so `.btn-success`
        // follows the palette even where the opacity utilities cannot.
        $this->assertStringContainsString('--bs-success: var(--color-success);', $out);
    }

    /**
     * The dark end of the transfer function, and the clamp above it.
     *
     * sRGB is linear below 0.0031308 and a power curve above it — a black or
     * near-black token takes the branch nothing else in this file reaches, and getting
     * it wrong would lift every dark surface off black by a visible amount. The second
     * half is the out-of-gamut case: oklch describes colours sRGB cannot show, and a
     * negative component has to clamp the way a browser clamps rather than wrap or
     * produce a channel outside 0..255.
     */
    public function testBlackAndOutOfGamutColoursConvertWithinRange(): void
    {
        // Arrange — black, and a chroma no sRGB display can reach at that lightness.
        $css = <<<'CSS'
        @plugin "daisyui/theme" {
            name: "acme";
            --color-primary: oklch(0% 0 0);
            --color-base-100: oklch(70% 0.37 142);
        }
        CSS;

        // Act
        $out = ThemeTokens::toCss(ThemeTokens::parse($css));

        // Assert — the linear branch.
        $this->assertStringContainsString('--bs-primary-rgb: 0, 0, 0;', $out);

        // And the clamp: every channel is a whole number inside the range.
        preg_match('/--bs-body-bg-rgb: ([\d, ]+);/', $out, $match);
        $this->assertNotEmpty($match, 'an out-of-gamut colour still produces a triplet');
        foreach (explode(', ', $match[1]) as $channel) {
            $this->assertGreaterThanOrEqual(0, (int) $channel);
            $this->assertLessThanOrEqual(255, (int) $channel);
        }
    }

    /**
     * An alias for a token the theme does not declare is not emitted.
     *
     * Aliasing to a property nothing defines would hand those stylesheets the same
     * silent `var(--x, #literal)` fallback the bridge exists to remove — the rule would
     * resolve, to the wrong colour, for ever.
     */
    public function testNoAliasIsEmittedForATokenTheThemeDoesNotDeclare(): void
    {
        // Arrange — a palette with a primary and nothing else.
        $css = "@plugin \"daisyui/theme\" {\n  name: \"acme\";\n  --color-primary: #2563eb;\n}\n";

        // Act
        $out = ThemeTokens::toCss(ThemeTokens::parse($css));

        // Assert
        $this->assertStringContainsString('--bs-primary: var(--color-primary);', $out);
        $this->assertStringNotContainsString('--bs-body-bg:', $out);
        $this->assertStringNotContainsString('--surface:', $out);
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
