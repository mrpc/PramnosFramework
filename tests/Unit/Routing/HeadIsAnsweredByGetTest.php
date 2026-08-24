<?php

namespace Pramnos\Tests\Unit\Routing;

use PHPUnit\Framework\TestCase;
use Pramnos\Http\Request;
use Pramnos\Routing\Router;

/**
 * **Every page a router serves answered 404 to HEAD.**
 *
 * RFC 9110 §9.3.2: *"The HEAD method is identical to GET except that the server MUST NOT
 * send content in the response."* Routes are stored per method here, so `HEAD` had a table
 * of its own containing only the routes an application had explicitly declared for it —
 * which, in practice, is none. `getMatchedRoute()` looked in that empty table and returned
 * null, and the application answered 404.
 *
 * That is not a curiosity about an unusual verb. HEAD is what link checkers, uptime
 * monitors, `curl -I`, several crawlers and every "is this URL alive" tool send first. A
 * site could be entirely reachable and report as entirely broken.
 *
 * Reported from an application whose sitemap had just started working: 2,250 station pages
 * announced to crawlers, `GET` 200 and `HEAD` 404 on every one of them — including
 * `/sitemap.xml` and `/robots.txt` themselves.
 *
 * ## What is fixed, and what is deliberately not
 *
 * **Which route runs.** The body is the caller's business: PHP's SAPI drops the content of
 * a HEAD response, and an application writing its own output can read the method. A router
 * that tried to suppress output would be reaching past its job.
 */
class HeadIsAnsweredByGetTest extends TestCase
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
     * A request for a URI with a given method.
     *
     * `PHP_SELF` is set to `/index.php` because that is what a web request looks
     * like, not to work around anything. It used to be a workaround: the Request
     * constructor stripped `dirname(PHP_SELF)` from the URI unconditionally, and
     * under PHPUnit that is `…/vendor/bin`, so every URI here lost its first 23
     * characters. Fixed on 2026-08-24 — the strip now applies only when the
     * request really does start with that directory.
     */
    private function requestFor(string $uri, string $method): Request
    {
        // Arrange
        $_SERVER['REQUEST_URI']    = $uri;
        $_SERVER['PHP_SELF']       = '/index.php';
        $_SERVER['REQUEST_METHOD'] = $method;

        return new Request();
    }

    /**
     * **The bug: a GET route answers a HEAD request.**
     *
     * The one assertion the whole change exists for. Without it every page of every
     * application on this router is a 404 to half the automated tools on the internet.
     */
    public function testAGetRouteAnswersAHeadRequest(): void
    {
        // Arrange
        $router = new Router(null);
        $router->addRoute('stations', 'GET', static fn (): string => 'ok');

        // Act
        $matched = $router->getMatchedRoute($this->requestFor('/stations', 'HEAD'));

        // Assert
        $this->assertNotNull($matched, 'HEAD was refused for a URI that GET serves');
        $this->assertSame('stations', $matched->uri);
    }

    /**
     * **And it fills the parameters, so the controller receives what GET would.**
     *
     * The half a status-code-only assertion would miss: a route matched with an empty
     * `parameters` array runs its action with no arguments, which for
     * `/station/{slug}` means a lookup for nothing.
     */
    public function testTheParametersAreFilledAsTheyWouldBeForGet(): void
    {
        // Arrange
        $router = new Router(null);
        $router->addRoute('station/{slug}', 'GET', static fn (): string => 'ok');

        // Act
        $matched = $router->getMatchedRoute($this->requestFor('/station/athens', 'HEAD'));

        // Assert
        $this->assertNotNull($matched);
        $this->assertSame('athens', $matched->parameters['slug'] ?? null);
    }

    /**
     * **A HEAD route declared on purpose still wins.**
     *
     * The fallback is tried only when nothing in the HEAD table matched, so an application
     * that wants a cheaper HEAD than its GET — a existence check that skips the expensive
     * query — keeps it.
     */
    public function testAnExplicitHeadRouteIsPreferred(): void
    {
        // Arrange
        $router = new Router(null);
        $router->addRoute('thing', 'GET', static fn (): string => 'get');
        $router->addRoute('thing', 'HEAD', static fn (): string => 'head');

        // Act
        $matched = $router->getMatchedRoute($this->requestFor('/thing', 'HEAD'));

        // Assert — the route's own method says which table it came from.
        $this->assertNotNull($matched);
        $this->assertSame('HEAD', $matched->method);
    }

    /**
     * **A URI that GET does not serve is still a 404 to HEAD.**
     *
     * The other direction. A fallback that matched too eagerly would make every unknown
     * address answer HEAD, which is a worse lie than the 404 it replaced.
     */
    public function testHeadIsStillRefusedForAUriNobodyServes(): void
    {
        // Arrange
        $router = new Router(null);
        $router->addRoute('stations', 'GET', static fn (): string => 'ok');

        // Act & Assert
        $this->assertNull($router->getMatchedRoute($this->requestFor('/nowhere', 'HEAD')));
    }

    /**
     * **No other method borrows the GET table.**
     *
     * The fallback is HEAD's alone, because HEAD is the only method the specification
     * defines as GET-without-a-body. A POST answered by a GET route would run a read
     * handler for a write request — and, worse, make a route look like it accepts
     * submissions.
     */
    public function testPostDoesNotFallBackToGet(): void
    {
        // Arrange
        $router = new Router(null);
        $router->addRoute('stations', 'GET', static fn (): string => 'ok');

        // Act & Assert
        $this->assertNull($router->getMatchedRoute($this->requestFor('/stations', 'POST')));
        $this->assertNull($router->getMatchedRoute($this->requestFor('/stations', 'DELETE')));
    }

    /**
     * **GET itself is unchanged.**
     *
     * Kept because the fix moved the three lookups — exact, query-stripped, pattern —
     * into a helper, and the path most likely to be broken by a refactor is the one that
     * already worked.
     */
    public function testGetStillMatchesItsOwnRoutes(): void
    {
        // Arrange
        $router = new Router(null);
        $router->addRoute('stations', 'GET', static fn (): string => 'ok');
        $router->addRoute('station/{slug}', 'GET', static fn (): string => 'ok');

        // Act & Assert
        $this->assertNotNull($router->getMatchedRoute($this->requestFor('/stations', 'GET')));
        $this->assertNotNull(
            $router->getMatchedRoute($this->requestFor('/stations?playable=1', 'GET')),
            'a query string stopped a static route from matching'
        );
        $this->assertNotNull(
            $router->getMatchedRoute($this->requestFor('/station/athens?fbclid=x', 'GET'))
        );
    }
}
