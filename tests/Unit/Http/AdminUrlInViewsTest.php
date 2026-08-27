<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Pramnos\Http\AdminArea;

/**
 * Links inside the administration views, and the classes those views use.
 *
 * WHAT: that admin-area views build their links through `adminUrl()`, that
 *       user-facing links are deliberately left alone, and that the Tailwind
 *       theme uses no Bootstrap classes.
 * WHY:  both were silent failures found by looking at a rendered page.
 *
 *       A view inside the area that links with a bare `sURL . 'Users'` sends the
 *       visitor *out* of the area — same controller, different layout, no
 *       sidebar. Every table row, "back" link and pagination control did that.
 *
 *       And `text-bg-primary` is a Bootstrap utility that the Tailwind theme has
 *       no definition for, so four dashboard tiles rendered white text on a
 *       transparent surface: invisible, with nothing in any log to say so.
 *
 * These assertions are on the bundled views rather than on a rendered page,
 * because that is where the mistake lives and where the next one would appear.
 */
class AdminUrlInViewsTest extends TestCase
{
    /** Controllers that live in the administration area. */
    private const ADMIN_CONTROLLERS = [
        'Users', 'Applications', 'Tokens', 'Permissions', 'Settings', 'Logs',
        'Queue', 'Health', 'Organizations', 'Emails', 'Services', 'TokenActions',
        'Dashboard',
    ];

    /** The bundled themes, all of which ship the same view set. */
    private const THEMES = ['plain-css', 'bootstrap', 'tailwind'];

    protected function setUp(): void
    {
        AdminArea::reset();
        $_GET = [];
    }

    protected function tearDown(): void
    {
        AdminArea::reset();
        $_GET = [];
    }

    /** Absolute path to the bundled themes. */
    private function themesDir(): string
    {
        return dirname(__DIR__, 3) . '/scaffolding/themes';
    }

    /**
     * No bundled view links to an administration controller with a bare `sURL`.
     *
     * The check is case-insensitive on the controller name, because the URL shape
     * accepts either and the views use both spellings.
     */
    public function testAdminLinksGoThroughAdminUrl(): void
    {
        // Arrange
        $names   = implode('|', self::ADMIN_CONTROLLERS);
        $pattern = '/\\$?sURL;\s*\?>(' . $names . ')(?![A-Za-z])/i';

        $offenders = [];
        $checked   = 0;

        // Act
        foreach (self::THEMES as $theme) {
            foreach (glob($this->themesDir() . '/' . $theme . '/views/*/*.php') ?: [] as $path) {
                $checked++;
                if (preg_match($pattern, (string) file_get_contents($path))) {
                    $offenders[] = $theme . '/' . basename(dirname($path)) . '/' . basename($path);
                }
            }
        }

        // Assert
        $this->assertGreaterThan(50, $checked, 'the bundled views should have been found');
        $this->assertSame([], $offenders, 'these views would send an administrator out of the area');
    }

    /**
     * User-facing links are deliberately NOT rewritten.
     *
     * An administrator clicking "My account" wants the public account page, not an
     * admin-framed copy of it — so `account`, `login` and the rest must keep
     * leaving the area. This is the other half of the previous test: without it, a
     * blanket rewrite would look like a pass.
     */
    public function testUserFacingLinksStayOutsideTheArea(): void
    {
        // Arrange
        $sidebar = $this->themesDir() . '/tailwind/views/partials/account_sidebar.html.php';
        $this->assertFileExists($sidebar);

        // Act
        $code = (string) file_get_contents($sidebar);

        // Assert — the account sidebar addresses its own controller, not the area
        $this->assertStringNotContainsString('adminUrl(', $code);
        $this->assertStringContainsString('sURL . $item[\'href\']', $code);
    }

