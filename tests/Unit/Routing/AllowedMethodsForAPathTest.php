<?php

namespace Pramnos\Tests\Unit\Routing;

use PHPUnit\Framework\TestCase;
use Pramnos\Http\Request;
use Pramnos\Routing\Router;

/**
 * **A wrong verb and a wrong address were the same answer.**
 *
 * `getMatchedRoute()` answers "matched" or "not matched" for the request's own
 * method. There was no way to ask whether the path would have matched under a
 * *different* one, so inside the kernel a `GET` on a `POST`-only endpoint fell
 * through exactly as a path nobody had declared did. An application could only
 * answer **404** for both — honest, and unhelpful: it tells an integrator to
 * check the address when the address was right.
 *
 * The correct answer for the first is **405 Method Not Allowed** with an
 * `Allow` header, and RFC 9110 §15.5.6 makes that header mandatory on a 405.
 * You cannot send it without knowing which methods the path has.
 *
 * `allowedMethodsFor()` answers that question and nothing else. What the
 * application does with it — 405, 404, or something of its own — stays with the
 * application.
 *
 * ## Why the router has to be the one to answer
 *
 * `getRoutesWithPermissions()` already exposes the table keyed by method, so an
 * application *could* walk it. But matching a URI pattern against a path is the
 * router's own rule — placeholders, optional segments, the query-string forms —
 * and re-deriving it in the application would be a second spelling of the
 * matching logic, which is exactly the duplication these filings keep removing.
 */
