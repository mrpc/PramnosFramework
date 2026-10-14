<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\NavRegistry;
use Pramnos\Http\AdminArea;

/**
 * An application that **is** the administration — its own front controller — and the area it
 * serves, on every request.
 *
 * Four things went wrong with `AdminArea::serve()` called from `init()`: the navigation, built in
 * `init()` before it, linked to the site; the area's controllers and views under `src/Admin/` were
 * never in scope; a front controller in `www/admin/`, whose `sURL` is already the area's, got
 * `/admin/admin/…`; and from the second request one process served, the area was forgotten. The
 * config key `'admin' => ['serve' => true]` serves it from the constructor, and the state survives
 * between requests.
 */
#[CoversClass(AdminArea::class)]
#[CoversClass(Application::class)]
class AdminAreaServeTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $info = [];

    private Application $app;

    protected function setUp(): void
    {
        $this->app  = Application::getInstance();
        $this->info = $this->app->applicationInfo;
        AdminArea::reset();
        NavRegistry::reset();
        $_GET = [];
    }

    protected function tearDown(): void
    {
        $this->app->applicationInfo = $this->info;
        AdminArea::reset();
        NavRegistry::reset();
        $this->app->beginRequest();
        $_GET = [];
    }

    /**
     * `'admin' => ['serve' => true]`: every request is inside the area, the area's code is in
     * scope, its theme and front door apply, and a second request in the same process is still
     * inside — with nothing in the route rewritten.
     */
    public function testTheConfigServesTheAreaOnEveryRequest(): void
    {
        // Arrange
        $this->app->applicationInfo['admin'] = ['serve' => true, 'prefix' => 'admin', 'min_usertype' => 90, 'theme' => 'adminish', 'default_controller' => 'Dashboard'];
        $_GET = ['r' => 'Users/edit/5'];

        // Act
        $this->app->beginRequest();
        $first = [AdminArea::isActive(), AdminArea::served(), AdminArea::minUserType(), $this->app->area, $this->app->applicationInfo['theme'] ?? null, $_GET['r']];
        $this->app->beginRequest();
        $second = AdminArea::isActive();

        // Assert
        $this->assertSame([true, true, 90, 'Admin', 'adminish', 'Users/edit/5'], $first);
        $this->assertTrue($second, 'still the area on the next request');
    }

    /**
     * `serve()` called from code holds between requests too, and so does the area's own sign-in
     * page — both were set once and forgotten by the next request's reset.
     */
    public function testWhatTheApplicationDeclaredHoldsBetweenRequests(): void
    {
        // Arrange
        unset($this->app->applicationInfo['admin']);
        AdminArea::serve('admin', 80);
        AdminArea::useLogin('/admin/login');

        // Act
        $this->app->beginRequest();

        // Assert
        $this->assertTrue(AdminArea::isActive());
        $this->assertSame('Admin', $this->app->area, 'src/Admin/ in scope');
        $this->assertSame('/admin/login', AdminArea::loginUrl());
        $this->assertSame(80, AdminArea::minUserType());
    }

    /** A request outside a prefixed area is outside again, though the one before was inside. */
    public function testAPrefixedAreaIsDecidedPerRequest(): void
    {
        // Arrange
        $this->app->applicationInfo['admin'] = ['prefix' => 'admin'];
        $_GET = ['r' => 'admin/Users'];
        $this->app->beginRequest();
        $inside = AdminArea::isActive();

        // Act
        $_GET = ['r' => 'Posts'];
        $this->app->beginRequest();

        // Assert
        $this->assertTrue($inside);
        $this->assertFalse(AdminArea::isActive());
        $this->assertFalse(AdminArea::served());
        $this->assertSame('', $this->app->area);
    }

    /**
     * Where `sURL` already ends in the prefix — a front controller in `www/<prefix>/` — the area's
     * address is `sURL` itself, not the prefix twice. Under a site-root `sURL` the prefix is added.
     */
    public function testTheAreasAddressIsNotThePrefixTwice(): void
    {
        // Arrange — the suite's sURL is https://pramnosframework.test/, which "ends in" that segment
        $segment = basename(rtrim(\sURL, '/'));

        // Act
        AdminArea::serve($segment);
        $own = AdminArea::url('Users');
        AdminArea::reset();
        AdminArea::serve('admin');
        $root = AdminArea::url('Users');
        AdminArea::reset();
        AdminArea::detect('pramnosframework.test');
        $detected = AdminArea::url('Users');

        // Assert
        $this->assertSame(\sURL . 'Users', $own);
        $this->assertSame(\sURL . 'admin/Users', $root);
        $this->assertSame(\sURL . 'pramnosframework.test/Users', $detected, 'a prefixed area always adds its prefix');
    }

    /**
     * Called after the navigation was built, `serve()` builds it again on the area's URL — and
     * logs that the config is the place for it. Called again with the same prefix, nothing more.
     */
    public function testServingAfterTheNavigationRebuildsIt(): void
    {
        // Arrange — navigation built for the site, as init() does before an application's code
        unset($this->app->applicationInfo['admin']);
        $this->app->registerDefaultNavItems([]);
        $before = $this->dashboardUrl();

        // Act
        AdminArea::serve('admin', 90);
        $after = $this->dashboardUrl();
        NavRegistry::reset();
        AdminArea::serve('admin', 90);

        // Assert
        $this->assertStringNotContainsString('/admin/', $before);
        $this->assertSame(\sURL . 'admin/Dashboard', $after);
        $this->assertSame([], NavRegistry::all(), 'already serving it: nothing rebuilt');
    }

    /** The registered dashboard item's address. */
    private function dashboardUrl(): string
    {
        foreach (NavRegistry::all() as $item) {
            if ($item->id === 'admin.dashboard') {
                return $item->url;
            }
        }

        return '';
    }
}
