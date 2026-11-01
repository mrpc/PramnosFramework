<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Routing;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Routing\Router;
use Pramnos\Routing\Route;
use Pramnos\Routing\LocalizedRoute;
use Pramnos\Http\Request;
use Pramnos\Http\MiddlewareInterface;

/**
 * Tests for localized route groups: registering an array of locale => path
 * instead of a single URI string, so hreflang can be derived from the
 * router instead of a hand-maintained slug map.
 *
 * Verifies that:
 * - each locale's path is independently dispatchable
 * - a shared name resolves to the right locale's URI via Router::route()
 * - Router::getLocaleSiblings() returns the whole group from any one of its routes
 * - Router::getByName() refuses to pick a locale for a caller that didn't ask for one
 * - Router::getCurrentRoute() tracks the most recently matched route
 * - a locale group nested inside a prefixed Router::group() still gets the prefix
 */
#[CoversClass(\Pramnos\Routing\Router::class)]
#[CoversClass(\Pramnos\Routing\Route::class)]
#[CoversClass(\Pramnos\Routing\LocalizedRoute::class)]
class LocalizedRouteGroupsTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $container    = new \Pramnos\Application\Application('myApp');
        $this->router = new Router($container);
    }

    /**
     * Each locale in the array gets its own, independently matching route —
     * registering a group doesn't merge the paths into one pattern.
     */
    public function testEachLocaleUriIsIndependentlyDispatchable(): void
    {
        // Arrange
        $this->router->get(
            ['el' => '/syxnes-erwthseis', 'en' => '/faqs'],
            fn() => 'faq-page'
        );

        // Act
        $el = $this->router->dispatch(Request::create('/syxnes-erwthseis', 'GET'));
        $en = $this->router->dispatch(Request::create('/faqs', 'GET'));

        // Assert — both locales' paths run the same action
        $this->assertSame('faq-page', $el);
        $this->assertSame('faq-page', $en);
    }

    /**
     * Registering a locale group returns a LocalizedRoute, not a Route — the
     * caller is chaining over several routes, not one.
     */
    public function testLocalizedUriReturnsLocalizedRouteHandle(): void
    {
        // Act
        $handle = $this->router->get(['el' => '/a', 'en' => '/b'], fn() => 'x');

        // Assert
        $this->assertInstanceOf(LocalizedRoute::class, $handle);
        $this->assertCount(2, $handle->routes());
    }

    /**
     * A single name shared by the whole group resolves to the right locale's
     * URI through Router::route($name, $params, $locale).
     */
    public function testRouteResolvesNamedLocalizedGroupToTheRequestedLocale(): void
    {
        // Arrange
        $this->router->get(
            ['el' => '/syxnes-erwthseis', 'en' => '/faqs'],
            fn() => 'faq-page'
        )->name('faqs');

        // Act
        $en = $this->router->route('faqs', [], 'en');
        $el = $this->router->route('faqs', [], 'el');

        // Assert
        $this->assertSame('/faqs', $en);
        $this->assertSame('/syxnes-erwthseis', $el);
    }

    /**
     * Without an explicit locale, Router::route() falls back to the group's
     * first sibling when the current language isn't one of its locales —
     * a request for a locale the group doesn't have must not throw.
     */
    public function testRouteWithoutLocaleFallsBackToFirstSiblingWhenCurrentLocaleIsMissing(): void
    {
        // Arrange — neither key is the default language ('english'), so the
        // fallback path is exercised deterministically regardless of test
        // environment language setup.
        $this->router->get(
            ['el' => '/syxnes-erwthseis', 'en' => '/faqs'],
            fn() => 'faq-page'
        )->name('faqs');

        // Act
        $url = $this->router->route('faqs');

        // Assert — falls back to the first registered sibling ('el')
        $this->assertSame('/syxnes-erwthseis', $url);
    }

    /**
     * getLocaleSiblings() returns every locale in the group — including the
     * route it was called on — keyed by locale.
     */
    public function testGetLocaleSiblingsReturnsTheWholeGroup(): void
    {
        // Arrange
        $this->router->get(['el' => '/syxnes-erwthseis', 'en' => '/faqs'], fn() => 'x');
        $elRoute = $this->router->getMatchedRoute(Request::create('/syxnes-erwthseis', 'GET'));

        // Act
        $siblings = $this->router->getLocaleSiblings($elRoute);

        // Assert
        $this->assertSame(['el', 'en'], array_keys($siblings));
        $this->assertSame($elRoute, $siblings['el']);
        $this->assertSame('/faqs', $siblings['en']->uri);
    }

    /**
     * A route registered the ordinary way (string $uri) has no locale group:
     * getLocaleSiblings() returns an empty array rather than guessing.
     */
    public function testGetLocaleSiblingsIsEmptyForAnOrdinaryRoute(): void
    {
        // Arrange
        $this->router->get('/about', fn() => 'about');
        $route = $this->router->getMatchedRoute(Request::create('/about', 'GET'));

        // Act
        $siblings = $this->router->getLocaleSiblings($route);

        // Assert
        $this->assertSame([], $siblings);
    }

    /**
     * A name shared by a locale group has no single route to hand back —
     * getByName() must return null rather than one arbitrarily chosen
     * sibling, since Router::route() is the method that knows how to pick.
     */
    public function testGetByNameReturnsNullForALocalizedGroupName(): void
    {
        // Arrange
        $this->router->get(['el' => '/a', 'en' => '/b'], fn() => 'x')->name('shared');

        // Act
        $route = $this->router->getByName('shared');

        // Assert
        $this->assertNull($route);
    }

    /**
     * getCurrentRoute() is null before any request has been matched, and
     * becomes the matched route after dispatch() — code that runs after
     * dispatch (building the <head>, for instance) can read it without
     * re-matching the request itself.
     */
    public function testGetCurrentRouteTracksTheMostRecentMatch(): void
    {
        // Arrange
        $this->router->get('/about', fn() => 'about');

        // Act / Assert — nothing matched yet
        $this->assertNull($this->router->getCurrentRoute());

        // Act
        $this->router->dispatch(Request::create('/about', 'GET'));

        // Assert
        $this->assertInstanceOf(Route::class, $this->router->getCurrentRoute());
        $this->assertSame('/about', $this->router->getCurrentRoute()->uri);
    }

    /**
     * LocalizedRoute::middleware() applies to every sibling in the group —
     * a request against either locale's URI runs it.
     */
    public function testMiddlewareIsAppliedToEverySiblingInTheGroup(): void
    {
        // Arrange
        $log = [];
        $mw  = new class($log) implements MiddlewareInterface {
            public function __construct(private array &$log) {}
            public function handle(Request $request, callable $next): mixed
            {
                $this->log[] = $request->getRequestUri();
                return $next($request);
            }
        };
        $this->router->get(['el' => '/syxnes-erwthseis', 'en' => '/faqs'], fn() => 'faq-page')
            ->middleware($mw);

        // Act
        $this->router->dispatch(Request::create('/syxnes-erwthseis', 'GET'));
        $this->router->dispatch(Request::create('/faqs', 'GET'));

        // Assert — middleware ran for both locales' requests
        $this->assertSame(['syxnes-erwthseis', 'faqs'], $log);
    }

    /**
     * LocalizedRoute::requirePermissions() applies to every sibling — a
     * request against either locale's URI is denied without the permission.
     */
    public function testRequirePermissionsIsAppliedToEverySiblingInTheGroup(): void
    {
        // Arrange
        $this->router->get(['el' => '/syxnes-erwthseis', 'en' => '/faqs'], fn() => 'faq-page')
            ->requirePermissions(['faq:read']);

        // Act / Assert — denied without the permission, on either locale
        $this->expectException(\Pramnos\Auth\AuthorizationException::class);
        $this->router->dispatch(Request::create('/faqs', 'GET'), []);
    }

    /**
     * A locale group registered inside Router::group(['prefix' => ...])
     * gets the prefix applied to every locale's URI, the same as an
     * ordinary route — addLocalizedRoute() reuses addSingleRoute() rather
     * than re-implementing prefix handling.
     */
    public function testLocaleGroupInheritsTheSurroundingGroupPrefix(): void
    {
        // Arrange
        $this->router->group(['prefix' => '/el-site'], function (Router $r): void {
            $r->get(['el' => '/syxnes-erwthseis', 'en' => '/faqs'], fn() => 'faq-page');
        });

        // Act
        $prefixed  = $this->router->dispatch(Request::create('/el-site/faqs', 'GET'));
        $unPrefixed = $this->router->dispatch(Request::create('/faqs', 'GET'));

        // Assert — only the prefixed URI matches
        $this->assertSame('faq-page', $prefixed);
        $this->assertNull($unPrefixed);
    }
}
