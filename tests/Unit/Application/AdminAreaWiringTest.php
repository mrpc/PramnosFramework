<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use Pramnos\Http\AdminArea;

/**
 * How `Application` uses `AdminArea`: the theme swap and the usertype floor.
 *
 * WHAT: the two decisions the application makes once the area has been detected.
 * WHY:  the floor is what makes the area an area rather than a URL convention. It
 *       is not a substitute for each screen's own check — those still run, and
 *       several are stricter — but it is what stops an ordinary signed-in account
 *       browsing the whole of `/admin` if one screen forgot its own.
 *
 *       The two refusals are deliberately different, and that difference is
 *       tested: a guest is sent to sign in and brought back, while a signed-in
 *       user who is simply not an administrator is sent to the site root. Sending
 *       the second one to a login form they are already past reads as a broken
 *       session, and they retype their password instead of understanding.
 *
 * The methods are `protected`, so a subclass exposes them and supplies the two
 * things they read — the configuration and the current user — with no database,
 * session or request involved.
 */
class AdminAreaWiringTest extends TestCase
{
    protected function setUp(): void
    {
        AdminArea::reset();
        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/admin/Users';
    }

    protected function tearDown(): void
    {
        AdminArea::reset();
        $_GET = [];
        unset($_SERVER['REQUEST_URI']);
    }

    /**
     * Inside the area, the configured theme replaces the site theme.
     *
     * This is the whole of the layout switch: no controller knows which theme it
     * is being rendered into, and none has to.
     */
    public function testTheAreaThemeReplacesTheSiteTheme(): void
    {
        // Arrange
        $_GET['r'] = 'admin/Users';
        $app = new InspectableAdminApplication([
            'theme' => 'main',
            'admin' => ['prefix' => 'admin', 'theme' => 'backoffice'],
        ]);

        // Act
        $app->enterArea();

        // Assert
        $this->assertTrue(AdminArea::isActive());
        $this->assertSame('backoffice', $app->info['theme']);
    }

    /**
     * Outside the area, the site theme stands.
     */
    public function testTheSiteThemeStandsOutsideTheArea(): void
    {
        // Arrange
        $_GET['r'] = 'Users';
        $app = new InspectableAdminApplication([
            'theme' => 'main',
            'admin' => ['prefix' => 'admin', 'theme' => 'backoffice'],
        ]);

        // Act
        $app->enterArea();

        // Assert
        $this->assertFalse(AdminArea::isActive());
        $this->assertSame('main', $app->info['theme']);
    }

    /**
     * An area with no theme configured keeps the site theme.
     *
     * Mounting screens under a prefix and restyling them are separate decisions,
     * and an application may want only the first.
     */
    public function testAnAreaWithoutAThemeKeepsTheSiteTheme(): void
    {
        // Arrange
        $_GET['r'] = 'admin/Users';
        $app = new InspectableAdminApplication([
            'theme' => 'main',
            'admin' => ['prefix' => 'admin'],
        ]);

        // Act
        $app->enterArea();

        // Assert
        $this->assertTrue(AdminArea::isActive());
        $this->assertSame('main', $app->info['theme']);
    }

    /**
     * Outside the area the guard has no opinion, whatever the user is.
     *
     * A public page must not acquire a usertype requirement because an admin area
     * exists somewhere else in the application.
     */
    public function testTheGuardIsSilentOutsideTheArea(): void
    {
        // Arrange
        $_GET['r'] = 'Home';
        $app = new InspectableAdminApplication(['admin' => ['prefix' => 'admin', 'min_usertype' => 80]]);
        $app->enterArea();

        // Act / Assert — no user at all, and still allowed
        $this->assertTrue($app->allow());
        $this->assertSame([], $app->redirects);
    }

    /**
     * With no floor configured, the area is open to anyone who can reach a screen.
     *
     * Each controller still enforces its own requirement; the area itself simply
     * has no additional one.
     */
    public function testNoFloorMeansNoAreaLevelRefusal(): void
    {
        // Arrange
        $_GET['r'] = 'admin/Users';
        $app = new InspectableAdminApplication(['admin' => ['prefix' => 'admin']]);
        $app->enterArea();

        // Act / Assert
        $this->assertTrue($app->allow());
    }

