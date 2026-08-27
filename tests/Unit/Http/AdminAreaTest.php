<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Pramnos\Http\AdminArea;

/**
 * `AdminArea` — mounting the administration screens under a URL prefix.
 *
 * WHAT: whether a request is inside the area, and what the route looks like once
 *       the prefix has been taken off it.
 * WHY:  the whole design rests on the prefix being gone before routing splits the
 *       path, and on it being taken off *only* when it is genuinely a prefix. A
 *       segment test that is really a string test would send `/administration` and
 *       `/adminibbles` into the area, which is both wrong and a way in.
 *       `REQUEST_URI` staying intact is the other load-bearing detail: the return
 *       URL a login redirect carries comes from there, and a stripped one would
 *       bring the visitor back outside the area they were trying to reach.
 */
class AdminAreaTest extends TestCase
{
    protected function setUp(): void
    {
        AdminArea::reset();
        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/';
    }

    protected function tearDown(): void
    {
        AdminArea::reset();
        $_GET = [];
        unset($_SERVER['REQUEST_URI']);
    }

    /**
     * An empty prefix switches the area off, and touches nothing.
     *
     * This is the default state of every existing application, so it is the first
     * thing to pin: the feature must be invisible until configured.
     */
    public function testAnEmptyPrefixLeavesTheRequestAlone(): void
    {
        // Arrange
        $_GET['r'] = 'Users/edit/5';

        // Act
        $active = AdminArea::detect('');

        // Assert
        $this->assertFalse($active);
        $this->assertFalse(AdminArea::isActive());
        $this->assertSame('Users/edit/5', $_GET['r']);
    }

    /**
     * A request under the prefix is inside the area, and the route loses it.
     *
     * The remaining route is exactly what the same screen would receive without
     * the area, which is what lets one set of controllers serve both.
     */
    public function testARequestUnderThePrefixIsStripped(): void
    {
        // Arrange
        $_GET['r'] = 'admin/Users/edit/5';

        // Act
        $active = AdminArea::detect('admin');

        // Assert
        $this->assertTrue($active);
        $this->assertTrue(AdminArea::isActive());
        $this->assertSame('Users/edit/5', $_GET['r']);
    }

    /**
     * The bare prefix is the area's front page, not a controller called "admin".
     *
     * Left in place it would route to a controller of that name and 404.
     */
    public function testTheBarePrefixLeavesAnEmptyRoute(): void
    {
        // Arrange
        $_GET['r'] = 'admin';

        // Act / Assert
        $this->assertTrue(AdminArea::detect('admin'));
        $this->assertSame('', $_GET['r']);
    }

    /**
     * A leading slash on the route is tolerated.
     *
     * Rewrite rules differ on whether they capture one, and a request that
     * silently fell outside the area over a slash would be very hard to see.
     */
    public function testALeadingSlashIsTolerated(): void
    {
        // Arrange
        $_GET['r'] = '/admin/Logs';

        // Act / Assert
        $this->assertTrue(AdminArea::detect('admin'));
        $this->assertSame('Logs', $_GET['r']);
    }

    /**
     * A path that merely *starts with* the prefix's letters is not inside it.
     *
     * The most important test here. `/administration` shares five characters with
     * `/admin` and is a different page; a `str_starts_with($route, $prefix)` check
     * would put it inside the area, apply the admin theme to it, and — worse —
     * hand routing a mangled path.
     *
     * @param string $route A route that must NOT be treated as inside the area
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('routesOutsideTheArea')]
    public function testAPrefixMustBeAWholeSegment(string $route): void
    {
        // Arrange
        $_GET['r'] = $route;

        // Act
        $active = AdminArea::detect('admin');

        // Assert
        $this->assertFalse($active, $route . ' must not be inside the area');
        $this->assertSame($route, $_GET['r'], 'the route must be left exactly as it was');
    }

    /** @return array<string, array{0: string}> */
    public static function routesOutsideTheArea(): array
    {
        return [
            'longer word'      => ['administration/index'],
            'longer word bare' => ['administration'],
            'no prefix'        => ['Users/edit/5'],
            'prefix inside'    => ['app/admin/Users'],
            'empty route'      => [''],
        ];
    }

    /**
     * Detecting twice does not strip twice.
     *
     * A process that constructs two applications — a test suite, a worker
     * handling several requests — would otherwise turn `admin/admin/Users` into
     * `Users` on the second pass, or eat a real path segment.
     */
    public function testDetectionIsIdempotent(): void
    {
        // Arrange
        $_GET['r'] = 'admin/admin/Users';

        // Act
        AdminArea::detect('admin');
        $afterFirst = $_GET['r'];
        AdminArea::detect('admin');

        // Assert — one segment removed, and only once
        $this->assertSame('admin/Users', $afterFirst);
        $this->assertSame('admin/Users', $_GET['r']);
    }

    /**
     * The address the visitor asked for is preserved.
     *
     * Everything that needs to send somebody back where they were — a login
     * redirect's `return=`, session tracking, a log line — reads `REQUEST_URI`. A
     * stripped one would bring an administrator back to the public copy of the
     * page they were refused on.
     */
    public function testTheRequestUriIsNotRewritten(): void
    {
        // Arrange
        $_SERVER['REQUEST_URI'] = '/admin/Users/edit/5?tab=roles';
        $_GET['r']              = 'admin/Users/edit/5';

        // Act
        AdminArea::detect('admin');

        // Assert
        $this->assertSame('/admin/Users/edit/5?tab=roles', $_SERVER['REQUEST_URI']);
    }

    /**
     * The usertype floor is remembered for the guard to read later.
     *
     * Enforcement cannot happen here — refusing means redirecting, which needs a
     * session, and this runs before there is one.
     */
    public function testTheUsertypeFloorIsRemembered(): void
    {
        // Arrange
        $_GET['r'] = 'admin/Users';

        // Act
        AdminArea::detect('admin', 80);

        // Assert
        $this->assertSame(80, AdminArea::minUserType());
        $this->assertSame('admin', AdminArea::prefix());
    }

    /**
     * `url()` builds addresses inside the area.
     *
     * Callers use it without knowing whether an area is configured, so the
     * no-prefix case has to produce a working plain URL rather than a broken one.
     */
    public function testUrlBuildsAddressesInsideTheArea(): void
    {
        // Arrange
        $_GET['r'] = 'admin/Users';
        AdminArea::detect('admin');

        // Act / Assert
        $this->assertSame(\sURL . 'admin/Users', AdminArea::url('Users'));
        $this->assertSame(\sURL . 'admin/', AdminArea::url(),
            'a base ends in a slash, like sURL — callers concatenate onto it');
        $this->assertSame(\sURL . 'admin/Users', AdminArea::url('/Users'));
    }

    /**
     * With no area configured, `url()` is a plain application URL.
     *
     * This is what keeps the nav registration free of conditionals: the same call
     * produces `/Users` on an application with no admin area and `/admin/Users`
     * on one with.
     */
    public function testUrlFallsBackWhenNoAreaIsConfigured(): void
    {
        // Act / Assert — nothing detected
        $this->assertSame(\sURL . 'Users', AdminArea::url('Users'));
        $this->assertSame(\sURL, AdminArea::url());
    }
}
