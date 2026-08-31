<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Html;

use PHPUnit\Framework\TestCase;

/**
 * Two things every scaffolded theme has to get right, checked in all three at once.
 *
 * A theme is copied into a project and edited from then on, so a gap here is a gap in every site
 * generated before it was noticed — and in none of them afterwards, which is why it goes unseen.
 */
class ScaffoldAccessibilityTest extends TestCase
{
    private const THEMES = ['plain-css', 'bootstrap', 'tailwind'];

    /** A view file with its comments removed, so an explanation is not mistaken for the thing. */
    private function executableCode(string $path): string
    {
        $code = '';

        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token)
                && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
            ) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    private function theme(string $name): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 4) . '/scaffolding/themes/' . $name . '/theme.html.php'
        );
    }

    private function style(string $name): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 4) . '/scaffolding/themes/' . $name . '/style.css'
        );
    }

    /**
     * A keyboard can get past the navigation.
     *
     * The first thing any accessibility audit checks, and the `<main>` landmark it needs has been
     * in these themes all along — only the link to it was missing. Without one, reaching the
     * content of a page means tabbing through every navigation item on every page.
     */
    public function testEveryThemeHasASkipLink(): void
    {
        foreach (self::THEMES as $theme) {
            // Assert
            $this->assertStringContainsString('href="#main-content"', $this->theme($theme), $theme);
            $this->assertStringContainsString('id="main-content"', $this->theme($theme),
                $theme . ' has a skip link pointing at nothing');
        }
    }

    /**
     * And it becomes visible when it has focus.
     *
     * Positioned off-screen rather than hidden: `display:none` takes it out of the tab order,
     * which removes the one thing it exists to provide. A skip link nobody can see once they
     * have reached it is a skip link nobody uses.
     */
    public function testTheSkipLinkAppearsOnFocus(): void
    {
        foreach (self::THEMES as $theme) {
            // Act
            $css = $this->style($theme);

            // Assert
            $this->assertStringContainsString('.pf-skip-link', $css, $theme);
            $this->assertStringContainsString('.pf-skip-link:focus', $css,
                $theme . ' hides the skip link with no way to reveal it');
            $this->assertStringNotContainsString('.pf-skip-link{display:none', $css, $theme);
        }
    }

    /**
     * The consent screen sends nothing about the person to a third party.
     *
     * It fell back to Gravatar, which means `md5(email)` of somebody signing in went to another
     * company on every render — from the one page in the flow where they are deciding what to
     * disclose, and to a party they were never asked about. An md5 of an address is not
     * anonymous; it is the address, hashed.
     */
    public function testTheConsentScreenLeaksNothingToAThirdParty(): void
    {
        foreach (self::THEMES as $theme) {
            // Act — the code, with comments stripped: the comment explaining why the fallback
            // is gone names it, and a test that cannot tell the two apart is a test that
            // punishes the explanation.
            $view = $this->executableCode(
                dirname(__DIR__, 4) . '/scaffolding/themes/' . $theme
                . '/views/OAuth2/authorize.html.php'
            );

            // Assert
            $this->assertStringNotContainsString('gravatar', strtolower($view), $theme);
            $this->assertStringNotContainsString('md5(', $view,
                $theme . ' still hashes something about the person into a URL');
        }
    }
}
