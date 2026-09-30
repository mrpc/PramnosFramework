<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Html;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The scaffolded account screens at phone width (390px), in all three themes.
 *
 * Measured on the tailwind theme after the page had stopped being wider than the screen: it
 * fitted, and read badly. The account menu was a tall card above every page, so the content
 * started a screen down; a label and its action shared a row and both were squeezed; the
 * dashboard's 2FA badge spilled out of its fixed height; the search box and its results panel
 * kept desktop minimum widths inside a phone header.
 *
 * None of that is visible on a desktop, so it is pinned here by reading the files — the same
 * approach as {@see ScaffoldSignInPracticesTest}. Each theme meets it in its own idiom:
 * Tailwind utilities, Bootstrap utilities, and classes in the plain-css stylesheet (inline
 * styles cannot be overridden by a media query, so plain-css moved them into classes).
 */
class ScaffoldAccountMobileTest extends TestCase
{
    /**
     * Per theme: the markers each rule is expressed with.
     *
     * @return array<string, array{string, array<string, string>}>
     */
    public static function themes(): array
    {
        return [
            'tailwind' => ['tailwind', [
                'navRow'   => 'flex-row flex-nowrap overflow-x-auto md:flex-col',
                'rowStack' => 'flex flex-col items-start gap-2 sm:flex-row sm:items-center',
                'dashHead' => 'flex flex-wrap items-center justify-between',
            ]],
            'bootstrap' => ['bootstrap', [
                'navRow'   => 'flex-row flex-md-column flex-nowrap overflow-auto',
                'rowStack' => 'd-flex flex-column flex-sm-row align-items-start align-items-sm-center',
                'dashHead' => 'd-flex flex-wrap justify-content-between',
            ]],
            'plain-css' => ['plain-css', [
                'navRow'   => 'account-nav',
                'rowStack' => 'pf-row-split',
                'dashHead' => 'display:flex;flex-wrap:wrap',
            ]],
        ];
    }

    /** Every `max-width: 639px` block of a stylesheet, joined — plain-css has two. */
    private static function phoneRules(string $css): string
    {
        preg_match_all('/@media \(max-width: 639px\) \{(.*?)\n\}/s', $css, $blocks);

        return implode("\n", $blocks[1]);
    }

