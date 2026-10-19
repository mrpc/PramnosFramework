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
#[CoversClass(PageCache::class)]
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
        PageCache::useQueuedHeaders(null);
        Request::resetInstance();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_GET    = $this->get;
        $_COOKIE = $this->cookie;

        PageCache::resetRuntime();
        PageCache::useQueuedHeaders(null);
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
     * The miss that fills the cache goes out with the configured `cacheControl`, as every hit
     * of it will — the first visitor's page was `no-store`, so no bfcache and no revalidation;
     * a page that was not stored keeps its own headers, and with nothing configured nothing is
     * added.
     */
    public function testAStoredMissCarriesTheCacheControlToo(): void
    {
        // Arrange
        $bfcache = $this->middleware(['cacheControl' => 'private, no-cache']);
        $plain   = $this->middleware();
        $page    = static fn (): Response => Response::make('<html>public page</html>');
        $private = static fn (): Response => Response::make('<html>yours</html>')->withHeader('Set-Cookie', 'a=b');

        // Act
        $miss       = $bfcache->handle($this->request('/a'), $page);
        $hit        = $bfcache->handle($this->request('/a'), $page);
        $notStored  = $bfcache->handle($this->request('/b'), $private);
        $unset      = $plain->handle($this->request('/c'), $page);

        // Assert
        $this->assertSame('private, no-cache', $miss->getHeaderLine('Cache-Control'), 'the miss, as it was stored');
        $this->assertSame('private, no-cache', $hit->getHeaderLine('Cache-Control'));
        $this->assertSame('', (string) $notStored->getHeaderLine('Cache-Control'), 'not stored: its own headers');
        $this->assertSame('', (string) $unset->getHeaderLine('Cache-Control'), 'nothing configured: nothing added');
    }

    /**
     * A cookie queued with setcookie() — which the Response never sees — means the page answered
     * one visitor: it is not stored, nor given the shared Cache-Control. The framework's own
     * visitor tracking, set on every request, does not count, by its name before or after the
     * framework's encoding, nor do the cookies an application names harmless; the session cookie
     * does.
     */
    public function testACookieQueuedByPhpIsNotStored(): void
    {
        // Arrange
        $prefix = substr(md5('pcms'), 0, 10);
        $cache  = $this->middleware(['cacheControl' => 'private, no-cache', 'harmlessCookies' => ['lastseen', 'visitorid', 'theme']]);
        $ran    = 0;
        $page   = function () use (&$ran): Response {
            $ran++;

            return Response::make('<html>p</html>');
        };

        // Act — tracking cookies only, then an app's harmless one, then the session, then a tracker of its own
        PageCache::useQueuedHeaders(['Set-Cookie: ' . urlencode($prefix . '[' . str_rot13('lastseen') . ']') . '=1700000000; path=/', 'Set-Cookie: visitorid=abc', 'Set-Cookie: theme=dark']);
        $tracked = $cache->handle($this->request('/t'), $page);
        PageCache::useQueuedHeaders([]);
        $cache->handle($this->request('/t'), $page);
        $afterTracked = $ran;
        PageCache::useQueuedHeaders(['Set-Cookie: PHPSESSID=new; path=/']);
        $session = $cache->handle($this->request('/s'), $page);
        PageCache::useQueuedHeaders([]);
        $cache->handle($this->request('/s'), $page);
        $afterSession = $ran;

        // Assert
        $this->assertSame('private, no-cache', $tracked->getHeaderLine('Cache-Control'), 'tracking cookies: stored, and said so');
        $this->assertSame(1, $afterTracked, 'the second came from the cache');
        $this->assertSame('', (string) $session->getHeaderLine('Cache-Control'), 'a session cookie: not stored');
        $this->assertSame(3, $afterSession, 'so the next request ran the page again');
    }

    /**
     * A page that prints who its visitor is is not stored: the visitor id it just minted (the
     * tracking cookie this response sets), or the one the session keeps. Stored, every later
     * visitor would report into that one visit — very often a bot's first. The same page
     * printing nothing about the visitor, with the same tracking cookie, is stored.
     */
    public function testAPagePrintingTheVisitorsIdIsNotStored(): void
    {
        // Arrange
        $session = $_SESSION ?? null;
        $cache   = $this->middleware();
        $ran     = 0;
        $printed = '';
        $page    = function () use (&$ran, &$printed): Response {
            $ran++;

            return Response::make('<html><script>track("' . $printed . '")</script></html>');
        };

        try {
            // Act — a new visitor, its id printed
            unset($_SESSION['visitorid']);
            $printed = '0123456789abcdef';
            PageCache::useQueuedHeaders(['Content-Language: el', 'Set-Cookie: visitorid=0123456789abcdef; path=/']);
            $cache->handle($this->request('/minted'), $page);
            $cache->handle($this->request('/minted'), $page);
            $afterMinted = $ran;
            // A returning visitor, its session's id printed
            $_SESSION['visitorid'] = 'fedcba9876543210';
            $printed = 'fedcba9876543210';
            PageCache::useQueuedHeaders([]);
            $cache->handle($this->request('/known'), $page);
            $cache->handle($this->request('/known'), $page);
            $afterKnown = $ran;
            // Nothing about the visitor in the page
            $printed = 'pageview';
            PageCache::useQueuedHeaders(['Set-Cookie: visitorid=0123456789abcdef; path=/']);
            $cache->handle($this->request('/plain'), $page);
            $cache->handle($this->request('/plain'), $page);
        } finally {
            if ($session === null) {
                unset($_SESSION['visitorid']);
            } else {
                $_SESSION = $session;
            }
        }

        // Assert
        $this->assertSame(2, $afterMinted, 'the page with the id it minted was stored');
        $this->assertSame(4, $afterKnown, 'the page with the session\'s visitor id was stored');
        $this->assertSame(5, $ran, 'a page printing nothing about the visitor was not stored');
    }

    /**
     * A page that prints the time is stored, though the `lastseen` cookie this response sets holds
     * the same `time()`: a timestamp identifies nobody, and looking for it refused every such
     * page — a script's clock, a cache-buster — to every visitor without cookies.
     */
    public function testAPagePrintingTheTimeIsStored(): void
    {
        // Arrange
        $prefix = substr(md5('pcms'), 0, 10);
        $now    = (string) time();
        $cache  = $this->middleware();
        $ran    = 0;
        $page   = function () use (&$ran, $now): Response {
            $ran++;

            return Response::make('<html><script>var now = ' . $now . ';</script></html>');
        };

        // Act
        PageCache::useQueuedHeaders(['Set-Cookie: ' . urlencode($prefix . '[' . str_rot13('lastseen') . ']') . '=' . $now . '; path=/']);
        $cache->handle($this->request('/clock'), $page);
        PageCache::useQueuedHeaders([]);
        $cache->handle($this->request('/clock'), $page);

        // Assert
        $this->assertSame(1, $ran, 'the page printing the time was not stored');
    }

    /**
     * The framework's own cookies arrive as an array under its prefix, by an encoded name; the
     * remember-me `logged` among them is still found, so a remembered visitor without a session is
     * not served the anonymous page. An array cookie of an application's own is matched as
     * `name[key]`; the framework's visitor tracking does not bypass anything.
     */
    public function testArrayStyleCookiesAreMatched(): void
    {
        // Arrange
        $prefix = substr(md5('pcms'), 0, 10);
        $cache  = new PageCache(['enabled' => true, 'bypassCookies' => ['#^(auth|remember|logged)#i', '#^prefs\\[remember\\]$#']], new ArrayAdapter());
        $request = $this->request('/x');

        // Act
        $_COOKIE = [$prefix => [str_rot13('logged') => '1']];
        $remembered = $cache->whyBypassed($request);
        $_COOKIE = ['prefs' => ['remember' => '1']];
        $own = $cache->whyBypassed($request);
        $_COOKIE = [$prefix => [str_rot13('visitorid') => 'abc', str_rot13('lastseen') => '1']];
        $tracking = $cache->whyBypassed($request);

        // Assert
        $this->assertSame('cookie:logged', $remembered);
        $this->assertSame('cookie:prefs[remember]', $own);
        $this->assertNull($tracking);
    }

    /**
     * With cdnCacheControl, a hit tells a CDN to keep it for what is left of the entry's life —
     * as CDN-Cache-Control, or a header of the application's choosing; a miss never says it, and
     * by default nothing is said.
     */
    public function testAHitTellsTheCdn(): void
    {
        // Arrange
        $page = static fn (): Response => Response::make('<html>cdn</html>');
        $on   = $this->middleware(['cdnCacheControl' => true, 'ttl' => 600]);
        $cf   = $this->middleware(['cdnCacheControl' => 'Cloudflare-CDN-Cache-Control', 'ttl' => 300]);
        $off  = $this->middleware();

        // Act
        $miss = $on->handle($this->request('/c'), $page);
        $hit  = $on->handle($this->request('/c'), $page);
        $cf->handle($this->request('/f'), $page);
        $cfHit = $cf->handle($this->request('/f'), $page);
        $off->handle($this->request('/o'), $page);
        $offHit = $off->handle($this->request('/o'), $page);

        // Assert
        $this->assertSame('', (string) $miss->getHeaderLine('CDN-Cache-Control'), 'not on a miss');
        $this->assertMatchesRegularExpression('/^max-age=(600|599)$/', $hit->getHeaderLine('CDN-Cache-Control'));
        $this->assertMatchesRegularExpression('/^max-age=(300|299)$/', $cfHit->getHeaderLine('Cloudflare-CDN-Cache-Control'));
        $this->assertSame('', (string) $offHit->getHeaderLine('CDN-Cache-Control'), 'off by default');
    }

    /**
     * With pageSignal, a page a service worker may keep says so — `public; max-age` on the miss
     * just stored and on a hit, with what is left of its life — and a private one says it is
     * private: bypassed at runtime, or carrying a private marker; a page refused for any other
     * reason says nothing, and without pageSignal nothing is said.
     */
    public function testThePageSignal(): void
    {
        // Arrange
        $signal  = $this->middleware(['pageSignal' => true, 'ttl' => 600, 'privateMarkers' => ['data-mine']]);
        $page    = static fn (): Response => Response::make('<html>public</html>');
        $mine    = static fn (): Response => Response::make('<html data-mine>yours</html>');
        $bypass  = static function (): Response {
            PageCache::bypass('a basket');

            return Response::make('<html>basket</html>');
        };
        $missing = static fn (): Response => Response::make('<html>gone</html>', 404);

        // Act
        $miss    = $signal->handle($this->request('/p'), $page);
        $hit     = $signal->handle($this->request('/p'), $page);
        $marked  = $signal->handle($this->request('/m'), $mine);
        $runtime = $signal->handle($this->request('/b'), $bypass);
        $notFound = $signal->handle($this->request('/n'), $missing);
        $quiet   = $this->middleware()->handle($this->request('/q'), $page);

        // Assert
        $this->assertSame('public; max-age=600', $miss->getHeaderLine('Pramnos-Page'));
        $this->assertMatchesRegularExpression('/^public; max-age=(600|599)$/', $hit->getHeaderLine('Pramnos-Page'));
        $this->assertSame('private', $marked->getHeaderLine('Pramnos-Page'));
        $this->assertSame('private', $runtime->getHeaderLine('Pramnos-Page'));
        $this->assertSame('', (string) $notFound->getHeaderLine('Pramnos-Page'), 'not stored, and not private: nothing said');
        $this->assertSame('', (string) $quiet->getHeaderLine('Pramnos-Page'), 'off by default');
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
