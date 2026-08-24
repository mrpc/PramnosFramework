<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\Adapter\ArrayAdapter;
use Pramnos\Cache\Page\PageCache;
use Pramnos\Http\Middleware\PageCacheMiddleware;
use Pramnos\Http\Request;
use Pramnos\Http\Response;

/**
 * The middleware that wraps the pipeline in the page cache.
 *
 * There is very little of it on purpose — lookup, `$next`, store — so what these
 * tests are really checking is the three ways that little can be wrong: serving
 * a hit without running the pipeline, not storing something that is not a
 * Response, and not letting one request's runtime state reach the next.
 */
#[CoversClass(PageCacheMiddleware::class)]
class PageCacheMiddlewareTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $server = [];
    /** @var array<string,mixed> */
    private array $get = [];
    /** @var array<string,mixed> */
    private array $cookie = [];

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $this->get    = $_GET;
        $this->cookie = $_COOKIE;

        $_GET    = [];
        $_COOKIE = [];
        $_SERVER['HTTP_HOST']      = 'example.test';
        $_SERVER['PHP_SELF']       = '/index.php';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI']    = '/stations';

        PageCache::resetRuntime();
        Request::resetInstance();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_GET    = $this->get;
        $_COOKIE = $this->cookie;

        PageCache::resetRuntime();
        Request::resetInstance();
    }

    private function request(string $uri = '/stations'): Request
    {
        $_SERVER['REQUEST_URI'] = $uri;
        Request::resetInstance();

        return new Request();
    }

    private function middleware(array $config = []): PageCacheMiddleware
    {
        return new PageCacheMiddleware(new PageCache(
            array_merge(['enabled' => true], $config), new ArrayAdapter()
        ));
    }

    /**
     * A miss runs the pipeline; the following hit does not.
     *
     * The `$ran` counter is the assertion that matters — a cache that returns
     * the right bytes *after* rendering the page has saved nothing at all,
     * and every other assertion here would still pass.
     */
    public function testAHitShortCircuitsThePipeline(): void
    {
        // Arrange
        $middleware = $this->middleware();
        $ran = 0;
        $next = function () use (&$ran): Response {
            $ran++;
            return Response::make('<html>rendered</html>');
        };

        // Act
        $first  = $middleware->handle($this->request(), $next);
        $second = $middleware->handle($this->request(), $next);

        // Assert
        $this->assertSame(1, $ran, 'the pipeline must run once, not twice');
        $this->assertSame('<html>rendered</html>', $first->getBody());
        $this->assertSame('<html>rendered</html>', $second->getBody());
        $this->assertSame('HIT', $second->getHeaderLine('X-Pramnos-Cache'));
    }

    /**
     * A pipeline that does not return a Response is left alone.
     *
     * Plenty of framework actions echo their output and return a string, an
     * array, or nothing. There is no reliable body to store in those cases, and
     * guessing at one caches half a page.
     */
    public function testANonResponseResultIsPassedThroughAndNotStored(): void
    {
        // Arrange
        $middleware = $this->middleware();
        $ran = 0;
        $next = function () use (&$ran): string {
            $ran++;
            return 'a plain string';
        };

        // Act
        $first  = $middleware->handle($this->request(), $next);
        $second = $middleware->handle($this->request(), $next);

        // Assert — passed through unchanged …
        $this->assertSame('a plain string', $first);
        // … and nothing was cached, so the pipeline ran again.
        $this->assertSame(2, $ran);
    }

    /**
     * The runtime state is cleared at the start of every request.
     *
     * Without this, one page calling `PageCache::bypass()` would suppress
     * caching for every request the process served afterwards — a cache that
     * quietly switches itself off under load and works perfectly in testing.
     *
     * The reversal that reddens this: delete the `resetRuntime()` call from
     * `handle()`.
     */
    public function testTheRuntimeStateDoesNotLeakBetweenRequests(): void
    {
        // Arrange — a previous request bypassed.
        $middleware = $this->middleware();
        PageCache::bypass('a previous request');

        // Act — a new request arrives and renders normally.
        $middleware->handle(
            $this->request('/stations'),
            static fn(): Response => Response::make('<html>fresh</html>')
        );

        // Assert — it was stored, so the stale bypass did not apply …
        $this->assertFalse(PageCache::isBypassed());
        $ran = 0;
        $result = $middleware->handle($this->request('/stations'), function () use (&$ran) {
            $ran++;
            return Response::make('should not run');
        });
        $this->assertSame(0, $ran);
        $this->assertSame('<html>fresh</html>', $result->getBody());
    }

    /**
     * Built with no arguments — as the pipeline builds it — it reads the
     * application's `pagecache` block and caches nothing without one.
     *
     * This is the production path, and the assertion is the safe default: a
     * project that adds the middleware to its pipeline and writes no
     * configuration gets a working site rather than a randomly shared one. An
     * `enabled`-by-default would make this test the last thing standing between
     * a deploy and somebody else's account page.
     */
    public function testWithNoConfigurationItCachesNothing(): void
    {
        // Arrange — no injected engine, so it builds its own from settings.
        $middleware = new PageCacheMiddleware();
        $ran = 0;
        $next = function () use (&$ran): Response {
            $ran++;
            return Response::make('<html>rendered</html>');
        };

        // Act
        $middleware->handle($this->request(), $next);
        $middleware->handle($this->request(), $next);

        // Assert — the pipeline ran both times; nothing was served from cache.
        $this->assertSame(2, $ran);
    }

    /**
     * A controller that bypasses mid-render is honoured by the middleware.
     *
     * This is the end-to-end version of the engine's own bypass test: the
     * decision is made inside `$next`, after `lookup()` has already run.
     */
    public function testAControllerCanRefuseToBeCachedMidRender(): void
    {
        // Arrange
        $middleware = $this->middleware();
        $next = static function (): Response {
            PageCache::bypass('personalised');
            return Response::make('<html>personal</html>');
        };

        // Act
        $middleware->handle($this->request(), $next);

        // Assert — nothing was stored, so the next request renders again.
        $ran = 0;
        $middleware->handle($this->request(), function () use (&$ran): Response {
            $ran++;
            return Response::make('<html>personal</html>');
        });
        $this->assertSame(1, $ran);
    }
}