    /**
     * A guest is sent to sign in, and carries the address they were refused.
     *
     * The `return=` is the point: without it, signing in lands them on the
     * dashboard and they have to find their way back to whatever they had opened.
     */
    public function testAGuestIsSentToSignInAndBroughtBack(): void
    {
        // Arrange
        $_GET['r'] = 'admin/Users';
        $app = new InspectableAdminApplication(['admin' => ['prefix' => 'admin', 'min_usertype' => 80]]);
        $app->enterArea();
        $app->user = null;

        // Act
        $allowed = $app->allow();

        // Assert
        $this->assertFalse($allowed);
        $this->assertCount(1, $app->redirects);
        $this->assertStringContainsString('login', $app->redirects[0]);
        $this->assertStringContainsString(urlencode('/admin/Users'), $app->redirects[0]);
    }

    /**
     * A signed-in user below the floor is sent to the site root, not to login.
     *
     * They are already authenticated; a login form would tell them their session
     * is broken when the real answer is that this is not for them.
     */
    public function testAnOrdinaryUserIsSentToTheSiteRoot(): void
    {
        // Arrange
        $_GET['r'] = 'admin/Users';
        $app = new InspectableAdminApplication(['admin' => ['prefix' => 'admin', 'min_usertype' => 80]]);
        $app->enterArea();
        $app->user = (object) ['userid' => 42, 'usertype' => 0];

        // Act
        $allowed = $app->allow();

        // Assert
        $this->assertFalse($allowed);
        $this->assertSame([\sURL], $app->redirects);
        $this->assertStringNotContainsString('login', $app->redirects[0]);
    }

    /**
     * A user at or above the floor is allowed through.
     *
     * Both boundaries: exactly the floor passes, and one below it does not — an
     * off-by-one here either locks out every administrator or lets in the tier
     * below them.
     */
    public function testTheFloorIsInclusive(): void
    {
        // Arrange
        $_GET['r'] = 'admin/Users';
        $app = new InspectableAdminApplication(['admin' => ['prefix' => 'admin', 'min_usertype' => 80]]);
        $app->enterArea();

        // Act / Assert — exactly at the floor
        $app->user = (object) ['userid' => 7, 'usertype' => 80];
        $this->assertTrue($app->allow());
        $this->assertSame([], $app->redirects);

        // Act / Assert — one below it
        $app->user = (object) ['userid' => 7, 'usertype' => 79];
        $this->assertFalse($app->allow());
        $this->assertSame([\sURL], $app->redirects);
    }

    /**
     * The reserved low user ids do not count as signed in.
     *
     * `userid` 0 and 1 are the framework's guest and system rows; treating either
     * as a user would let an unauthenticated request past the first branch and
     * then be judged on a usertype it does not have.
     */
    public function testReservedUserIdsAreTreatedAsGuests(): void
    {
        // Arrange
        $_GET['r'] = 'admin/Users';
        $app = new InspectableAdminApplication(['admin' => ['prefix' => 'admin', 'min_usertype' => 80]]);
        $app->enterArea();

        foreach ([0, 1] as $userid) {
            // Act
            $app->redirects = [];
            $app->user      = (object) ['userid' => $userid, 'usertype' => 99];
            $allowed        = $app->allow();

            // Assert — refused, and refused as a guest
            $this->assertFalse($allowed, 'userid ' . $userid . ' must not be a user');
            $this->assertStringContainsString('login', $app->redirects[0]);
        }
    }
}

/**
 * Application with the two admin-area methods exposed and their inputs injected.
 *
 * `currentUser()` and `isLogged()` are overridden rather than mocked so the test
 * needs no session; `setRedirect()` is captured rather than performed.
 */
class InspectableAdminApplication extends \Pramnos\Application\Application
{
    /** @var array<string, mixed> The application config under test */
    public array $info;

    /** The user the guard should see, or null for a guest. */
    public mixed $user = null;

    /** @var list<string> Captured redirect targets */
    public array $redirects = [];

    /** @param array<string, mixed> $info */
    public function __construct(array $info)
    {
        $this->info = $info;
    }

    public function enterArea(): void
    {
        $this->applicationInfo = $this->info;
        $this->enterAdminAreaIfRequested();
        $this->info = $this->applicationInfo;
    }

    public function allow(): bool
    {
        return $this->allowAdminAreaRequest();
    }

    public function setRedirect($url = '')
    {
        $this->redirects[] = $url;
    }

    protected function adminAreaUser(): mixed
    {
        return $this->user;
    }

    protected function adminAreaUserIsSignedIn(): bool
    {
        return $this->user !== null && (int) ($this->user->userid ?? 0) > 1;
    }
}
