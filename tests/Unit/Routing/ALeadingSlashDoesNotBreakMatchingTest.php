<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Routing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Http\Request;
use Pramnos\Routing\Route;
use Pramnos\Routing\Router;

/**
 * A request URI that arrives with a leading slash still matches its route.
 *
 * Filed by a consuming application, and the report was exact:
 * `Route::matches()` builds the string it hands to the compiled regex as
 * `'/' . $uri`. When `$uri` already begins with a slash that produces
 * `//stations/7`, and Symfony's compiled pattern is anchored — so **every route
 * with a placeholder misses**, while static routes are unaffected because they
 * are answered by the `==` comparison above it.
 *
 * The two sibling call sites that build the same kind of string already write
 * `'/' . ltrim($uri, '/')` — `Routing\OpenApiGenerator` and `Router::add()`.
 * This one did not.
 *
 * **The root cause is one level up.** `Request::__construct()` stores the URI
 * trimmed (`trim($_SERVER['REQUEST_URI'], '/')`) and so does the subdirectory
 * branch, but `Request::create()` — the factory a test or a console caller uses
 * — assigned `$uri` verbatim. So `getRequestUri()` returned `stations/7` for a
 * real request and `/stations/7` for a created one, and *every* consumer of that
 * value inherited the discrepancy, not only routing. All 114 existing call sites
 * pass a leading slash, because that is how anybody writes a URL.
 *
 * Both are fixed: the factory now produces the same shape as the constructor,
 * and the match is defensive like its siblings.
 */
#[CoversClass(Route::class)]
#[CoversClass(Request::class)]
class ALeadingSlashDoesNotBreakMatchingTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $server = [];

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $_SERVER['PHP_SELF'] = '/index.php';
        Request::resetInstance();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        Request::resetInstance();
    }

    /**
     * The factory produces the same URI shape as a real request.
     *
     * This is the invariant everything else here depends on: two ways of
     * building a Request must not disagree about what the request was for.
     *
     * The reversal that reddens this: restore `self::$requestUri = $uri;` in
     * `Request::create()`.
     *
     * @return array<string,array{string,string}>
     */
    public static function uriShapeProvider(): array
    {
        return [
            'a leading slash'  => ['/stations/7', 'stations/7'],
            'no leading slash' => ['stations/7', 'stations/7'],
            'a trailing slash' => ['/stations/7/', 'stations/7'],
            'the root'         => ['/', ''],
            'with a query'     => ['/stations?page=2', 'stations?page=2'],
        ];
    }

    #[DataProvider('uriShapeProvider')]
    public function testTheFactoryStoresTheUriTheSameWayTheConstructorDoes(
        string $given, string $expected
    ): void {
        // Act
        $request = Request::create($given);

        // Assert
        $this->assertSame($expected, $request->getRequestUri());
    }

    /**
     * A route with a placeholder matches a request built with a leading slash.
     *
     * The reported symptom, reproduced directly. Before the fix the compiled
     * pattern was tried against `//stations/7` and missed.
     *
     * The reversal that reddens this: put back `'/' . $uri` in
     * `Route::matches()` **and** the untrimmed assignment in
     * `Request::create()` — either fix alone keeps this green, which is why the
     * factory has its own test above.
     */
    public function testAPlaceholderRouteMatchesALeadingSlashRequest(): void
    {
        // Arrange
        $route = new Route('/stations/{id}', 'GET', 'Stations@show');

        // Act
        $matched = $route->matches(Request::create('/stations/7', 'GET'));

        // Assert
        $this->assertTrue($matched);
    }

    /**
     * And the placeholder's value is the segment, not the segment with a slash
     * glued to it.
     *
     * A match that captured `/7` would be worse than a miss: the controller
     * receives an id that looks right in a log line and finds no record.
     */
    public function testThePlaceholderCapturesTheBareValue(): void
    {
        // Arrange
        $route = new Route('/stations/{id}', 'GET', 'Stations@show');

        // Act
        $route->matches(Request::create('/stations/7', 'GET'));
        $parameters = $route->parameters;

        // Assert
        $this->assertArrayHasKey('id', $parameters);
        $this->assertSame('7', $parameters['id']);
    }

    /**
     * Several placeholder shapes, all through the router this time.
     *
     * Going through `Router::getMatchedRoute()` rather than `Route` directly
     * proves the whole path works, not just the one method that was edited.
     *
     * @return array<string,array{string,string}>
     */
    public static function placeholderRouteProvider(): array
    {
        return [
            'one placeholder'    => ['/stations/{id}', '/stations/7'],
            'two placeholders'   => ['/stations/{id}/shows/{show}', '/stations/7/shows/3'],
            'a trailing segment' => ['/stations/{id}/schedule', '/stations/7/schedule'],
            'a slug'             => ['/station/{slug}', '/station/athens-radio'],
        ];
    }

    #[DataProvider('placeholderRouteProvider')]
    public function testTheRouterResolvesPlaceholderRoutesForLeadingSlashUris(
        string $pattern, string $uri
    ): void {
        // Arrange
        $router = new Router(null);
        $router->get($pattern, 'Stations@show');

        // Act
        $matched = $router->getMatchedRoute(Request::create($uri, 'GET'));

        // Assert
        $this->assertInstanceOf(Route::class, $matched);
        $this->assertSame($pattern, $matched->uri);
    }

    /**
     * A static route was never affected, and still is not.
     *
     * It is answered by the `==` comparison before the regex is reached, which
     * is exactly why this bug survived: the routes anyone would test first are
     * the ones that worked.
     */
    public function testStaticRoutesAreUnaffected(): void
    {
        // Arrange
        $router = new Router(null);
        $router->get('/stations', 'Stations@index');

        // Act & Assert
        $this->assertInstanceOf(
            Route::class, $router->getMatchedRoute(Request::create('/stations', 'GET'))
        );
    }

    /**
     * A route that does not match still does not match.
     *
     * The guard against "fixing" this by making the pattern looser: an ltrim
     * that turned into a general slash-collapse would start matching things it
     * should not.
     */
    public function testANonMatchingUriStillMisses(): void
    {
        // Arrange
        $route = new Route('/stations/{id}', 'GET', 'Stations@show');

        // Act & Assert
        $this->assertFalse($route->matches(Request::create('/artists/7', 'GET')));
        $this->assertFalse($route->matches(Request::create('/stations/7/extra', 'GET')));
    }
}
