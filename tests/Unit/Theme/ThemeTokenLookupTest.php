<?php

declare(strict_types=1);

namespace Tests\Unit\Theme;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Theme\ThemeTokens;

/**
 * Reading one colour out of the palette, from PHP.
 *
 * `ThemeTokens::token()` exists for the two places a CSS custom property cannot be used: a chart
 * drawn on a canvas, whose colours have to match the page and therefore be in the markup; and an
 * HTML email, which has no custom properties at all, so every colour has to be written out.
 *
 * Eleven statements, none of them ever executed. What they add up to is a lookup that **never
 * throws and never returns nothing** — every failure gives back the caller's fallback, because a
 * chart with a missing colour should be the wrong shade rather than a stack trace, and an email
 * with `background: ;` in it is a broken email.
 *
 * The palette is primed into the read-once cache rather than written to disk. `flush()` is public
 * and documented for tests, which is what makes that the intended seam — and the real file lives in
 * the project root, where a test has no business writing.
 */
#[CoversClass(ThemeTokens::class)]
class ThemeTokenLookupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ThemeTokens::flush();
    }

    protected function tearDown(): void
    {
        ThemeTokens::flush();
        parent::tearDown();
    }

    /**
     * Puts a palette in front of `token()` without touching the project's own file.
     *
     * @param array<string, array{default: bool, tokens: array<string, string>}> $themes
     */
    private function palette(array $themes): void
    {
        $cache = new \ReflectionProperty(ThemeTokens::class, 'cache');
        $cache->setValue(null, [ThemeTokens::locate() => $themes]);
    }

    /**
     * A named theme's token comes back.
     */
    public function testANamedThemesTokenComesBack(): void
    {
        // Arrange
        $this->palette([
            'light' => ['default' => true,  'tokens' => ['--primary' => 'oklch(55% 0.2 250)']],
            'dark'  => ['default' => false, 'tokens' => ['--primary' => 'oklch(75% 0.2 250)']],
        ]);

        // Act + Assert
        $this->assertSame('oklch(75% 0.2 250)', ThemeTokens::token('--primary', 'dark'));
    }

    /**
     * The leading `--` is optional, because half the callers will not write it.
     *
     * A lookup that needed the exact custom-property spelling would fail silently for anybody
     * thinking in token names — and silently means the fallback, so it would look like a palette
     * problem rather than a spelling one.
     */
    public function testTheLeadingDashesAreOptional(): void
    {
        // Arrange
        $this->palette(['light' => ['default' => true, 'tokens' => ['--primary' => '#123456']]]);

        // Act + Assert
        $this->assertSame('#123456', ThemeTokens::token('primary'));
        $this->assertSame('#123456', ThemeTokens::token('--primary'));
    }

    /**
     * With no theme named, the default theme answers.
     *
     * Which is what makes the function usable from an email template that has no idea which themes
     * an installation declares.
     */
    public function testWithNoThemeNamedTheDefaultAnswers(): void
    {
        // Arrange — the default is not the first entry, so this cannot pass by accident
        $this->palette([
            'dark'  => ['default' => false, 'tokens' => ['--primary' => 'dark-value']],
            'light' => ['default' => true,  'tokens' => ['--primary' => 'light-value']],
        ]);

        // Act + Assert
        $this->assertSame('light-value', ThemeTokens::token('primary'));
    }

    /**
     * Every way of not finding the token gives back the caller's fallback.
     *
     * All four in one test, because the guarantee is the *set*: there is no input for which this
     * returns something the caller did not ask for. A chart wants a colour or its own default; it
     * has no use for an exception and no use for an empty string it did not choose.
     */
    public function testEveryFailureGivesBackTheCallersFallback(): void
    {
        // Arrange
        $this->palette(['light' => ['default' => true, 'tokens' => ['--primary' => '#abc']]]);

        // Act + Assert
        $this->assertSame(
            'FB',
            ThemeTokens::token('primary', 'no-such-theme', 'FB'),
            'a theme that is not declared should fall back'
        );
        $this->assertSame(
            'FB',
            ThemeTokens::token('no-such-token', 'light', 'FB'),
            'a token that is not declared should fall back'
        );

        // And an empty palette — an installation with no file, or one that failed to parse.
        $this->palette([]);
        $this->assertSame('FB', ThemeTokens::token('primary', '', 'FB'));
        $this->assertSame('FB', ThemeTokens::token('primary', 'light', 'FB'));
    }

    /**
     * With no fallback given, a miss is an empty string rather than an error.
     *
     * The default default. Worth pinning because the alternative — `null` — would put `null` into
     * string concatenation in every template that calls this without a third argument.
     */
    public function testWithNoFallbackAMissIsAnEmptyString(): void
    {
        // Arrange
        $this->palette([]);

        // Act
        $value = ThemeTokens::token('primary');

        // Assert
        $this->assertSame('', $value);
    }
}
