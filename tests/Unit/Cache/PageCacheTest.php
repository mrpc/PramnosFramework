<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\Adapter\ArrayAdapter;
use Pramnos\Cache\Page\PageCache;
use Pramnos\Http\Request;
use Pramnos\Http\Response;

/**
 * The full-page cache, and the several ways one can be quietly wrong.
 *
 * A page cache has an unusual risk profile: when it works nobody notices, and
 * when it fails it serves one visitor's page to another. So the tests here lean
 * hard on the refusals — the cases where the right answer is *not* to cache —
 * because those are the ones that fail silently and stay failed.
 *
 * The store is an {@see ArrayAdapter} throughout, which is not a limitation: the
 * class talks to {@see \Pramnos\Cache\FlatCache}, every adapter implements the
 * same contract, and the one place the backend genuinely matters — whether its
 * counter is atomic — is tested against both answers explicitly.
 */
#[CoversClass(PageCache::class)]
class PageCacheTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $server = [];
    /** @var array<string,mixed> */
    private array $get = [];
    /** @var array<string,mixed> */
    private array $cookie = [];

    private string $staticRoot = '';

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $this->get    = $_GET;
        $this->cookie = $_COOKIE;

        $_GET    = [];
        $_COOKIE = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_HOST']      = 'example.test';
        $_SERVER['PHP_SELF']       = '/index.php';
        unset(
            $_SERVER['HTTP_IF_NONE_MATCH'], $_SERVER['HTTP_ACCEPT_ENCODING'],
            $_SERVER['HTTP_USER_AGENT'], $_SERVER['HTTPS'],
            $_SERVER['HTTP_AUTHORIZATION']
        );

        PageCache::resetRuntime();
        Request::resetInstance();

        $this->staticRoot = sys_get_temp_dir() . '/pagecache-static-' . getmypid();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_GET    = $this->get;
        $_COOKIE = $this->cookie;

        PageCache::resetRuntime();
        Request::resetInstance();

        $this->removeTree($this->staticRoot);
        $this->removeTree(sys_get_temp_dir() . '/pramnos_cache/pagecache-locks');
    }

    /**
     * A cache over a fresh in-memory store, enabled by default because a
     * disabled one is the subject of exactly one test.
     *
     * @param array<string,mixed> $config
     */
    private function cache(array $config = [], ?ArrayAdapter $adapter = null): PageCache
    {
        return new PageCache(
            array_merge(['enabled' => true], $config),
            $adapter ?? new ArrayAdapter()
        );
    }

    /** A GET request for a path, with an optional query string. */
    private function request(string $uri = '/stations', string $method = 'GET'): Request
    {
        $_SERVER['REQUEST_URI']    = $uri;
        $_SERVER['REQUEST_METHOD'] = $method;

        $at = strpos($uri, '?');
        if ($at !== false) {
            parse_str(substr($uri, $at + 1), $parsed);
            $_GET = array_merge($_GET, $parsed);
        }

        Request::resetInstance();

        return new Request();
    }

    private function page(string $body = '<html>stations</html>', int $status = 200): Response
    {
        return Response::make($body, $status);
    }

    // ── Round trip ──────────────────────────────────────────────────────────

    /**
     * The whole point, in one test: a stored page comes back.
     *
     * Asserts the body *and* the status, because a cache that returns the right
     * bytes under the wrong status is a 200 page served as a 404 to a crawler.
     */
    public function testAStoredPageIsServedBack(): void
    {
        // Arrange
        $cache   = $this->cache();
        $request = $this->request('/stations');

        // Act
        $this->assertNull($cache->lookup($request), 'nothing is cached yet');
        $this->assertTrue($cache->store($request, $this->page()));
        $hit = $cache->lookup($this->request('/stations'));

        // Assert
        $this->assertInstanceOf(Response::class, $hit);
        $this->assertSame('<html>stations</html>', $hit->getBody());
        $this->assertSame(200, $hit->getStatusCode());
        $this->assertSame('HIT', $hit->getHeaderLine('X-Pramnos-Cache'));
    }

    /**
     * A different path is a different page.
     *
     * Trivial-looking, and it is the assertion that fails first if the key ever
     * stops including the path — at which point every page on the site serves
     * whichever one was rendered first.
     */
    public function testADifferentPathIsADifferentEntry(): void
    {
        // Arrange
        $cache = $this->cache();
        $cache->store($this->request('/stations'), $this->page('A'));

        // Act
        $hit = $cache->lookup($this->request('/artists'));

        // Assert
        $this->assertNull($hit);
    }

    // ── Key normalisation (§6.1) ────────────────────────────────────────────

    /**
     * Query parameters in a different order are the same page.
     *
     * The implementation this replaces keyed on the raw query string, so
     * `?a=1&b=2` and `?b=2&a=1` were two entries for one page — a hit rate
     * halved by nothing but link ordering.
     *
     * The reversal that reddens this: drop the `ksort()` in
     * `normalisedQuery()`.
     */
    public function testQueryParameterOrderDoesNotChangeTheKey(): void
    {
        // Arrange
        $cache = $this->cache();
        $cache->store($this->request('/search?a=1&b=2'), $this->page('result'));

        // Act — same parameters, opposite order.
        $_GET = [];
        $hit = $cache->lookup($this->request('/search?b=2&a=1'));

        // Assert
        $this->assertNotNull($hit);
        $this->assertSame('result', $hit->getBody());
    }

    /**
     * Tracking parameters do not fragment the cache.
     *
     * Every campaign link carries a different `utm_*`, so keying on them gives
     * advertising traffic — the traffic a page cache exists for — a permanent
     * 0% hit rate, and lets anyone fill the store by appending junk.
     *
     * @return array<string,array{string}>
     */
    public static function ignoredQueryProvider(): array
    {
        return [
            'utm_source' => ['/stations?utm_source=newsletter'],
            'utm_medium' => ['/stations?utm_medium=email&utm_campaign=x'],
            'fbclid'     => ['/stations?fbclid=abc123'],
            'gclid'      => ['/stations?gclid=xyz'],
            'ref'        => ['/stations?ref=partner'],
        ];
    }

    #[DataProvider('ignoredQueryProvider')]
    public function testTrackingParametersDoNotFragmentTheCache(string $uri): void
    {
        // Arrange — the clean URL is cached.
        $cache = $this->cache();
        $cache->store($this->request('/stations'), $this->page('clean'));

        // Act — the campaign URL arrives.
        $_GET = [];
        $hit = $cache->lookup($this->request($uri));

        // Assert — it is the same page.
        $this->assertNotNull($hit, $uri . ' should hit the entry for /stations');
        $this->assertSame('clean', $hit->getBody());
    }

    /**
     * A parameter that changes the page still changes the key.
     *
     * The other half of the previous test, and the one that keeps the
     * ignore-list honest: if it ever grew to swallow a real parameter, page 2
     * of a listing would serve page 1.
     */
    public function testAMeaningfulParameterStillChangesTheKey(): void
    {
        // Arrange
        $cache = $this->cache();
        $cache->store($this->request('/stations?page=1'), $this->page('one'));

        // Act
        $_GET = [];
        $hit = $cache->lookup($this->request('/stations?page=2'));

        // Assert
        $this->assertNull($hit);
    }

    /**
     * `varyQuery` is a whitelist, and it wins over the ignore list.
     *
     * An application that knows exactly which parameters matter should be able
     * to say so, rather than enumerating the infinite set that does not.
     */
    public function testVaryQueryKeepsOnlyTheNamedParameters(): void
    {
        // Arrange
        $cache = $this->cache(['varyQuery' => ['page']]);
        $cache->store($this->request('/stations?page=1'), $this->page('one'));

        // Act — an unlisted parameter is dropped entirely …
        $_GET = [];
        $hit = $cache->lookup($this->request('/stations?page=1&anything=else'));

        // Assert
        $this->assertNotNull($hit);

        // … and a listed one still separates entries.
        $_GET = [];
        $this->assertNull($cache->lookup($this->request('/stations?page=9')));
    }

    /**
     * The front controller's routing parameter is not part of the page.
     *
     * `r` is how the framework's own front controller carries the route when
     * there is no rewrite. Keying on it would give the rewritten and
     * non-rewritten spellings of one URL two entries.
     */
    public function testTheRoutingParameterIsExcludedFromTheKey(): void
    {
        // Arrange
        $cache = $this->cache();
        $cache->store($this->request('/stations'), $this->page('body'));

        // Act
        $_GET = ['r' => 'stations'];
        $hit  = $cache->lookup($this->request('/stations'));

        // Assert
        $this->assertNotNull($hit);
    }

    /**
     * HEAD shares GET's entry and gets an empty body.
     *
     * It is the same page with the body withheld, so a separate entry would
     * double the storage to hold identical bytes — while sending a body on a
     * HEAD, which is a protocol violation, is the failure in the other
     * direction.
     */
    public function testHeadSharesTheGetEntryAndReturnsNoBody(): void
    {
        // Arrange
        $cache = $this->cache();
        $cache->store($this->request('/stations'), $this->page('<html>x</html>'));

        // Act
        $hit = $cache->lookup($this->request('/stations', 'HEAD'));

        // Assert
        $this->assertNotNull($hit, 'HEAD must reach the GET entry');
        $this->assertSame('', $hit->getBody());
    }

    /**
     * `varyBy` splits the key, and its resolver receives the request.
     *
     * This is how a site with a per-country or per-currency view keeps separate
     * entries without teaching the cache what a country is.
     */
    public function testVaryByProducesSeparateEntries(): void
    {
        // Arrange
        $country = 'GR';
        $cache = $this->cache([
            'varyBy' => ['country' => static function () use (&$country) {
                return $country;
            }],
        ]);
        $cache->store($this->request('/stations'), $this->page('greek'));

        // Act — same URL, different vary value.
        $country = 'UK';
        $miss = $cache->lookup($this->request('/stations'));

        // Assert
        $this->assertNull($miss, 'a different vary value is a different entry');

        // … and going back returns the original.
        $country = 'GR';
        $this->assertSame('greek', $cache->lookup($this->request('/stations'))?->getBody());
    }

    // ── The decision matrix (§6.2) ──────────────────────────────────────────

    /**
     * Disabled is the default, and it caches nothing.
     *
     * The single most important default in the class. A project that registers
     * the middleware and writes no config must get a working site, not a
     * randomly shared one.
     */
    public function testTheDefaultConfigurationCachesNothing(): void
    {
        // Arrange — note: no 'enabled' => true.
        $cache   = new PageCache([], new ArrayAdapter());
        $request = $this->request('/stations');

        // Act & Assert
        $this->assertFalse($cache->store($request, $this->page()));
        $this->assertNull($cache->lookup($request));
        $this->assertSame('disabled', $cache->whyBypassed($request));
    }

    /**
     * Only safe methods are cached.
     *
     * A cached POST would serve the result of one visitor's form submission to
     * the next visitor who submits the form.
     *
     * @return array<string,array{string,bool}>
     */
    public static function methodProvider(): array
    {
        return [
            'GET'    => ['GET', true],
            'HEAD'   => ['HEAD', true],
            'POST'   => ['POST', false],
            'PUT'    => ['PUT', false],
            'DELETE' => ['DELETE', false],
            'PATCH'  => ['PATCH', false],
        ];
    }

    #[DataProvider('methodProvider')]
    public function testOnlySafeMethodsAreCached(string $method, bool $cacheable): void
    {
        // Arrange
        $cache   = $this->cache();
        $request = $this->request('/stations', $method);

        // Act
        $stored = $cache->store($request, $this->page());

        // Assert
        $this->assertSame($cacheable, $stored);
    }

    /**
     * A visitor who presents an authentication cookie is never served a cached
     * page — and their page is never stored.
     *
     * This is the failure everybody fears from a page cache, and the reason the
     * default `bypassCookies` pattern is broad rather than exact: it is better
     * to miss on a cookie named `remember_theme` than to hit on one named
     * `authtoken` that the list did not anticipate.
     *
     * @return array<string,array{string}>
     */
    public static function authCookieProvider(): array
    {
        return [
            'auth'        => ['auth'],
            'authtoken'   => ['authtoken'],
            'AUTH_TOKEN'  => ['AUTH_TOKEN'],
            'remember_me' => ['remember_me'],
            'logged_in'   => ['logged_in'],
        ];
    }

    #[DataProvider('authCookieProvider')]
    public function testAnAuthenticatedVisitorIsNeitherServedNorStored(string $cookie): void
    {
        // Arrange — a page is already cached for anonymous visitors.
        $cache = $this->cache();
        $cache->store($this->request('/stations'), $this->page('anonymous'));

        // Act — a logged-in visitor arrives.
        $_COOKIE[$cookie] = 'whatever';
        $request = $this->request('/stations');

        // Assert — served nothing …
        $this->assertNull($cache->lookup($request));
        // … and their own page is not stored.
        $this->assertFalse($cache->store($request, $this->page('private')));
        $this->assertSame('cookie:' . $cookie, $cache->whyBypassed($request));
    }

    /**
     * A harmless cookie does not disable the cache.
     *
     * The complement of the test above. A consent banner or an analytics cookie
     * is on almost every request; if those bypassed, the cache would be off in
     * production and on in testing, which is the worst of both.
     */
    public function testAnUnrelatedCookieDoesNotBypass(): void
    {
        // Arrange
        $cache = $this->cache();
        $cache->store($this->request('/stations'), $this->page('anonymous'));

        // Act
        $_COOKIE['cookie_consent'] = 'accepted';
        $_COOKIE['_ga']            = 'GA1.2.3';

        // Assert
        $this->assertNotNull($cache->lookup($this->request('/stations')));
    }

    /**
     * An `Authorization` header bypasses, cookie or no cookie.
     *
     * An API client authenticates with a header and never sends a cookie, so
     * the cookie rules alone would happily serve one token holder's response to
     * another's.
     */
    public function testAnAuthorizationHeaderBypasses(): void
    {
        // Arrange
        $cache = $this->cache();
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer abc';
        $request = $this->request('/stations');

        // Act & Assert
        $this->assertSame('header:Authorization', $cache->whyBypassed($request));
        $this->assertFalse($cache->store($request, $this->page()));
    }

    /**
     * `bypassPaths` accepts both spellings a human writes.
     *
     * Configuration is written by people who think in globs and people who
     * think in regexes; supporting one and silently matching nothing for the
     * other is how a bypass rule ends up not bypassing.
     *
     * @return array<string,array{string,string}>
     */
    public static function bypassPathProvider(): array
    {
        return [
            'a glob'       => ['/api/*', '/api/stations'],
            'a regex'      => ['#^/admin#', '/admin/dashboard'],
            'an exact path' => ['/checkout', '/checkout'],
            'a deep glob'  => ['/webhooks/*', '/webhooks/stripe'],
        ];
    }

    #[DataProvider('bypassPathProvider')]
    public function testBypassPathsAcceptGlobsAndRegexes(string $pattern, string $path): void
    {
        // Arrange
        $cache   = $this->cache(['bypassPaths' => [$pattern]]);
        $request = $this->request($path);

        // Act & Assert
        $this->assertSame('bypassPaths', $cache->whyBypassed($request));
    }

    /**
     * `onlyPaths` inverts the rule: everything outside it is uncacheable.
     *
     * The safest way to switch a page cache on for a large existing site — name
     * the pages you are sure about instead of enumerating the ones you are not.
     */
    public function testOnlyPathsExcludesEverythingElse(): void
    {
        // Arrange
        $cache = $this->cache(['onlyPaths' => ['/stations*']]);

        // Act & Assert
        $this->assertNull($cache->whyBypassed($this->request('/stations/7')));
        $this->assertSame(
            'not-in-onlyPaths', $cache->whyBypassed($this->request('/artists'))
        );
    }

    /**
     * `bypassQuery` can name the values that matter, or any value at all.
     */
    public function testBypassQueryMatchesByNameOrByValue(): void
    {
        // Arrange
        $cache = $this->cache([
            'bypassQuery' => ['preview' => true, 'mode' => ['debug']],
        ]);

        // Act & Assert — any value for `preview` …
        $_GET = ['preview' => '1'];
        $this->assertSame('bypassQuery:preview', $cache->whyBypassed($this->request('/stations')));

        // … only the named value for `mode` …
        $_GET = ['mode' => 'debug'];
        $this->assertSame('bypassQuery:mode', $cache->whyBypassed($this->request('/stations')));

        // … and an unlisted value does not bypass.
        $_GET = ['mode' => 'normal'];
        $this->assertNull($cache->whyBypassed($this->request('/stations')));
    }

    /**
     * `hosts` restricts the cache to the names it was configured for.
     */
    public function testAnUnlistedHostIsNotCached(): void
    {
        // Arrange
        $cache = $this->cache(['hosts' => ['example.test']]);

        // Act & Assert
        $this->assertNull($cache->whyBypassed($this->request('/stations')));

        $_SERVER['HTTP_HOST'] = 'staging.example.test';
        $this->assertSame(
            'host:staging.example.test', $cache->whyBypassed($this->request('/stations'))
        );
    }

    // ── What must never be stored ───────────────────────────────────────────

    /**
     * A response carrying `Set-Cookie` is never stored.
     *
     * The single worst thing a page cache can do: replay one visitor's session
     * cookie to the next. Refused outright rather than filtered out of the
     * headers, because a response that is *setting* a session is per-visitor in
     * its body too.
     *
     * The reversal that reddens this: delete the `hasHeader('Set-Cookie')`
     * guard in `store()`.
     */
    public function testAResponseThatSetsACookieIsNeverStored(): void
    {
        // Arrange
        $cache    = $this->cache();
        $request  = $this->request('/stations');
        $response = $this->page('welcome back')
            ->withHeader('Set-Cookie', 'PHPSESSID=abc123; Path=/');

        // Act
        $stored = $cache->store($request, $response);

        // Assert
        $this->assertFalse($stored);
        $this->assertNull($cache->lookup($this->request('/stations')));
    }

    /**
     * Only whitelisted statuses are stored.
     *
     * A cached 500 outlives the incident that caused it, and a cached 302
     * pins every visitor to one visitor's redirect target.
     *
     * @return array<string,array{int,bool}>
     */
    public static function statusProvider(): array
    {
        return [
            '200 OK'        => [200, true],
            '301 moved'     => [301, false],
            '302 found'     => [302, false],
            '404 not found' => [404, false],
            '500 error'     => [500, false],
        ];
    }

    #[DataProvider('statusProvider')]
    public function testOnlyWhitelistedStatusesAreStored(int $status, bool $stored): void
    {
        // Arrange
        $cache = $this->cache();

        // Act & Assert
        $this->assertSame(
            $stored,
            $cache->store($this->request('/stations'), $this->page('body', $status))
        );
    }

    /**
     * A status can be added to the whitelist.
     *
     * Caching 404s is a real and deliberate choice on a site whose 404 page is
     * expensive — it just must not be the default.
     */
    public function testTheStatusWhitelistIsConfigurable(): void
    {
        // Arrange
        $cache = $this->cache(['statuses' => [200, 404]]);

        // Act & Assert
        $this->assertTrue(
            $cache->store($this->request('/nope'), $this->page('missing', 404))
        );
    }

    /**
     * An empty body is not a page.
     *
     * Storing one turns a render that failed halfway into a blank page served
     * for the whole TTL.
     */
    public function testAnEmptyBodyIsNotStored(): void
    {
        $cache = $this->cache();

        $this->assertFalse($cache->store($this->request('/stations'), $this->page('')));
    }

    /**
     * `privateMarkers` refuses a body that proves it is personalised.
     *
     * The belt to the cookie rules' braces: an application whose logged-in state
     * lives only in `$_SESSION` has no cookie for the decision to see, and this
     * catches the resulting page by what it contains.
     */
    public function testAPrivateMarkerInTheBodyPreventsStoring(): void
    {
        // Arrange
        $cache = $this->cache(['privateMarkers' => ['id="logout-link"']]);

        // Act
        $stored = $cache->store(
            $this->request('/stations'),
            $this->page('<a id="logout-link">Log out</a>')
        );

        // Assert
        $this->assertFalse($stored);
    }

    /**
     * Only response headers on the whitelist are replayed.
     *
     * A whitelist rather than a blacklist, because the header that must not be
     * replayed is always the one nobody thought of. `Content-Type` survives
     * because a cached page served as `text/html` when it was JSON is broken.
     */
    public function testOnlyWhitelistedHeadersAreReplayed(): void
    {
        // Arrange
        $cache    = $this->cache();
        $response = $this->page('{"ok":true}')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('X-Request-Id', 'per-request-value')
            ->withHeader('X-Powered-By', 'something');

        // Act
        $cache->store($this->request('/api-ish'), $response);
        $hit = $cache->lookup($this->request('/api-ish'));

        // Assert
        $this->assertSame('application/json', $hit->getHeaderLine('Content-Type'));
        $this->assertFalse(
            $hit->hasHeader('X-Request-Id'),
            'a per-request header must not be replayed to another visitor'
        );
        $this->assertFalse($hit->hasHeader('X-Powered-By'));
    }

    // ── Runtime bypass and tags (§6.4) ──────────────────────────────────────

    /**
     * `PageCache::bypass()` stops the page being stored, from anywhere.
     *
     * Called by a controller that has just discovered the page is personal.
     * Static because the caller has no reference to this object — the same
     * reason WordPress uses a constant for it.
     */
    public function testARuntimeBypassPreventsStoring(): void
    {
        // Arrange
        $cache = $this->cache();

        // Act — as a controller would, mid-render.
        PageCache::bypass('cart has items');
        $stored = $cache->store($this->request('/stations'), $this->page());

        // Assert
        $this->assertFalse($stored);
        $this->assertTrue(PageCache::isBypassed());
        $this->assertSame('cart has items', PageCache::bypassReason());
    }

    /**
     * The runtime state does not leak into the next request.
     *
     * A process serves many requests — a worker, a test run. Without the reset
     * the first page to call `bypass()` would suppress caching for everything
     * after it, which is a cache that switches itself off in production and
     * cannot be reproduced anywhere else.
     */
    public function testTheRuntimeStateIsClearedBetweenRequests(): void
    {
        // Arrange
        PageCache::bypass('previous request');
        PageCache::tag('previous:tag');

        // Act
        PageCache::resetRuntime();

        // Assert
        $this->assertFalse(PageCache::isBypassed());
        $this->assertSame([], PageCache::tags());
    }

    /** Tags are collected, de-duplicated, and empty ones ignored. */
    public function testTagsAreCollectedAndDeduplicated(): void
    {
        // Act
        PageCache::tag('station:7', 'homepage');
        PageCache::tag('station:7', '', '  ');

        // Assert
        $this->assertSame(['station:7', 'homepage'], PageCache::tags());
    }

    // ── Purging (§6.7) ──────────────────────────────────────────────────────

    /**
     * A tag purge removes every page carrying it, and nothing else.
     *
     * Without this the only invalidation is the TTL — "the edit appears within
     * an hour" — which is why full-page caches get switched off after the first
     * urgent correction.
     */
    public function testPurgingATagRemovesExactlyThePagesCarryingIt(): void
    {
        // Arrange — two pages share a tag, a third does not.
        $cache = $this->cache();

        PageCache::resetRuntime();
        PageCache::tag('station:7');
        $cache->store($this->request('/stations/7'), $this->page('seven'));

        PageCache::resetRuntime();
        PageCache::tag('station:7');
        $cache->store($this->request('/stations/7/schedule'), $this->page('schedule'));

        PageCache::resetRuntime();
        PageCache::tag('station:9');
        $cache->store($this->request('/stations/9'), $this->page('nine'));

        // Act
        $removed = $cache->purgeTag('station:7');

        // Assert
        $this->assertSame(2, $removed);
        $this->assertNull($cache->lookup($this->request('/stations/7')));
        $this->assertNull($cache->lookup($this->request('/stations/7/schedule')));
        $this->assertNotNull(
            $cache->lookup($this->request('/stations/9')),
            'an unrelated tag must survive the purge'
        );
    }

    /**
     * Purging a URL removes **every** variant of it.
     *
     * One address can have several entries — one per `varyBy` value — and an
     * invalidation that cleared only the variant matching the purging request
     * would leave the others serving the old page to exactly the visitors who
     * see them. This is why the URL index exists at all.
     */
    public function testPurgingAUrlRemovesEveryVariant(): void
    {
        // Arrange — the same URL cached under two vary values.
        $country = 'GR';
        $cache = $this->cache([
            'varyBy' => ['country' => static function () use (&$country) {
                return $country;
            }],
        ]);

        $cache->store($this->request('/stations'), $this->page('greek'));
        $country = 'UK';
        $cache->store($this->request('/stations'), $this->page('british'));

        // Assert the premise: they really are two entries.
        $this->assertSame('british', $cache->lookup($this->request('/stations'))?->getBody());
        $country = 'GR';
        $this->assertSame('greek', $cache->lookup($this->request('/stations'))?->getBody());

        // Act
        $cache->purgeUrl('http://example.test/stations');

        // Assert — both gone.
        $this->assertNull($cache->lookup($this->request('/stations')));
        $country = 'UK';
        $this->assertNull($cache->lookup($this->request('/stations')));
    }

    /** A flush removes everything. */
    public function testFlushRemovesEverything(): void
    {
        // Arrange
        $cache = $this->cache();
        $cache->store($this->request('/stations'), $this->page('a'));
        $cache->store($this->request('/artists'), $this->page('b'));

        // Act
        $cache->flush();

        // Assert
        $this->assertNull($cache->lookup($this->request('/stations')));
        $this->assertNull($cache->lookup($this->request('/artists')));
    }

    /** Purging something that was never cached is not an error. */
    public function testPurgingAnUncachedUrlIsHarmless(): void
    {
        $cache = $this->cache();

        $this->assertTrue($cache->purgeUrl('http://example.test/never-cached'));
        $this->assertSame(0, $cache->purgeTag('no-such-tag'));
    }

    // ── TTL (§6.3) ──────────────────────────────────────────────────────────

    /** A path rule beats the global TTL, first match winning. */
    public function testPathRulesOverrideTheGlobalTtl(): void
    {
        // Arrange
        $cache = $this->cache([
            'ttl'      => 3600,
            'ttlRules' => ['/news*' => 60, '/stations*' => 7200],
        ]);

        // Act & Assert
        $this->assertSame(60, $cache->ttlFor($this->request('/news/today')));
        $this->assertSame(7200, $cache->ttlFor($this->request('/stations/7')));
        $this->assertSame(3600, $cache->ttlFor($this->request('/about')));
    }

    /**
     * A crawler can be given a longer TTL than a visitor.
     *
     * Crawlers are the traffic least harmed by a stale page and the traffic
     * most likely to request pages nobody else has asked for in hours, so they
     * are exactly who a longer TTL is for.
     */
    public function testACrawlerCanGetALongerTtl(): void
    {
        // Arrange
        $cache = $this->cache(['ttl' => 600, 'botTtl' => 86400]);

        // Act — a human …
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Macintosh) Safari/605';
        $human = $cache->ttlFor($this->request('/stations'));

        // … and a crawler.
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; Googlebot/2.1; '
            . '+http://www.google.com/bot.html)';
        $bot = $cache->ttlFor($this->request('/stations'));

        // Assert
        $this->assertSame(600, $human);
        $this->assertSame(86400, $bot);
    }

    /**
     * An expired entry outside the stale window is a miss.
     *
     * Driven by storing with a zero TTL and no stale window rather than by
     * sleeping, so the test costs nothing.
     */
    public function testAnExpiredEntryIsAMiss(): void
    {
        // Arrange
        $cache = $this->cache(['ttl' => 0, 'staleWhileRevalidate' => 0]);
        $cache->store($this->request('/stations'), $this->page('old'));

        // Act — created is `time()`, ttl is 0, so any elapsed second expires it.
        // Force the comparison by ageing the stored entry directly.
        $this->ageEntry($cache, '/stations', 10);
        $hit = $cache->lookup($this->request('/stations'));

        // Assert
        $this->assertNull($hit);
    }

    // ── Stale-while-revalidate and the lock (§6.5) ──────────────────────────

    /**
     * Inside the stale window, the second request is served the old page.
     *
     * The first request past expiry takes the lock and returns a miss, so it
     * renders. Everybody else gets the stale copy instead of piling onto the
     * same render — which is the entire point, and the reason the lock has to
     * actually be a lock.
     */
    public function testInsideTheStaleWindowOnlyTheFirstRequestRenders(): void
    {
        // Arrange — an entry that expired 5 seconds ago, with a 30s window.
        $cache = $this->cache(['ttl' => 60, 'staleWhileRevalidate' => 30]);
        $cache->store($this->request('/stations'), $this->page('old'));
        $this->ageEntry($cache, '/stations', 65);

        // Act
        $first  = $cache->lookup($this->request('/stations'));
        $second = $cache->lookup($this->request('/stations'));
        $third  = $cache->lookup($this->request('/stations'));

        // Assert — the first renders …
        $this->assertNull($first, 'the lock holder must render');
        // … the rest are served the old page rather than stampeding.
        $this->assertNotNull($second);
        $this->assertSame('old', $second->getBody());
        $this->assertSame('STALE', $second->getHeaderLine('X-Pramnos-Cache'));
        $this->assertNotNull($third);
    }

    /**
     * Past the stale window there is nothing left to serve.
     */
    public function testPastTheStaleWindowEverybodyMisses(): void
    {
        // Arrange
        $cache = $this->cache(['ttl' => 60, 'staleWhileRevalidate' => 30]);
        $cache->store($this->request('/stations'), $this->page('old'));
        $this->ageEntry($cache, '/stations', 200);

        // Act & Assert
        $this->assertNull($cache->lookup($this->request('/stations')));
        $this->assertNull($cache->lookup($this->request('/stations')));
    }

    /**
     * **The lock does not use the store's counter when the store cannot count
     * atomically** — which the default `file` store cannot.
     *
     * This is the spec bug this class was written around.
     * {@see \Pramnos\Cache\Cache::supportsAtomicCounter()} exists precisely
     * because the File and Array adapters implement `increment()` as a load
     * followed by a save: under concurrency every caller reads the same value
     * and every caller believes it won the lock. A stampede lock built on that
     * fails at the only moment it is for.
     *
     * With a non-atomic store the lock falls back to `mkdir()`, and this test
     * proves the exclusion holds anyway: three lookups against an
     * `ArrayAdapter` (`supportsAtomicCounter() === false`) yield exactly one
     * render.
     *
     * The reversal that reddens this: make `acquireLock()` call
     * `$this->flat()->increment()` unconditionally.
     */
    public function testTheLockHoldsOnAStoreWithoutAnAtomicCounter(): void
    {
        // Arrange — ArrayAdapter is deliberately not atomic.
        $adapter = new ArrayAdapter();
        $this->assertFalse(
            $adapter->supportsAtomicCounter(),
            'the premise: this store cannot count atomically'
        );

        $cache = $this->cache(
            ['ttl' => 60, 'staleWhileRevalidate' => 30], $adapter
        );
        $cache->store($this->request('/stations'), $this->page('old'));
        $this->ageEntry($cache, '/stations', 65);

        // Act — five concurrent-ish arrivals.
        $renders = 0;
        for ($i = 0; $i < 5; $i++) {
            if ($cache->lookup($this->request('/stations')) === null) {
                $renders++;
            }
        }

        // Assert — exactly one renders, four are served stale.
        $this->assertSame(1, $renders);
    }

    /**
     * The same exclusion on a store that *can* count atomically.
     *
     * The other branch of `acquireLock()`: `swap()` is Redis `GETSET`, and the
     * caller that gets `null` back is the one that took it.
     */
    public function testTheLockHoldsOnAStoreWithAnAtomicCounter(): void
    {
        // Arrange — an adapter that reports itself atomic, as Redis does.
        $adapter = new class extends ArrayAdapter {
            public function supportsAtomicCounter(): bool
            {
                return true;
            }
        };

        $cache = $this->cache(
            ['ttl' => 60, 'staleWhileRevalidate' => 30], $adapter
        );
        $cache->store($this->request('/stations'), $this->page('old'));
        $this->ageEntry($cache, '/stations', 65);

        // Act
        $renders = 0;
        for ($i = 0; $i < 5; $i++) {
            if ($cache->lookup($this->request('/stations')) === null) {
                $renders++;
            }
        }

        // Assert
        $this->assertSame(1, $renders);
    }

    /**
     * A lock whose holder died is not held for ever.
     *
     * A render that fatals mid-flight would otherwise wedge that page in the
     * stale state until the entry itself expired, serving an old copy that
     * nothing will ever refresh.
     */
    public function testAnAbandonedLockExpires(): void
    {
        // Arrange — a lock TTL of zero means a lock is never honoured, which is
        // the same code path a dead renderer's abandoned lock takes once its
        // TTL has passed.
        $cache = $this->cache([
            'ttl' => 60, 'staleWhileRevalidate' => 30, 'lockTtl' => 0,
        ]);
        $cache->store($this->request('/stations'), $this->page('old'));
        $this->ageEntry($cache, '/stations', 65);

        // Act
        $first  = $cache->lookup($this->request('/stations'));
        $second = $cache->lookup($this->request('/stations'));

        // Assert — both render, because the lock is always considered abandoned.
        $this->assertNull($first);
        $this->assertNull($second);
    }

    // ── ETag, 304 and gzip ──────────────────────────────────────────────────

    /**
     * A matching `If-None-Match` gets a 304 with no body.
     *
     * The cheapest possible hit: nothing rendered and nothing sent.
     */
    public function testAMatchingEtagIsAnsweredWith304(): void
    {
        // Arrange
        $cache = $this->cache();
        $cache->store($this->request('/stations'), $this->page('<html>x</html>'));
        $etag = $cache->lookup($this->request('/stations'))->getHeaderLine('ETag');
        $this->assertNotEmpty($etag);

        // Act
        $_SERVER['HTTP_IF_NONE_MATCH'] = $etag;
        $hit = $cache->lookup($this->request('/stations'));

        // Assert
        $this->assertSame(304, $hit->getStatusCode());
        $this->assertSame('', $hit->getBody());
    }

    /** A stale ETag gets the page, not a 304. */
    public function testANonMatchingEtagGetsTheFullPage(): void
    {
        // Arrange
        $cache = $this->cache();
        $cache->store($this->request('/stations'), $this->page('<html>x</html>'));

        // Act
        $_SERVER['HTTP_IF_NONE_MATCH'] = '"something-else"';
        $hit = $cache->lookup($this->request('/stations'));

        // Assert
        $this->assertSame(200, $hit->getStatusCode());
        $this->assertSame('<html>x</html>', $hit->getBody());
    }

    /**
     * A client that accepts gzip is served the pre-compressed copy.
     *
     * Compressing once at store time rather than on every hit is most of the
     * CPU a page cache saves after the render itself.
     */
    public function testAGzipCapableClientGetsThePrecompressedBody(): void
    {
        // Arrange
        $body  = str_repeat('<p>stations</p>', 50);
        $cache = $this->cache();
        $cache->store($this->request('/stations'), $this->page($body));

        // Act
        $_SERVER['HTTP_ACCEPT_ENCODING'] = 'gzip, deflate, br';
        $hit = $cache->lookup($this->request('/stations'));

        // Assert
        $this->assertSame('gzip', $hit->getHeaderLine('Content-Encoding'));
        $this->assertSame($body, gzdecode($hit->getBody()));
        $this->assertLessThan(strlen($body), strlen($hit->getBody()));
    }

    /** A client that does not accept gzip gets plain bytes. */
    public function testAClientWithoutGzipGetsThePlainBody(): void
    {
        // Arrange
        $cache = $this->cache();
        $cache->store($this->request('/stations'), $this->page('plain'));

        // Act — no Accept-Encoding at all.
        $hit = $cache->lookup($this->request('/stations'));

        // Assert
        $this->assertFalse($hit->hasHeader('Content-Encoding'));
        $this->assertSame('plain', $hit->getBody());
    }

    // ── Vary, and the shared cache in front of us ───────────────────────────

    /**
     * A gzip-capable cache always declares `Vary: Accept-Encoding`.
     *
     * With `gzip` on, one URL has two bodies and the choice is made from a
     * request header. A shared cache in front of the application — a CDN, a
     * corporate proxy — that is not told this will store one variant and serve
     * it to a client that asked for the other: compressed bytes to a client that
     * will not decompress them. "The page is broken, but only for some people",
     * and it never reproduces locally.
     *
     * **Asserted on both branches**, which is the whole point. A shared cache
     * that saw the plain copy first has the identical problem in reverse, so a
     * `Vary` emitted only alongside `Content-Encoding` fixes half of it.
     *
     * Reported by a consuming application hours after this feature shipped. The
     * original tests exercised both branches and asserted `Content-Encoding` on
     * each — which is exactly how a missing header survives a green suite.
     *
     * The reversal that reddens this: remove the `Vary` block from
     * `decorate()`.
     *
     * @return array<string,array{string|null}>
     */
    public static function acceptEncodingProvider(): array
    {
        return [
            'a client that accepts gzip'     => ['gzip, deflate, br'],
            'a client that does not'         => [null],
            'a client that sends it empty'   => [''],
            'a client that accepts only br'  => ['br'],
        ];
    }

    #[DataProvider('acceptEncodingProvider')]
    public function testVaryIsAlwaysDeclaredWhenGzipIsEnabled(?string $accept): void
    {
        // Arrange
        $cache = $this->cache(['gzip' => true]);
        $cache->store($this->request('/stations'), $this->page(str_repeat('<p>x</p>', 40)));

        // Act
        if ($accept === null) {
            unset($_SERVER['HTTP_ACCEPT_ENCODING']);
        } else {
            $_SERVER['HTTP_ACCEPT_ENCODING'] = $accept;
        }
        $hit = $cache->lookup($this->request('/stations'));

        // Assert
        $this->assertStringContainsStringIgnoringCase(
            'Accept-Encoding', (string) $hit->getHeaderLine('Vary')
        );
    }

    /**
     * A `Vary` the application already sent is kept, not replaced.
     *
     * Dropping a page's own `Vary: Accept-Language` to add ours would break the
     * caching of exactly the pages that were careful about it.
     */
    public function testAnApplicationVaryIsMergedRatherThanOverwritten(): void
    {
        // Arrange — `vary` is on the header whitelist, so it is stored.
        $cache = $this->cache(['gzip' => true]);
        $cache->store(
            $this->request('/stations'),
            $this->page('<html>x</html>')->withHeader('Vary', 'Accept-Language')
        );

        // Act
        $hit = $cache->lookup($this->request('/stations'));

        // Assert
        $vary = strtolower((string) $hit->getHeaderLine('Vary'));
        $this->assertStringContainsString('accept-language', $vary);
        $this->assertStringContainsString('accept-encoding', $vary);
    }

    /**
     * It is not added twice when the application already declared it.
     *
     * `Vary: Accept-Encoding, Accept-Encoding` is legal and pointless, and it is
     * the sort of thing that makes a header diff unreadable while looking like a
     * bug to whoever is reading it.
     */
    public function testVaryIsNotDuplicated(): void
    {
        // Arrange
        $cache = $this->cache(['gzip' => true]);
        $cache->store(
            $this->request('/stations'),
            $this->page('<html>x</html>')->withHeader('Vary', 'accept-encoding')
        );

        // Act
        $hit = $cache->lookup($this->request('/stations'));

        // Assert
        $this->assertSame(
            1, substr_count(strtolower((string) $hit->getHeaderLine('Vary')), 'accept-encoding')
        );
    }

    /**
     * With gzip off there is one body per URL, so nothing is added.
     *
     * A `Vary` nobody needs costs hit rate in every shared cache downstream,
     * which is the opposite of the point.
     */
    public function testNoVaryIsAddedWhenGzipIsDisabled(): void
    {
        // Arrange
        $cache = $this->cache(['gzip' => false]);
        $cache->store($this->request('/stations'), $this->page('<html>x</html>'));

        // Act
        $_SERVER['HTTP_ACCEPT_ENCODING'] = 'gzip';
        $hit = $cache->lookup($this->request('/stations'));

        // Assert
        $this->assertFalse($hit->hasHeader('Vary'));
    }

    /** A page that already varies on everything is left saying so. */
    public function testAWildcardVaryIsLeftAlone(): void
    {
        // Arrange
        $cache = $this->cache(['gzip' => true]);
        $cache->store(
            $this->request('/stations'),
            $this->page('<html>x</html>')->withHeader('Vary', '*')
        );

        // Act
        $hit = $cache->lookup($this->request('/stations'));

        // Assert
        $this->assertSame('*', $hit->getHeaderLine('Vary'));
    }

    // ── Conditional requests ────────────────────────────────────────────────

    /**
     * The 304 carries the ETag.
     *
     * RFC 7232 §4.1. Without it a client cannot tell which of its stored copies
     * has just been confirmed fresh, and some re-download on the next cycle —
     * losing the round trip the 304 exists to save.
     *
     * The reversal that reddens this: drop the `withHeader('ETag', …)` from the
     * 304 branch of `responseFrom()`.
     */
    public function testThe304CarriesTheEtag(): void
    {
        // Arrange
        $cache = $this->cache();
        $cache->store($this->request('/stations'), $this->page('<html>x</html>'));
        $etag = $cache->lookup($this->request('/stations'))->getHeaderLine('ETag');

        // Act
        $_SERVER['HTTP_IF_NONE_MATCH'] = $etag;
        $hit = $cache->lookup($this->request('/stations'));

        // Assert
        $this->assertSame(304, $hit->getStatusCode());
        $this->assertSame($etag, $hit->getHeaderLine('ETag'));
    }

    /**
     * `If-None-Match` is understood in the shapes clients actually send it.
     *
     * A list is legal and common from a client holding several variants; `W/`
     * marks a weak validator, and for whole stored bytes there is no strong
     * comparison to fail; `*` matches anything stored. Mishandling any of them
     * is not a correctness bug — the answer falls back to a 200 — but it throws
     * away the saving the ETag was added for, silently.
     *
     * @return array<string,array{string,bool}>
     */
    public static function ifNoneMatchProvider(): array
    {
        return [
            'the exact tag'        => ['%ETAG%', true],
            'a weak tag'           => ['W/%ETAG%', true],
            'a list containing it' => ['"other", %ETAG%', true],
            'a list, ours first'   => ['%ETAG%, "other"', true],
            'a wildcard'           => ['*', true],
            'a list without it'    => ['"a", "b"', false],
            'a different tag'      => ['"nope"', false],
            'empty'                => ['', false],
        ];
    }

    #[DataProvider('ifNoneMatchProvider')]
    public function testIfNoneMatchIsParsedNotJustCompared(
        string $header, bool $expect304
    ): void {
        // Arrange
        $cache = $this->cache();
        $cache->store($this->request('/stations'), $this->page('<html>x</html>'));
        $etag = $cache->lookup($this->request('/stations'))->getHeaderLine('ETag');

        // Act
        $_SERVER['HTTP_IF_NONE_MATCH'] = str_replace('%ETAG%', $etag, $header);
        $hit = $cache->lookup($this->request('/stations'));

        // Assert
        $this->assertSame($expect304 ? 304 : 200, $hit->getStatusCode());
    }

    /** The debug header can be switched off. */
    public function testTheDebugHeaderCanBeDisabled(): void
    {
        // Arrange
        $cache = $this->cache(['debugHeader' => false]);
        $cache->store($this->request('/stations'), $this->page());

        // Act
        $hit = $cache->lookup($this->request('/stations'));

        // Assert
        $this->assertFalse($hit->hasHeader('X-Pramnos-Cache'));
        $this->assertFalse($hit->hasHeader('Age'));
    }

    /** `Age` reports how long the entry has been held. */
    public function testTheAgeHeaderReportsTheEntryAge(): void
    {
        // Arrange
        $cache = $this->cache(['ttl' => 3600]);
        $cache->store($this->request('/stations'), $this->page());
        $this->ageEntry($cache, '/stations', 42);

        // Act
        $hit = $cache->lookup($this->request('/stations'));

        // Assert
        $this->assertSame('42', $hit->getHeaderLine('Age'));
    }

    // ── Statistics (§6.8) ───────────────────────────────────────────────────

    /** The counters follow what actually happened. */
    public function testTheCountersRecordHitsMissesAndStores(): void
    {
        // Arrange
        $cache = $this->cache();

        // Act
        $cache->lookup($this->request('/stations'));                  // miss
        $cache->store($this->request('/stations'), $this->page());    // store
        $cache->lookup($this->request('/stations'));                  // hit
        $cache->lookup($this->request('/stations'));                  // hit

        // Assert
        $stats = $cache->stats();
        $this->assertSame(1, $stats['miss']);
        $this->assertSame(1, $stats['store']);
        $this->assertSame(2, $stats['hit']);
    }

    // ── The static-file writer (§10) ────────────────────────────────────────

    /**
     * With the static writer on, a hit lands on disk as a real file.
     *
     * The mechanism WP Super Cache calls mod_rewrite mode: a rewrite rule in
     * front of the front controller serves the file and a logged-out visitor
     * never reaches PHP.
     */
    public function testTheStaticWriterWritesThePageToDisk(): void
    {
        // Arrange
        $cache = $this->cache([
            'writer' => 'static', 'staticRoot' => $this->staticRoot,
        ]);

        // Act
        $cache->store($this->request('/stations/7'), $this->page('<html>seven</html>'));

        // Assert
        $file = $this->staticRoot . '/example.test/stations/7/index.html';
        $this->assertFileExists($file);
        $this->assertSame('<html>seven</html>', file_get_contents($file));
    }

    /** The gzip twin is written beside it, for `gzip_static`-style serving. */
    public function testTheStaticWriterWritesAGzipTwin(): void
    {
        // Arrange
        $body  = str_repeat('<p>x</p>', 50);
        $cache = $this->cache([
            'writer' => 'static', 'staticRoot' => $this->staticRoot,
        ]);

        // Act
        $cache->store($this->request('/stations'), $this->page($body));

        // Assert
        $gz = $this->staticRoot . '/example.test/stations/index.html.gz';
        $this->assertFileExists($gz);
        $this->assertSame($body, gzdecode((string) file_get_contents($gz)));
    }

    /**
     * A URL with a query string is never written as a file.
     *
     * A rewrite rule cannot apply `ignoreQuery`, so it would serve
     * `?utm_source=x` from the file written for the clean URL — or, worse,
     * serve `?page=1` for `?page=2`. Those requests stay on the PHP path, where
     * normalisation still happens.
     *
     * The reversal that reddens this: drop the `normalisedQuery() !== ''` guard
     * in `writeStatic()`.
     */
    public function testAUrlWithAQueryStringIsNotWrittenStatically(): void
    {
        // Arrange
        $cache = $this->cache([
            'writer' => 'static', 'staticRoot' => $this->staticRoot,
        ]);

        // Act
        $cache->store($this->request('/stations?page=2'), $this->page('two'));

        // Assert — cached in the store, but not on disk.
        $_GET = [];
        $this->assertNotNull($cache->lookup($this->request('/stations?page=2')));
        $this->assertFileDoesNotExist(
            $this->staticRoot . '/example.test/stations/index.html'
        );
    }

    /**
     * A purge removes the static twin too.
     *
     * Otherwise the rewrite rule keeps serving the file the purge was supposed
     * to remove — an invalidation that reports success and changes nothing,
     * which is worse than one that fails loudly.
     */
    public function testPurgingRemovesTheStaticFile(): void
    {
        // Arrange
        $cache = $this->cache([
            'writer' => 'static', 'staticRoot' => $this->staticRoot,
        ]);
        PageCache::tag('station:7');
        $cache->store($this->request('/stations/7'), $this->page('seven'));
        $file = $this->staticRoot . '/example.test/stations/7/index.html';
        $this->assertFileExists($file);

        // Act
        $cache->purgeTag('station:7');

        // Assert
        $this->assertFileDoesNotExist($file);
    }

    /** A flush removes the whole static tree. */
    public function testFlushRemovesTheStaticTree(): void
    {
        // Arrange
        $cache = $this->cache([
            'writer' => 'static', 'staticRoot' => $this->staticRoot,
        ]);
        $cache->store($this->request('/stations/7'), $this->page('a'));
        $cache->store($this->request('/artists/3'), $this->page('b'));

        // Act
        $cache->flush();

        // Assert
        $this->assertDirectoryDoesNotExist($this->staticRoot);
    }

    /**
     * A URL that tries to escape the cache root writes nothing.
     *
     * A page cache that turns request paths into filesystem paths is a
     * directory traversal waiting to happen, and the check belongs in the
     * writer rather than in whatever calls it.
     *
     * @return array<string,array{string}>
     */
    public static function traversalProvider(): array
    {
        return [
            'dot segments'      => ['/../../etc/passwd'],
            'encoded traversal' => ['/%2e%2e/%2e%2e/etc/passwd'],
            'a deep escape'     => ['/stations/../../../../tmp/owned'],
        ];
    }

    #[DataProvider('traversalProvider')]
    public function testATraversingUrlWritesNothing(string $uri): void
    {
        // Arrange
        $cache = $this->cache([
            'writer' => 'static', 'staticRoot' => $this->staticRoot,
        ]);

        // Act
        $cache->store($this->request($uri), $this->page('owned'));

        // Assert — nothing outside the root, and nothing claiming to be inside
        // it by way of `..`.
        $this->assertFileDoesNotExist('/tmp/owned/index.html');
        foreach ((array) glob($this->staticRoot . '/*/*') as $written) {
            $this->assertStringNotContainsString('..', (string) $written);
        }
    }

    // ── serveEarly (§6.6) ───────────────────────────────────────────────────

    /**
     * `serveEarly()` reports whether it answered, without booting anything.
     *
     * The reason a page cache is fast is not the storage — it is how little has
     * run by the time it answers. This is callable straight after the
     * autoloader, and it is safe there because every rule the decision uses
     * reads the request and nothing else.
     */
    public function testServeEarlyReturnsFalseWhenThereIsNothingCached(): void
    {
        // Arrange
        $this->request('/stations');

        // Act — $exit = false so the test survives it.
        $served = PageCache::serveEarly(['enabled' => true, 'store' => 'array'], false);

        // Assert
        $this->assertFalse($served);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Age a stored entry by rewriting its `created` stamp.
     *
     * Cheaper and more precise than sleeping, and it lets a test say "this is
     * 65 seconds old" without the suite taking 65 seconds. Reaches through
     * reflection because the store is deliberately private — a test that needed
     * a public setter would be arguing for an API nothing else wants.
     */
    private function ageEntry(PageCache $cache, string $uri, int $seconds): void
    {
        $request = $this->request($uri);

        $flat = (new \ReflectionMethod($cache, 'flat'))->invoke($cache);
        $key  = 'pc:e:' . $cache->keyFor($request);

        $entry = $flat->get($key);
        $this->assertIsArray($entry, 'the entry must exist before it can be aged');

        $entry['created'] -= $seconds;
        $flat->set($key, $entry, 86400);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach ((array) scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            is_dir($full) ? $this->removeTree($full) : @unlink($full);
        }
        @rmdir($path);
    }
}
