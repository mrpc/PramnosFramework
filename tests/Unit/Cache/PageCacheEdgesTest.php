<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\Adapter\ArrayAdapter;
use Pramnos\Cache\Adapter\FileAdapter;
use Pramnos\Cache\Page\PageCache;
use Pramnos\Http\Request;
use Pramnos\Http\Response;

/**
 * The page cache's edges: the paths taken when something is missing, failing or
 * misconfigured.
 *
 * Split from {@see PageCacheTest}, which covers the behaviour a reader of the
 * guide would recognise. These are the branches nobody exercises deliberately —
 * a store that cannot write, a static root that was never configured, a pattern
 * that is an empty string — and they are exactly the ones that go wrong in
 * production and cannot be reproduced afterwards.
 */
#[CoversClass(PageCache::class)]
class PageCacheEdgesTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $server = [];
    /** @var array<string,mixed> */
    private array $get = [];
    /** @var array<string,mixed> */
    private array $cookie = [];

    private string $fileRoot = '';

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
        unset($_SERVER['HTTP_ACCEPT_ENCODING'], $_SERVER['HTTP_IF_NONE_MATCH']);

        PageCache::resetRuntime();
        Request::resetInstance();

        $this->fileRoot = sys_get_temp_dir() . '/pagecache-edges-' . getmypid();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_GET    = $this->get;
        $_COOKIE = $this->cookie;

        PageCache::resetRuntime();
        Request::resetInstance();

        $this->removeTree($this->fileRoot);
    }

    private function request(string $uri = '/stations'): Request
    {
        $_SERVER['REQUEST_URI'] = $uri;
        Request::resetInstance();

        return new Request();
    }

    // ── Which store the configuration actually selects ──────────────────────

    /**
     * Every `store` name builds the adapter it names.
     *
     * Worth a test because the failure is invisible: a typo, or a fallback that
     * quietly swallowed `'redis'`, would give a site a file cache while its
     * configuration and its dashboards both say Redis. Nothing breaks — it is
     * just slower and never shared between web servers, which is the kind of
     * thing that gets diagnosed months later.
     *
     * @return array<string,array{string,class-string}>
     */
    public static function storeNameProvider(): array
    {
        return [
            'file'  => ['file', \Pramnos\Cache\Adapter\FileAdapter::class],
            'array' => ['array', \Pramnos\Cache\Adapter\ArrayAdapter::class],
            'redis' => ['redis', \Pramnos\Cache\Adapter\RedisAdapter::class],
            'FILE in capitals' => ['FILE', \Pramnos\Cache\Adapter\FileAdapter::class],
            'Redis mixed case' => ['Redis', \Pramnos\Cache\Adapter\RedisAdapter::class],
        ];
    }

    /**
     * @param class-string $expected
     */
    #[DataProvider('storeNameProvider')]
    public function testTheConfiguredStoreNameBuildsThatAdapter(
        string $name, string $expected
    ): void {
        // Arrange
        $cache = new PageCache(['enabled' => true, 'store' => $name]);

        // Act — the adapter is built lazily and deliberately private.
        $adapter = (new \ReflectionMethod($cache, 'adapter'))->invoke($cache);

        // Assert
        $this->assertInstanceOf($expected, $adapter);
    }

    /**
     * An unknown store name falls back to the file adapter rather than throwing.
     *
     * The same choice {@see \Pramnos\Cache\Cache} makes, and for the same
     * reason: a cache that cannot reach its backend should degrade to a slower
     * one, not take the site down. A typo in `pagecache.store` must not be a
     * 500 on every page.
     */
    public function testAnUnknownStoreNameFallsBackToFile(): void
    {
        // Arrange
        $cache = new PageCache(['enabled' => true, 'store' => 'nonsense']);

        // Act
        $adapter = (new \ReflectionMethod($cache, 'adapter'))->invoke($cache);

        // Assert
        $this->assertInstanceOf(FileAdapter::class, $adapter);
    }

    // ── serveEarly, on a hit ────────────────────────────────────────────────

    /**
     * `serveEarly()` sends the cached page and reports that it did.
     *
     * The whole reason the method exists: this runs with nothing booted but the
     * autoloader. Driven over the **file** store rather than the array one
     * because `serveEarly()` deliberately builds its own engine from
     * configuration — an in-memory store would not be the same store, and a
     * test that shared one would be testing something the production call
     * cannot do.
     */
    public function testServeEarlySendsACachedPage(): void
    {
        // Arrange — one config, and *no* injected adapter on either side, so
        // both engines resolve the same store the way production does.
        $config = [
            'enabled'    => true,
            'store'      => 'file',
            'prefix'     => 'pagecache-early-' . getmypid() . ':',
            'staticRoot' => '',
        ];

        $writer = new PageCache($config);
        $this->assertTrue(
            $writer->store($this->request('/stations'), Response::make('<html>early</html>')),
            'the fixture must reach the file store'
        );

        // Act — $exit = false, and the body is echoed, so capture it.
        $this->request('/stations');
        ob_start();
        $served = PageCache::serveEarly($config, false);
        $output = (string) ob_get_clean();

        // Assert
        $this->assertTrue($served, 'serveEarly must find what the same config stored');
        $this->assertSame('<html>early</html>', $output);

        // Clean up: this one wrote to the shared default cache directory.
        (new PageCache($config))->flush();
    }

    // ── Headers with more than one value ────────────────────────────────────

    /**
     * A response header carrying several values is replayed as one line.
     *
     * `Link` is the realistic case — a page can advertise a canonical URL and a
     * preload hint at once — and it is on the whitelist, so it does get stored.
     */
    public function testAMultiValuedHeaderIsJoined(): void
    {
        // Arrange
        $cache = new PageCache(['enabled' => true], new ArrayAdapter());
        $response = Response::make('<html>x</html>')
            ->withHeader('Link', '<https://example.test/a>; rel="canonical"')
            ->withHeader('Link', '<https://example.test/b.css>; rel="preload"');

        // Act
        $cache->store($this->request('/stations'), $response);
        $hit = $cache->lookup($this->request('/stations'));

        // Assert
        $line = (string) $hit->getHeaderLine('Link');
        $this->assertStringContainsString('rel="canonical"', $line);
        $this->assertStringContainsString('rel="preload"', $line);
    }

    // ── The static writer, unconfigured or unwritable ───────────────────────

    /**
     * With no static root and no `ROOT` constant, the writer does nothing.
     *
     * It must not fall back to the current directory, or a console command run
     * from somebody's home directory starts scattering `index.html` files into
     * it.
     */
    public function testTheStaticWriterDoesNothingWithoutARoot(): void
    {
        // Arrange — `staticRoot` empty; ROOT is not defined under the suite.
        $cache = new PageCache(
            ['enabled' => true, 'writer' => 'static', 'staticRoot' => ''],
            new ArrayAdapter()
        );

        // Act
        $stored = $cache->store($this->request('/stations'), Response::make('<html>x</html>'));

        // Assert — the page is still cached in the store …
        $this->assertTrue($stored);
        $this->assertNotNull($cache->lookup($this->request('/stations')));
        // … and nothing was written beside the test run.
        $this->assertFileDoesNotExist(getcwd() . '/example.test/stations/index.html');
    }

    /**
     * A purge with the static writer on but no root configured is harmless.
     *
     * The removal path has the same "no root" branch as the write path, and a
     * purge that threw here would make the *invalidation* command fail on a
     * configuration that merely writes no files.
     */
    public function testPurgingWithoutAStaticRootIsHarmless(): void
    {
        // Arrange
        $cache = new PageCache(
            ['enabled' => true, 'writer' => 'static', 'staticRoot' => ''],
            new ArrayAdapter()
        );
        PageCache::tag('station:7');
        $cache->store($this->request('/stations/7'), Response::make('seven'));

        // Act
        $removed = $cache->purgeTag('station:7');

        // Assert
        $this->assertSame(1, $removed);
        $this->assertNull($cache->lookup($this->request('/stations/7')));
    }

    /**
     * Flushing a static tree that was never written is harmless.
     */
    public function testFlushingAnAbsentStaticTreeIsHarmless(): void
    {
        // Arrange
        $cache = new PageCache([
            'enabled' => true, 'writer' => 'static',
            'staticRoot' => $this->fileRoot . '/never-created',
        ], new ArrayAdapter());

        // Act & Assert
        $this->assertTrue($cache->flush());
    }

    /**
     * An unwritable static root is not a failed request.
     *
     * The page is still cached and still served; only the static twin is
     * missing. A cache that returned a 500 because its optional accelerator
     * could not write would be strictly worse than not having the accelerator.
     */
    public function testAnUnwritableStaticRootStillCachesThePage(): void
    {
        // Arrange — a *file* where the writer expects a directory tree.
        $blocker = $this->fileRoot . '/blocked';
        @mkdir($this->fileRoot, 0777, true);
        file_put_contents($blocker, 'not a directory');

        $cache = new PageCache([
            'enabled' => true, 'writer' => 'static', 'staticRoot' => $blocker,
        ], new ArrayAdapter());

        // Act
        $stored = $cache->store($this->request('/stations'), Response::make('<html>x</html>'));

        // Assert
        $this->assertTrue($stored, 'the page must still be cached');
        $this->assertNotNull($cache->lookup($this->request('/stations')));
    }

    // ── Pattern matching edges ──────────────────────────────────────────────

    /**
     * An empty pattern matches nothing.
     *
     * A configuration array with a stray empty string in it — a trailing comma
     * in a generated config, a value read from an environment variable that was
     * never set — must not become "bypass everything", which would silently
     * switch the cache off site-wide.
     *
     * The reversal that reddens this: drop the `$pattern === ''` guard in
     * `matches()`; `fnmatch('', '/stations')` is false but `preg_match` on an
     * empty pattern warns, and the intent is worth pinning either way.
     */
    public function testAnEmptyPatternMatchesNothing(): void
    {
        // Arrange
        $cache = new PageCache(
            ['enabled' => true, 'bypassPaths' => ['']], new ArrayAdapter()
        );

        // Act & Assert — still cacheable.
        $this->assertNull($cache->whyBypassed($this->request('/stations')));
    }

    /**
     * A malformed regex does not take the site down.
     *
     * Configuration is written by hand. An unbalanced bracket must mean "this
     * rule matches nothing", not a warning on every request.
     */
    public function testAMalformedRegexPatternDoesNotThrow(): void
    {
        // Arrange
        $cache = new PageCache(
            ['enabled' => true, 'bypassPaths' => ['#^/admin[#']], new ArrayAdapter()
        );

        // Act & Assert
        $this->assertNull($cache->whyBypassed($this->request('/stations')));
    }

    /**
     * A non-scalar query value does not break the key.
     *
     * `?filter[]=a&filter[]=b` arrives in `$_GET` as an array, and a key builder
     * that assumed strings would emit a notice and produce `Array` for every
     * such request — collapsing every distinct filter combination onto one
     * entry, which is a wrong-page bug rather than a slow one.
     */
    public function testAnArrayQueryValueProducesADistinctKey(): void
    {
        // Arrange
        $cache = new PageCache(['enabled' => true], new ArrayAdapter());

        $_GET = ['filter' => ['a', 'b']];
        $first = $cache->keyFor($this->request('/stations'));

        // Act
        $_GET = ['filter' => ['c', 'd']];
        $second = $cache->keyFor($this->request('/stations'));

        // Assert
        $this->assertNotSame($first, $second);
    }

    /**
     * A store that refuses to write reports a failure rather than pretending.
     *
     * `store()` returning true when nothing was written would make the next
     * lookup a miss for ever, and the counters would show a healthy store rate
     * against a 0% hit rate — the shape of an incident nobody can explain.
     */
    public function testAStoreThatCannotWriteReportsFailure(): void
    {
        // Arrange — an adapter whose save always fails.
        $adapter = new class extends ArrayAdapter {
            public function save($key, $data, $timeout = 3600): bool
            {
                return false;
            }
        };
        $cache = new PageCache(['enabled' => true], $adapter);

        // Act
        $stored = $cache->store($this->request('/stations'), Response::make('<html>x</html>'));

        // Assert
        $this->assertFalse($stored);
        $this->assertSame(0, $cache->stats()['store']);
    }

    /**
     * HTTPS is part of the key.
     *
     * A page rendered with absolute `http://` links served to an HTTPS visitor
     * is a mixed-content page, which browsers block silently.
     */
    public function testTheSchemeIsPartOfTheKey(): void
    {
        // Arrange
        $cache = new PageCache(['enabled' => true], new ArrayAdapter());
        $plain = $cache->keyFor($this->request('/stations'));

        // Act
        $_SERVER['HTTPS'] = 'on';
        $secure = $cache->keyFor($this->request('/stations'));

        // Assert
        $this->assertNotSame($plain, $secure);
    }

    /**
     * `HTTPS=off` is http, as the CGI specification says.
     *
     * IIS sets the variable to the string `off` rather than unsetting it, so
     * `!empty($_SERVER['HTTPS'])` alone reads every plain request as secure.
     */
    public function testHttpsOffIsTreatedAsPlainHttp(): void
    {
        // Arrange
        $cache = new PageCache(['enabled' => true], new ArrayAdapter());
        unset($_SERVER['HTTPS']);
        $absent = $cache->keyFor($this->request('/stations'));

        // Act
        $_SERVER['HTTPS'] = 'off';
        $off = $cache->keyFor($this->request('/stations'));

        // Assert
        $this->assertSame($absent, $off);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);
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
