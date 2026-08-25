<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Theme;

use PHPUnit\Framework\TestCase;
use Pramnos\Theme\Theme;

/**
 * The login page renders without the site header, and only when the theme has a layout.
 *
 * Reported from a scaffolded Tailwind project: `/login` arrived with the sticky site
 * header, the whole navigation and a "Sign in" link pointing at the page the visitor
 * was already looking at — and then, below all of it, a `min-h-screen` centred card.
 * Every built-in auth view is written that way (`min-height: 100vh` in the plain-CSS
 * and Bootstrap themes), so the chrome was never intended to be there.
 *
 * The mechanism is not new. `Theme::$elements` has mapped `'login'` to `login.php`
 * since the class was written, and `loadtheme()` consults it before falling back to
 * `theme.html.php`. No theme shipped the file, so the fallback was the only path
 * anybody ever saw. These tests pin both halves, and the second matters as much as
 * the first: a hand-written application theme with no `login.php` has to keep
 * rendering exactly as it did.
 */
class StandaloneLoginLayoutTest extends TestCase
{
    /**
     * A throwaway theme directory **inside** the repository.
     *
     * `Theme::getTheme()` resolves a non-empty `$path` as `ROOT . DS . $path`, so a
     * path under the system temp directory resolves to nothing and every accessor
     * returns an empty result — the same trap {@see ThemeLazyLoadTest} documents.
     */
    private string $relative = 'tests/fixtures/tmp-login-theme';

    private string $root = '';

    private const NAME = 'loginfixture';

    protected function setUp(): void
    {
        $this->root = ROOT . '/' . $this->relative;
        if (!is_dir($this->root . '/' . self::NAME)) {
            mkdir($this->root . '/' . self::NAME, 0777, true);
        }

        // The chrome layout: what every page got, login included.
        file_put_contents(
            $this->root . '/' . self::NAME . '/theme.html.php',
            '<html><head>ASSETS</head><body>SITE-HEADER[MODULE]SITE-FOOTER</body></html>'
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/' . self::NAME . '/theme.html.php');
        @unlink($this->root . '/' . self::NAME . '/login.php');
        @rmdir($this->root . '/' . self::NAME);
        @rmdir($this->root);

        // getTheme() caches instances by name in a static; a later test asking for
        // this name would be handed an object built against a directory that is gone.
        (new \ReflectionProperty(Theme::class, 'instances'))->setValue(null, null);
    }

    /** Writes the standalone layout the scaffolder now produces. */
    private function writeStandaloneLayout(): void
    {
        file_put_contents(
            $this->root . '/' . self::NAME . '/login.php',
            "<head>ASSETS</head>\n<body>\n[MODULE]\nSCRIPTS\n</body>\n"
        );
    }

    private function theme(): Theme
    {
        return Theme::getTheme(self::NAME, $this->relative, false);
    }

    /**
     * With `login.php` present, content type `login` renders no site chrome.
     *
     * `gethead()` is everything before `[MODULE]` and `getfoot()` everything after —
     * between them they are the whole of what wraps the view. Asserting the site
     * header and footer markers are absent from *both* is the assertion the bug
     * report was about; asserting `SCRIPTS` is present is what stops the fix from
     * being "render nothing around it", which would take `renderJs()` with it and
     * silently break the passkey flow on the login page.
     */
    public function testTheStandaloneLayoutDropsTheSiteChrome(): void
    {
        // Arrange
        $this->writeStandaloneLayout();
        $theme = $this->theme();

        // Act
        $theme->setContentType('login');
        $theme->loadtheme();

        // Assert — no chrome, above or below the view.
        $wrapper = $theme->gethead() . $theme->getfoot();
        $this->assertStringNotContainsString('SITE-HEADER', $wrapper,
            'the login page must not carry the site header');
        $this->assertStringNotContainsString('SITE-FOOTER', $wrapper);

        // …and the scripts still come out, which is why this is a layout and not a
        // suppression.
        $this->assertStringContainsString('SCRIPTS', $wrapper);
    }

    /**
     * The stylesheets still reach the document's real `<head>`.
     *
     * `getheader()` is the only accessor that reads `<head>…</head>` out of the
     * theme's output; `Html::render()` appends its result inside the document's own
     * head. A standalone layout that skipped it would render an unstyled login form —
     * which is the failure a reader would blame on the CSS, not on the layout.
     */
    public function testTheStandaloneLayoutKeepsTheHeadAssets(): void
    {
        // Arrange
        $this->writeStandaloneLayout();
        $theme = $this->theme();

        // Act
        $theme->setContentType('login');
        $theme->loadtheme();

        // Assert
        $this->assertStringContainsString('ASSETS', $theme->getheader());
    }

    /**
     * Without `login.php`, the content type changes nothing.
     *
     * The backwards-compatibility guarantee, and the reason this could ship as a
     * default: `Account` asks every theme for the standalone layout, and a theme
     * somebody hand-wrote before it existed has to answer with what it always did
     * rather than with an empty page.
     */
    public function testAThemeWithoutTheLayoutFallsBackToTheChrome(): void
    {
        // Arrange — no login.php written.
        $theme = $this->theme();

        // Act
        $theme->setContentType('login');
        $theme->loadtheme();

        // Assert — theme.html.php, exactly as before.
        $this->assertStringContainsString('SITE-HEADER', $theme->gethead());
        $this->assertStringContainsString('SITE-FOOTER', $theme->getfoot());
    }

    /**
     * The default content type still gets the chrome even when `login.php` exists.
     *
     * Proves the selection is the content type and not the file's mere presence — a
     * theme that gains a login layout must not lose its header everywhere else.
     */
    public function testTheDefaultContentTypeIsUnaffected(): void
    {
        // Arrange
        $this->writeStandaloneLayout();
        $theme = $this->theme();

        // Act — no setContentType() call; 'index' is the default.
        $theme->loadtheme();

        // Assert
        $this->assertStringContainsString('SITE-HEADER', $theme->gethead());
    }
}