    private static function read(string $theme, string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/scaffolding/themes/' . $theme . '/' . $relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * The account menu is one sideways-scrolling row on a phone, not a card above the page.
     *
     * Stacked vertically, five or six entries pushed every account page's content a whole
     * screen down. The row must not wrap (a wrapped row is the tall card again), must scroll
     * (`pf-account-nav` hides the scrollbar), and must mark the current entry for assistive
     * technology — colour alone is not a marker. The organisation link logic is kept.
     */
    #[DataProvider('themes')]
    public function testAccountMenuIsAHorizontalRowOnAPhone(string $theme, array $markers): void
    {
        // Arrange
        $sidebar = self::read($theme, 'views/partials/account_sidebar.html.php');
        $css     = self::read($theme, 'style.css');

        // Act
        $hidesScrollbar = (bool) preg_match('/\.pf-account-nav\s*\{[^}]*scrollbar-width:\s*none/', $css);

        // Assert
        $this->assertStringContainsString($markers['navRow'], $sidebar);
        $this->assertStringContainsString('pf-account-nav', $sidebar);
        $this->assertStringContainsString('aria-current="page"', $sidebar);
        $this->assertTrue($hidesScrollbar, 'the sideways row must not draw a scrollbar under itself');
        // The organisation-admin entry is still offered to the people who manage one.
        $this->assertStringContainsString('OrganizationAdmin::managedBy', $sidebar);
    }

    /**
     * plain-css: the grid collapses and the menu turns into a row in the same media query.
     *
     * Its markup has no utilities, so the phone form lives in the stylesheet — and the column
     * must be `minmax(0, 1fr)`: a bare `1fr` grows to the menu's full width and puts the
     * horizontal scroll back on the page instead of on the menu.
     */
    public function testPlainCssMenuRowLivesInTheStylesheet(): void
    {
        // Arrange
        $css = self::read('plain-css', 'style.css');

        // Act
        $matched = preg_match('/@media \(max-width: 720px\) \{(.*?)\n\}/s', $css, $block);

        // Assert
        $this->assertSame(1, $matched);
        $this->assertStringContainsString('grid-template-columns: minmax(0, 1fr)', $block[1]);
        $this->assertMatchesRegularExpression('/\.account-nav \{[^}]*flex-wrap: nowrap;[^}]*overflow-x: auto/', $block[1]);
    }

    /**
     * A description and its action ("Enable 2FA", "Manage Passkeys", "Change Password")
     * stack below `sm` instead of squeezing each other.
     *
     * Three rows on the security page plus the trusted-device and session lists: five in
     * total, and a row still using the plain side-by-side form is the one that squeezes.
     */
    #[DataProvider('themes')]
    public function testSecurityRowsStackBelowSm(string $theme, array $markers): void
    {
        // Arrange
        $security = self::read($theme, 'views/OAuth2/security.html.php');

        // Act
        $stacked = substr_count($security, $markers['rowStack']);

        // Assert
        $this->assertGreaterThanOrEqual($theme === 'plain-css' ? 3 : 5, $stacked);
        $this->assertStringNotContainsString('class="flex items-center justify-between px-4 py-4"', $security);
        $this->assertStringNotContainsString('class="card-body d-flex justify-content-between align-items-center"', $security);
        $this->assertStringNotContainsString('class="card-body" style="display:flex;justify-content:space-between;align-items:center"', $security);
    }

    /**
     * The same squeeze on the authorised-applications list and the passkey page header.
     *
     * An application's name, description and token count beside a Revoke button, and a
     * sentence beside "Add a passkey", are the same two-things-in-one-row shape as the
     * security page and fail the same way.
     */
    #[DataProvider('themes')]
    public function testOtherAccountRowsStackToo(string $theme, array $markers): void
    {
        // Arrange
        $apps     = self::read($theme, 'views/OAuth2/authorized_applications.html.php');
        $passkeys = self::read($theme, 'views/passkey/manage.html.php');

        // Act
        $appsMarker = $theme === 'bootstrap' ? 'col-12 col-sm' : $markers['rowStack'];

        // Assert
        $this->assertStringContainsString($appsMarker, $apps);
        $this->assertStringContainsString($markers['rowStack'], $passkeys);
        // The script-built passkey rows wrap rather than squeeze.
        $this->assertMatchesRegularExpression('/\.pf-pk-item\{[^}]*flex-wrap:wrap/', $passkeys);
    }

    /**
     * The dashboard heading and its 2FA badge wrap, and the badge text stays inside it.
     *
     * "⚠ 2FA Inactive" beside "Account Dashboard" wrapped onto two lines inside a badge of
     * fixed height, and the second line hung out of it.
     */
    #[DataProvider('themes')]
    public function testDashboardHeaderWrapsAndBadgeDoesNotSpill(string $theme, array $markers): void
    {
        // Arrange
        $dashboard = self::read($theme, 'views/dashboard/dashboard.html.php');
        $css       = self::read($theme, 'style.css');

        // Act
        $badgeNoWrap = match ($theme) {
            'tailwind'  => str_contains($dashboard, 'badge-warning inline-flex items-center whitespace-nowrap h-auto'),
            // Bootstrap's own .badge is `white-space: nowrap` with no fixed height.
            'bootstrap' => str_contains($dashboard, 'class="badge bg-warning'),
            default     => (bool) preg_match('/^\.badge \{[^}]*white-space: nowrap/m', $css),
        };

        // Assert
        $this->assertStringContainsString($markers['dashHead'], $dashboard);
        $this->assertTrue($badgeNoWrap);
    }

    /**
     * Below 640px the search box can shrink and its results span the screen.
     *
     * A 12rem input and a 20rem panel anchored to the input's right edge do not fit a 390px
     * header: the panel hung off the left of the screen.
     */
    #[DataProvider('themes')]
    public function testOmniboxFitsAPhoneHeader(string $theme, array $markers): void
    {
        // Arrange
        $css = self::read($theme, 'style.css');

        // Act
        $phone = self::phoneRules($css);

        // Assert
        $this->assertMatchesRegularExpression('/\.pf-omnibox-input \{ min-width: 7rem; \}/', $phone);
        $this->assertMatchesRegularExpression(
            '/\.pf-omnibox-results \{[^}]*position: fixed;[^}]*left: 0\.5rem;[^}]*right: 0\.5rem;[^}]*min-width: 0;/',
            $phone
        );
    }

    /**
     * A long breadcrumb trail scrolls on one line and a long crumb is truncated.
     *
     * The trails come from `Pramnos\Html\Breadcrumb` (`pf-breadcrumb`, or `breadcrumb` when a
     * project asks for it) and can carry an email address or a person's name; wrapped or
     * unbounded, one of those pushes the page wider than the screen.
     */
    #[DataProvider('themes')]
    public function testBreadcrumbsScrollAndTruncateOnAPhone(string $theme, array $markers): void
    {
        // Arrange
        $css = self::read($theme, 'style.css');

        // Act
        $phone = self::phoneRules($css);

        // Assert
        $this->assertMatchesRegularExpression('/\.pf-breadcrumb,\s*\.breadcrumb \{[^}]*flex-wrap: nowrap;[^}]*overflow-x: auto/', $phone);
        $this->assertMatchesRegularExpression('/\.pf-breadcrumb li > \*,\s*\.breadcrumb li > \* \{[^}]*max-width: 12rem;[^}]*text-overflow: ellipsis/', $phone);
    }
}