    /**
     * The Tailwind theme uses no Bootstrap classes.
     *
     * Four dashboard tiles carried `text-bg-primary`, which Tailwind does not
     * define: white text on a transparent surface. The failure is invisible in
     * every log and obvious only on the page.
     *
     * @param string $class A Bootstrap-only class name
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bootstrapOnlyClasses')]
    public function testTheTailwindThemeUsesNoBootstrapClasses(string $class): void
    {
        // Arrange / Act
        $offenders = [];
        foreach (glob($this->themesDir() . '/tailwind/views/*/*.php') ?: [] as $path) {
            if (str_contains((string) file_get_contents($path), $class)) {
                $offenders[] = basename(dirname($path)) . '/' . basename($path);
            }
        }

        // Assert
        $this->assertSame([], $offenders, $class . ' is a Bootstrap class with no Tailwind definition');
    }

    /** @return array<string, array{0: string}> */
    public static function bootstrapOnlyClasses(): array
    {
        return [
            'text-bg'      => ['text-bg-'],
            'btn-outline'  => ['btn-outline-'],
            'form-control' => ['form-control'],
            'form-label'   => ['form-label'],
            'grid column'  => ['col-md-'],
            // Bootstrap's colour names. daisyUI has no `danger`, `light` or
            // `dark`, so `badge bg-danger` renders as a plain uncoloured badge —
            // a failed queue job that looks exactly like a completed one. These
            // survived the first sweep, which only looked for the five above.
            'bg-danger'    => ['bg-danger'],
            'bg-light'     => ['bg-light'],
            'text-dark'    => ['text-dark'],
            'text-muted'   => ['text-muted'],
            'badge-pill'   => ['badge-pill'],
        ];
    }

    /**
     * The Tailwind theme carries no hardcoded palette.
     *
     * It is a daisyUI theme now, and daisyUI ships two themes: a `bg-white` or a
     * `text-gray-700` is invisible or unreadable under `data-theme="dark"`. That
     * is the whole failure mode, and it is invisible in every log — the page
     * renders, the text is simply not there.
     *
     * Asserted on the bundled views because that is where the next one would
     * appear: this theme already had Bootstrap classes leak into it once (see
     * above), by the same route — a view edited without the theme in mind.
     *
     * @param string $pattern A regex matching a hardcoded-palette utility
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('hardcodedPaletteClasses')]
    public function testTheTailwindThemeCarriesNoHardcodedPalette(string $pattern): void
    {
        // Arrange / Act
        $offenders = [];
        foreach (glob($this->themesDir() . '/tailwind/views/*/*.php') ?: [] as $path) {
            $content = (string) file_get_contents($path);
            foreach (preg_split('/\r?\n/', $content) ?: [] as $number => $line) {
                // Only inside a class attribute: `#2563eb` as a brand-colour
                // fallback in PHP is a different thing, and legitimate.
                if (preg_match('/class="[^"]*' . $pattern . '/', $line)) {
                    $offenders[] = basename(dirname($path)) . '/' . basename($path)
                        . ':' . ($number + 1);
                }
            }
        }

        // Assert
        $this->assertSame([], $offenders,
            'these carry a palette daisyUI cannot theme: ' . implode(', ', $offenders));
    }

    /** @return array<string, array{0: string}> */
    public static function hardcodedPaletteClasses(): array
    {
        return [
            'white surface'   => ['\bbg-white\b'],
            'grey text'       => ['\b(?:bg|text|border|divide)-gray-[0-9]'],
            'blue'            => ['\b(?:bg|text|border|ring)-blue-[0-9]'],
            'red'             => ['\b(?:bg|text|border)-red-[0-9]'],
            'green'           => ['\b(?:bg|text|border)-green-[0-9]'],
            'yellow or amber' => ['\b(?:bg|text|border)-(?:yellow|amber)-[0-9]'],
        ];
    }

    /**
     * `adminUrl()` is the helper the views call, and it follows the area.
     *
     * Asserted through the global function rather than the class, because that is
     * what the views use — a helper that existed but resolved differently would
     * pass a test written against `AdminArea::url()` and fail on every page.
     */
    public function testTheHelperFollowsTheArea(): void
    {
        // Arrange — outside any area
        $this->assertSame(\sURL . 'Users', adminUrl('Users'));

        // Act — enter one
        $_GET['r'] = 'admin/Users';
        AdminArea::detect('admin');

        // Assert
        $this->assertSame(\sURL . 'admin/Users', adminUrl('Users'));
        $this->assertSame(\sURL . 'admin', adminUrl());
    }

    /**
     * No administration *controller* builds a link with a bare `sURL` either.
     *
     * The view sweep above catches `<?php echo sURL; ?>Users`. It does not catch
     * `sURL . 'Users'`, which is the shape a controller uses — and controllers are
     * where most of these live: every breadcrumb, every redirect after a save, every
     * row-action link and every datatable's ajax source.
     *
     * The report that found it was one link: `/Logs/viewer` opened the public site.
     * The cause was 114 of them. A redirect is the worst of the set, because the
     * visitor is not clicking anything — they save a user inside the area and arrive
     * on the public copy of the list, which reads as having been signed out.
     */
    public function testAdminControllersBuildLinksThroughAdminUrl(): void
    {
        // Arrange — the framework's own administration controllers
        $files = array_merge(
            (array) glob(dirname(__DIR__, 3) . '/src/Pramnos/Application/Controllers/*Controller.php'),
            (array) glob(dirname(__DIR__, 3) . '/src/Pramnos/Auth/Controllers/*Controller.php')
        );
        $this->assertNotEmpty($files);

        $names = implode('|', self::ADMIN_CONTROLLERS);
        $offenders = [];

        foreach ($files as $file) {
            foreach (file($file) ?: [] as $number => $line) {
                // A doc-block may legitimately name the old shape while explaining it.
                $trimmed = ltrim($line);
                if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//')) {
                    continue;
                }

                if (preg_match('/sURL \. \x27(' . $names . ')(?![A-Za-z])/i', $line)) {
                    $offenders[] = basename($file) . ':' . ($number + 1) . ' ' . trim($line);
                }
            }
        }

        // Assert
        $this->assertSame(
            [],
            $offenders,
            'these links leave the administration area — use adminUrl(): ' . "\n"
            . implode("\n", $offenders)
        );
    }

    /**
     * A link built for the area carries the prefix even from outside it.
     *
     * `adminUrl()` is not "stay where you are": an administration screen belongs in
     * the area, so a visitor who reached one at its bare path is moved into the area by
     * their first click rather than kept on a screen with the public site's layout.
     */
    public function testAdminUrlAlwaysCarriesThePrefix(): void
    {
        // Arrange — a configured area, and a request that is *not* inside it
        $_GET['r'] = 'Home';
        AdminArea::detect('admin', 80);
        $this->assertFalse(AdminArea::isActive());

        // Act & Assert
        $this->assertStringContainsString('admin/Users', adminUrl('Users'));
    }
}
