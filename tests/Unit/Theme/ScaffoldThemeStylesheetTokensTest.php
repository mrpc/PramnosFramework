<?php

declare(strict_types=1);

namespace Tests\Unit\Theme;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Theme\ThemeTokens;

/**
 * Every custom property a scaffolded theme reads is one something on the page defines.
 *
 * `var(--name, #literal)` never fails. When nothing declares `--name` the browser takes the
 * fallback, renders it, and says nothing — so a stylesheet can read a vocabulary that does
 * not exist on the page and look merely opinionated rather than broken. The tailwind theme
 * was in exactly that state: its breadcrumb and pagination rules asked for `--primary-color`,
 * `--text-muted`, `--border-color`, `--text-main` and `--white` while the palette pipeline
 * emits daisyUI's `--color-*` names, so those two components rendered Tailwind blue on every
 * project and stayed light under the dark theme. The rest of the same file already used
 * `--color-*`, which is what made it invisible — it looked like a style choice.
 *
 * So the invariant is checked mechanically: a property is either declared in the same file,
 * or it carries a prefix that theme's page genuinely provides.
 */
class ScaffoldThemeStylesheetTokensTest extends TestCase
{
    /**
     * Besides its own declarations, where each theme's custom properties come from.
     *
     * The palette is not guessed at: `scaffolding/theme.css` is the file `init` writes,
     * and `pramnos theme:build` turns exactly its tokens into the `theme-tokens.css`
     * that `head.php` links for every server-rendered theme. Reading it through the
     * framework's own parser means a token added to the palette is available here the
     * same day, and one removed from it stops being an excuse.
     *
     * `--bs-*` is the other source: Bootstrap's own vocabulary, defined by the
     * stylesheet that theme loads.
     *
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function provideThemes(): array
    {
        return [
            'tailwind'  => ['tailwind',  []],
            'bootstrap' => ['bootstrap', ['--bs-']],
            'plain-css' => ['plain-css', []],
        ];
    }

    /**
     * Every token name the scaffolded palette declares, across all its themes.
     *
     * @return list<string>
     */
    private static function paletteTokens(): array
    {
        $themes = ThemeTokens::parse(
            (string) file_get_contents(dirname(__DIR__, 3) . '/scaffolding/theme.css')
        );

        $tokens = [];
        foreach ($themes as $theme) {
            $tokens = array_merge($tokens, array_keys($theme['tokens']));
        }

        return array_values(array_unique($tokens));
    }

    /**
     * No rule reads a property that nothing on the page declares.
     *
     * @param list<string> $providedPrefixes Extra prefixes the theme's page supplies,
     *        on top of the scaffolded palette's own tokens
     */
    #[DataProvider('provideThemes')]
    public function testEveryPropertyReadIsOneSomethingDefines(string $theme, array $providedPrefixes): void
    {
        // Arrange
        $css = (string) file_get_contents(
            dirname(__DIR__, 3) . '/scaffolding/themes/' . $theme . '/style.css'
        );

        // The file's own declarations: `--name:` at the start of a declaration.
        preg_match_all('/(--[a-z0-9-]+)\s*:/i', $css, $declared);
        // Every read: `var(--name` — with or without a fallback.
        preg_match_all('/var\(\s*(--[a-z0-9-]+)/i', $css, $read);

        // Act
        $undefined = [];
        foreach (array_unique($read[1]) as $property) {
            if (in_array($property, $declared[1], true)) {
                continue;
            }
            if (in_array($property, self::paletteTokens(), true)) {
                continue;
            }
            foreach ($providedPrefixes as $prefix) {
                if (str_starts_with($property, $prefix)) {
                    continue 2;
                }
            }
            $undefined[] = $property;
        }

        // Assert — the fallback is a fallback, not the only value the rule can ever take.
        $this->assertSame(
            [],
            $undefined,
            $theme . '/style.css reads ' . implode(', ', $undefined)
            . ' — nothing declares them, so every one of those rules is permanently its literal'
        );
    }
}