class AllowedMethodsForAPathTest extends TestCase
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
        $_SERVER['REQUEST_URI']    = $uri;
        $_SERVER['PHP_SELF']       = '/index.php';
        $_SERVER['REQUEST_METHOD'] = $method;

        return new Request();
    }

    // ── The reported case ───────────────────────────────────────────────────

    /**
     * **The filed case: a GET on a POST-only endpoint.**
     *
     * `/api/stations/signup` is POST-only. Asked about a GET request for it,
     * the router must say the path exists and names POST — which is what lets
     * the application answer 405 with `Allow: POST` instead of a 404 that sends
     * the integrator looking at the URL.
     */
    public function testAPostOnlyPathReportsPostForAGetRequest(): void
    {
        // Arrange
        $router = new Router(null);
        $router->addRoute('/api/stations/signup', 'POST', static fn (): string => 'ok');

        // Act
        $request = $this->requestFor('/api/stations/signup', 'GET');

        // Assert — the path exists, for POST.
        $this->assertNull($router->getMatchedRoute($request),
            'the GET itself still does not match, which is the premise');
        $this->assertSame(['POST'], $router->allowedMethodsFor($request));
    }

    /**
     * A path nobody declared reports nothing, which is how an application tells
     * 404 from 405.
     *
     * Without this distinction the whole method is useless: an empty answer has
     * to mean "no such path", not "I did not look".
     */
    public function testAnUndeclaredPathReportsNoMethods(): void
    {
        // Arrange
        $router = new Router(null);
        $router->addRoute('/api/stations/signup', 'POST', static fn (): string => 'ok');

        // Act
        $request = $this->requestFor('/api/nope', 'GET');

        // Assert
        $this->assertSame([], $router->allowedMethodsFor($request));
    }

    /**
     * The two answers side by side, which is the decision an application
     * actually makes.
     *
     * Written as one test because the value of either answer is that it differs
     * from the other; asserting them apart would not show that.
     */
    public function testTheWrongVerbAndTheWrongAddressNowDiffer(): void
    {
        // Arrange
        $router = new Router(null);
        $router->addRoute('/api/stations/signup', 'POST', static fn (): string => 'ok');

        // Act — each request is asked about before the next one is built.
        // Request reads $_SERVER lazily, so two live at once would both see
        // whichever URI was set last.
        $wrongVerb      = $this->requestFor('/api/stations/signup', 'GET');
        $verbMatched    = $router->getMatchedRoute($wrongVerb);
        $verbAllowed    = $router->allowedMethodsFor($wrongVerb);

        $wrongAddress   = $this->requestFor('/api/nope', 'GET');
        $addressMatched = $router->getMatchedRoute($wrongAddress);
        $addressAllowed = $router->allowedMethodsFor($wrongAddress);

        // Assert — both are unmatched, and they are no longer the same answer.
        $this->assertNull($verbMatched);
        $this->assertNull($addressMatched);
        $this->assertNotSame($verbAllowed, $addressAllowed);
        $this->assertSame(['POST'], $verbAllowed);
        $this->assertSame([], $addressAllowed);
    }

    // ── What it reports ─────────────────────────────────────────────────────

    /**
     * Every method the path is declared for is reported, sorted, so the value
     * can go straight into an `Allow` header.
     *
     * Sorted rather than in declaration order because the header is a set: a
     * stable string is easier to test, cache and compare than one that depends
     * on the order routes happened to be registered in.
     */
    public function testEveryDeclaredMethodIsReportedSorted(): void
    {
        // Arrange — deliberately registered out of alphabetical order.
        $router = new Router(null);
        $router->addRoute('/api/stations/{id}', 'PUT',    static fn (): string => 'u');
        $router->addRoute('/api/stations/{id}', 'DELETE', static fn (): string => 'd');
        $router->addRoute('/api/stations/{id}', 'PATCH',  static fn (): string => 'p');

        // Act
        $allowed = $router->allowedMethodsFor(
            $this->requestFor('/api/stations/7', 'POST')
        );

        // Assert
        $this->assertSame(['DELETE', 'PATCH', 'PUT'], $allowed);
        $this->assertSame('DELETE, PATCH, PUT', implode(', ', $allowed),
            'the value an Allow header wants, with no further work');
    }

    /**
     * The request's own method is included when it matches.
     *
     * The method answers "what is this path declared for", not "what else could
     * you have used" — an application building an `Allow` header for an OPTIONS
     * response needs the whole set, including the verb that was sent.
     */
    public function testTheRequestsOwnMethodIsIncludedWhenItMatches(): void
    {
        // Arrange
        $router = new Router(null);
        $router->addRoute('/api/stations', 'GET',  static fn (): string => 'g');
        $router->addRoute('/api/stations', 'POST', static fn (): string => 'p');

        // Act
        $allowed = $router->allowedMethodsFor(
            $this->requestFor('/api/stations', 'GET')
        );

        // Assert
        $this->assertSame(['GET', 'HEAD', 'POST'], $allowed);
    }

    /**
     * **HEAD is reported wherever GET is**, because that is what the router
     * will actually do.
     *
     * `getMatchedRoute()` falls back from HEAD to GET, per RFC 9110 §9.3.2. An
     * `Allow` header that omitted HEAD would contradict the router's own
     * behaviour — it would tell a client that a request it is about to serve
     * successfully is not allowed.
     */
    public function testHeadIsReportedWhereverGetIs(): void
    {
        // Arrange — GET only; nobody declared HEAD.
        $router = new Router(null);
        $router->addRoute('/stations', 'GET', static fn (): string => 'ok');

        // Act
        $allowed = $router->allowedMethodsFor(
            $this->requestFor('/stations', 'DELETE')
        );

        // Assert — and the router does serve HEAD here, which is the reason.
        $this->assertSame(['GET', 'HEAD'], $allowed);
        $this->assertNotNull(
            $router->getMatchedRoute($this->requestFor('/stations', 'HEAD')),
            'HEAD really is served, so the header must not deny it'
        );
    }

    /**
     * HEAD is not invented for a path that has no GET. The fallback exists
     * because a GET route answers HEAD, so with no GET there is nothing to
     * fall back to.
     */
    public function testHeadIsNotReportedWithoutAGet(): void
    {
        // Arrange
        $router = new Router(null);
        $router->addRoute('/api/events', 'POST', static fn (): string => 'ok');

        // Act
        $allowed = $router->allowedMethodsFor(
            $this->requestFor('/api/events', 'GET')
        );

        // Assert
        $this->assertSame(['POST'], $allowed);
    }

    /**
     * An explicitly declared HEAD is reported once, not twice.
     *
     * The GET fallback sets a flag on a keyed set rather than appending, so a
     * path declared for both cannot produce `Allow: GET, HEAD, HEAD`.
     */
    public function testAnExplicitHeadIsNotDuplicated(): void
    {
        // Arrange
        $router = new Router(null);
        $router->addRoute('/stations', 'GET',  static fn (): string => 'g');
        $router->addRoute('/stations', 'HEAD', static fn (): string => 'h');

        // Act
        $allowed = $router->allowedMethodsFor(
            $this->requestFor('/stations', 'POST')
        );

        // Assert
        $this->assertSame(['GET', 'HEAD'], $allowed);
    }

    // ── It uses the router's own matching, not a guess ──────────────────────

    /**
     * Placeholders are matched, which is the whole reason this lives on the
     * router.
     *
     * A concrete path that only matches a pattern must still be reported. An
     * application re-deriving this from `getRoutesWithPermissions()` would have
     * to re-implement placeholder matching to get here.
     */
    public function testAPatternRouteIsMatchedByAConcretePath(): void
    {
        // Arrange
        $router = new Router(null);
        $router->addRoute('/api/stations/{id}/tracks', 'POST', static fn (): string => 'ok');

        // Act
        $allowed = $router->allowedMethodsFor(
            $this->requestFor('/api/stations/2250/tracks', 'GET')
        );

        // Assert
        $this->assertSame(['POST'], $allowed);
    }

    /**
     * A path that matches no pattern reports nothing, so a near-miss on a
     * pattern route is still a 404 rather than a 405 for the wrong endpoint.
     */
    public function testANearMissOnAPatternReportsNothing(): void
    {
        // Arrange
        $router = new Router(null);
        $router->addRoute('/api/stations/{id}/tracks', 'POST', static fn (): string => 'ok');

        // Act
        $allowed = $router->allowedMethodsFor(
            $this->requestFor('/api/stations/2250/listeners', 'GET')
        );

        // Assert
        $this->assertSame([], $allowed);
    }

    /**
     * A query string does not hide the path.
     *
     * `getRequestUri()` keeps the query string, and the router strips it as one
     * of its lookups. Asking about `/api/stations/signup?ref=x` must find the
     * same route the bare path does — otherwise the 405 answer would depend on
     * whether the caller happened to append a parameter.
     */
    public function testAQueryStringDoesNotHideThePath(): void
    {
        // Arrange
        $router = new Router(null);
        $router->addRoute('/api/stations/signup', 'POST', static fn (): string => 'ok');

        // Act
        $allowed = $router->allowedMethodsFor(
            $this->requestFor('/api/stations/signup?ref=newsletter', 'GET')
        );

        // Assert
        $this->assertSame(['POST'], $allowed);
    }

    /**
     * A router with no routes at all answers empty rather than raising — the
     * method is asked on the failure path, where the least useful thing it
     * could do is throw.
     */
    public function testAnEmptyRouterAnswersEmpty(): void
    {
        // Arrange
        $router = new Router(null);

        // Act + Assert
        $this->assertSame(
            [],
            $router->allowedMethodsFor($this->requestFor('/anything', 'GET'))
        );
    }

    // ── The shape of the answer, in use ─────────────────────────────────────

    /**
     * The three-way decision an application makes, written out once.
     *
     * This is documentation as much as verification: it is the code the guide
     * shows, run against the real router, so the guide cannot drift away from
     * what the method actually returns.
     */
    public function testTheThreeWayDecisionAnApplicationMakes(): void
    {
        // Arrange
        $router = new Router(null);
        $router->addRoute('/api/stations', 'GET',  static fn (): string => 'list');
        $router->addRoute('/api/stations', 'POST', static fn (): string => 'create');

        $decide = static function (Request $request) use ($router): string {
            if ($router->getMatchedRoute($request) !== null) {
                return 'dispatch';
            }
            $allowed = $router->allowedMethodsFor($request);

            return $allowed === []
                ? '404'
                : '405 Allow: ' . implode(', ', $allowed);
        };

        // Act + Assert
        $this->assertSame('dispatch', $decide(
            $this->requestFor('/api/stations', 'GET')
        ));
        $this->assertSame('405 Allow: GET, HEAD, POST', $decide(
            $this->requestFor('/api/stations', 'DELETE')
        ));
        $this->assertSame('404', $decide(
            $this->requestFor('/api/nowhere', 'GET')
        ));
    }
}
