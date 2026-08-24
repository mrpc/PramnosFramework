<?php

namespace Pramnos\Tests\Unit\Routing;

use PHPUnit\Framework\TestCase;
use Pramnos\Http\Request;
use Pramnos\Routing\Route;

/**
 * **A query string is not part of the path — and a placeholder used to eat it.**
 *
 * `Request::getRequestUri()` returns the request with its query string still attached.
 * `Route::matches()` used to try the compiled pattern against that string first and only
 * strip the query if nothing had matched.
 *
 * For a **static** route that was harmless: `/stations?playable=1` misses every pattern,
 * and the retry caught it. For a route ending in a **placeholder** it was not, because
 * the first attempt succeeded on the wrong string. A placeholder compiles to `[^/]+` by
 * default and a query string contains no `/`, so `/station/{slug}` matched
 * `/station/athens?fbclid=abc` and handed the controller a slug of `athens?fbclid=abc`.
 * The retry was unreachable — a match had already been returned.
 *
 * Reported from an application whose station pages answered **404 to every link shared on
 * Facebook**, which appends `fbclid`. `utm_source`, a `?page=2` on a parameterised listing
 * and a redirect carrying `?error=…` all failed the same way: silently, and only for
 * routes with a placeholder — which is why it survived so long.
 */
class RouteIgnoresQueryStringTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $server = [];

    protected function setUp(): void
    {
        $this->server = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
    }

    /**
     * A request whose URI is exactly what is asked for.
     *
     * `PHP_SELF` is set to `/index.php` because that is what a web request looks
     * like, not to work around anything. It used to be a workaround: the Request
     * constructor stripped `dirname(PHP_SELF)` from the URI unconditionally, and
     * under PHPUnit that is `…/vendor/bin`, so every URI here lost its first 23
     * characters. Fixed on 2026-08-24 — the strip now applies only when the
     * request really does start with that directory.
     */
    private function requestFor(string $uri, string $method = 'GET'): Request
    {
        // Arrange
        $_SERVER['REQUEST_URI']    = $uri;
        $_SERVER['PHP_SELF']       = '/index.php';
        $_SERVER['REQUEST_METHOD'] = $method;

        return new Request();
    }

    /**
     * **The bug, in one assertion: a placeholder route with a query string.**
     *
     * This is the failure as it was reported. It matched before the fix as well — the
     * difference is *what it matched with*, which the next test pins down.
     */
    public function testAPlaceholderRouteMatchesWhenAQueryStringIsPresent(): void
    {
        // Arrange
        $route = new Route('station/{slug}', 'GET', 'x');

        // Act
        $matched = $route->matches($this->requestFor('/station/athens?fbclid=abc123'));

        // Assert
        $this->assertTrue($matched, 'a shared link with a tracking parameter stopped matching its route');
    }

    /**
     * **And the placeholder holds the slug, not the slug plus the query string.**
     *
     * The assertion that actually fails without the fix. `parameters` is what the
     * dispatcher hands the controller, so a slug of `athens?fbclid=abc123` reaches a
     * lookup that cannot find it — a 404 on a page that exists.
     */
    public function testThePlaceholderDoesNotSwallowTheQueryString(): void
    {
        // Arrange
        $route = new Route('station/{slug}', 'GET', 'x');

        // Act
        $route->matches($this->requestFor('/station/athens?fbclid=abc123'));

        // Assert — the compiled route fills `parameters` with its named groups.
        $this->assertSame(
            'athens',
            $route->parameters['slug'] ?? null,
            'the query string was captured as part of the placeholder'
        );
    }

    /**
     * **Several parameters, and one that looks like a path.**
     *
     * `?return=/station/athens` contains slashes, so stripping has to happen on the
     * first `?` rather than on the last segment — and a naive `explode('/')` would
     * mis-split it.
     */
    public function testEveryParameterIsStrippedIncludingOnesThatLookLikePaths(): void
    {
        // Arrange
        $route = new Route('station/{slug}', 'GET', 'x');

        // Act
        $matched = $route->matches(
            $this->requestFor('/station/athens?return=/station/other&utm_source=x')
        );

        // Assert
        $this->assertTrue($matched);
        $this->assertSame('athens', $route->parameters['slug'] ?? null);
    }

    /**
     * **A static route keeps working, which it always did.**
     *
     * Kept as a test rather than assumed: the fix moved the strip from after the regex
     * to before it, so the path that used to work is the one most likely to be broken by
     * the change.
     */
    public function testAStaticRouteStillMatchesWithAQueryString(): void
    {
        // Arrange
        $route = new Route('stations', 'GET', 'x');

        // Act & Assert
        $this->assertTrue($route->matches($this->requestFor('/stations?playable=1')));
    }

    /**
     * **A route that declares its own query string is left alone.**
     *
     * This is why the strip is guarded rather than unconditional. Such a route matches
     * on its exact form, and stripping the request's query would make it unreachable.
     */
    public function testARouteDeclaringAQueryStringIsNotStripped(): void
    {
        // Arrange
        $route = new Route('legacy?page=2', 'GET', 'x');

        // Act & Assert
        $this->assertTrue(
            $route->matches($this->requestFor('/legacy?page=2')),
            'a route registered with a query string stopped matching its own form'
        );
    }

    /**
     * **A different path still does not match.**
     *
     * The other direction: stripping must not turn the pattern into something looser.
     * `/stations` must not answer for `/stationsxyz`.
     */
    public function testStrippingDoesNotLoosenThePattern(): void
    {
        // Arrange
        $route = new Route('stations', 'GET', 'x');

        // Act & Assert
        $this->assertFalse($route->matches($this->requestFor('/stationsxyz?playable=1')));
    }
}
