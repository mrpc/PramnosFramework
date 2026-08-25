<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Cache\Adapter\ArrayAdapter;
use Pramnos\Cache\Page\PageCache;
use Pramnos\Http\Middleware\PageCacheMiddleware;
use Pramnos\Http\Request;
use Pramnos\Http\Response;

/**
 * Three ways the page cache was wrong in a consuming application, all silent.
 *
 * Grouped in one class because they are one story — somebody turning the cache
 * on for the first time hits them in this order: it does not switch on, then it
 * caches pages that lose their security policy, then it caches pages for signed-in
 * visitors. None of the three produced an error message.
 *
 * The tests stand an `Application` up through `newInstanceWithoutConstructor()`.
 * The real constructor reads `app.php` from disk, defines constants and boots the
 * breadcrumb trail; all that is wanted here is a live instance with two public
 * properties set, and `currentInstance()` is the lookup that finds it.
 */
#[CoversClass(PageCacheMiddleware::class)]
#[CoversClass(PageCache::class)]
class PageCacheConfigAndCspTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $server = [];
    /** @var array<string,mixed> */
    private array $cookie = [];

    protected function setUp(): void
    {
        $this->server = $_SERVER;
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
        $_COOKIE = $this->cookie;

        $this->forgetApplication();
        Settings::clearSettings();
        PageCache::resetRuntime();
        Request::resetInstance();
    }

    /**
     * A live application with nothing booted behind it.
     *
     * @param array<string,mixed> $info What `app.php` would have returned
     */
    private function application(array $info = []): Application
    {
        $app = (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();
        $app->applicationInfo = $info;

        $rc = new \ReflectionClass(Application::class);
        $instances = $rc->getProperty('appInstances');
        $instances->setValue(null, ['default' => $app]);
        $rc->getProperty('lastUsedApplication')->setValue(null, 'default');

        return $app;
    }

    private function forgetApplication(): void
    {
        $rc = new \ReflectionClass(Application::class);
        $rc->getProperty('appInstances')->setValue(null, []);
        $rc->getProperty('lastUsedApplication')->setValue(null, null);
    }

    /**
     * The engine the middleware built for itself, for a test that is about the
     * building rather than about caching.
     */
    private function engineOf(PageCacheMiddleware $middleware): PageCache
    {
        $property = new \ReflectionProperty(PageCacheMiddleware::class, 'cache');

        return $property->getValue($middleware);
    }

    private function request(string $uri = '/stations'): Request
    {
        $_SERVER['REQUEST_URI'] = $uri;
        Request::resetInstance();

        return new Request();
    }

    // ── 1. The configuration is read where the guide says to put it ──────────

    /**
     * A `pagecache` block in `app.php` switches the cache on.
     *
     * The bug this pins down: the middleware read `Settings::getSetting()` alone,
     * so the block the guide has always shown in `app.php` was never seen. The
     * cache was built on defaults, `enabled` stayed false, and the only symptom
     * was that `X-Pramnos-Cache` never appeared — indistinguishable from a dozen
     * other reasons a page is not cached.
     *
     * `whyBypassed()` is the assertion rather than a hit/miss count because it
     * names *which* rule refused: `'disabled'` is the answer that proves the
     * config never arrived, and any other answer would be a different bug.
     */
    public function testAPagecacheBlockInAppPhpIsRead(): void
    {
        // Arrange — configuration in app.php only, exactly as documented.
        $this->application(['pagecache' => ['enabled' => true]]);

        // Act
        $engine = $this->engineOf(new PageCacheMiddleware());

        // Assert — nothing refuses this request, so `enabled` arrived as true.
        $this->assertNull($engine->whyBypassed($this->request()));
    }

    /**
     * With no block in `app.php`, the settings store is still consulted.
     *
     * The fix must not become a replacement: an installation that keeps the block
     * in the `settings` table worked before and has to keep working.
     */
    public function testTheSettingsStoreIsStillReadWhenAppPhpHasNoBlock(): void
    {
        // Arrange — an application whose app.php says nothing about the cache.
        $this->application([]);
        Settings::setSetting('pagecache', ['enabled' => true], false);

        // Act
        $engine = $this->engineOf(new PageCacheMiddleware());

        // Assert
        $this->assertNull($engine->whyBypassed($this->request()));
    }

    /**
     * A settings-store block arrives as an object, and is still read.
     *
     * `Settings::getSetting()` casts an array value to `stdClass` on the way out.
     * The old code tested `is_array()` and returned `[]`, so the *documented
     * alternative* location did not work either — both paths were dead, for two
     * unrelated reasons.
     */
    public function testASettingStoredAsAnArrayArrivesAsAnArray(): void
    {
        // Arrange
        $this->application([]);
        Settings::setSetting('pagecache', ['enabled' => true, 'ttl' => 60], false);

        // Act — getSetting() hands back a stdClass here, not an array.
        $this->assertIsObject(Settings::getSetting('pagecache'));
        $engine = $this->engineOf(new PageCacheMiddleware());

        // Assert — the cast happened, so the block was honoured.
        $this->assertNull($engine->whyBypassed($this->request()));
    }

    /**
     * `app.php` wins over the settings store.
     *
     * Deliberate precedence, not an accident of ordering: `app.php` is in the
     * repository and reviewable, the settings row is not, and reading it costs a
     * database query the cache exists to avoid.
     */
    public function testAppPhpWinsOverTheSettingsStore(): void
    {
        // Arrange — the two disagree.
        $this->application(['pagecache' => ['enabled' => false]]);
        Settings::setSetting('pagecache', ['enabled' => true], false);

        // Act
        $engine = $this->engineOf(new PageCacheMiddleware());

        // Assert — app.php's `false` is the answer.
        $this->assertSame('disabled', $engine->whyBypassed($this->request()));
    }

    // ── 2. A hit keeps the Content-Security-Policy ───────────────────────────

    /**
     * A cache hit carries a Content-Security-Policy.
     *
     * `sendCspHeader()` is called from `Application::render()`. A hit returns a
     * `Response` before the application runs, so `render()` never executed and the
     * page went out with **no policy at all** — and it could not be replayed from
     * the stored entry either, because the header never reached the `Response`: it
     * goes straight out through `header()`.
     *
     * The failure looked like nothing at all. The markup was right, the scripts
     * ran — they ran because there was no longer a policy to stop them, and this
     * framework's default policy is `default-src 'none'`.
     */
    public function testACacheHitCarriesTheContentSecurityPolicy(): void
    {
        // Arrange — a rendered page with no inline script, so it is storable.
        $this->application([]);
        $middleware = new PageCacheMiddleware(
            new PageCache(['enabled' => true], new ArrayAdapter())
        );
        $next = static fn (): Response => Response::make('<html>catalogue</html>');
        $middleware->handle($this->request(), $next);

        // Act — the second request is the hit.
        $hit = $middleware->handle($this->request(), $next);

        // Assert
        $policy = $hit->getHeaderLine('Content-Security-Policy');
        $this->assertNotNull($policy, 'A cached page must not be served policy-less.');
        $this->assertStringContainsString("default-src 'none'", $policy);
    }

    /**
     * The policy on a hit carries a real nonce, not an empty one.
     *
     * `Application::$cspNonce` is generated in `exec()`, which a hit never reaches,
     * so the policy builder was interpolating an empty string and emitting the
     * source expression `'nonce-'`. That matches no element — the safe direction —
     * but it is not a policy anybody wrote, and it reads as a framework bug in
     * every report that scans headers.
     */
    public function testThePolicyOnAHitCarriesAGeneratedNonce(): void
    {
        // Arrange
        $app = $this->application([]);
        $this->assertSame('', $app->cspNonce, 'A hit reaches no exec(), so there is no nonce yet.');

        // Act
        $policy = $app->cspPolicy();

        // Assert — a nonce was generated for this response.
        $this->assertStringNotContainsString("'nonce-'", $policy);
        $this->assertNotSame('', $app->cspNonce);
        $this->assertStringContainsString("'nonce-" . $app->cspNonce . "'", $policy);
    }

    /**
     * A body containing this response's nonce is refused by `store()`.
     *
     * The other half of the CSP story, and the half that cannot be fixed by
     * replaying a header. The framework stamps a per-response nonce into every
     * inline `<script>`; a stored body freezes that nonce for the whole TTL and
     * hands the same one to every visitor. A nonce that is reused is not a nonce.
     *
     * So the two features are mutually exclusive for such a page, and this is the
     * side of the choice that fails loudly: the page is simply never cached, and
     * the application can decide to serve it without an inline script if it wants
     * it cached.
     */
    public function testAResponseWhoseBodyCarriesTheNonceIsNotStored(): void
    {
        // Arrange — a page with a nonced inline script, as Document\Raw writes it.
        $app = $this->application([]);
        $app->cspNonce = 'Zm9vYmFyYmF6cXV1eA==';
        $cache = new PageCache(['enabled' => true], new ArrayAdapter());
        $body  = '<html><script nonce="' . $app->cspNonce . '">go()</script></html>';

        // Act
        $stored = $cache->store($this->request(), Response::make($body));

        // Assert
        $this->assertFalse($stored, 'A body carrying a per-response nonce is per-response.');
    }

    /**
     * The same page without the nonce is stored.
     *
     * The counterpart assertion, and the one that proves the refusal above is not
     * simply "nothing is ever stored": the escape hatch the guide points at has to
     * actually work.
     */
    public function testAResponseWithoutTheNonceIsStored(): void
    {
        // Arrange
        $app = $this->application([]);
        $app->cspNonce = 'Zm9vYmFyYmF6cXV1eA==';
        $cache = new PageCache(['enabled' => true], new ArrayAdapter());

        // Act — an external script instead of an inline one.
        $stored = $cache->store(
            $this->request(),
            Response::make('<html><script src="/app.js"></script></html>')
        );

        // Assert
        $this->assertTrue($stored);
    }

    // ── 3. The session cookie bypasses the cache ─────────────────────────────

    /**
     * A request carrying the session cookie is neither served nor stored.
     *
     * The default `bypassCookies` was `['#^(auth|remember|logged)#i']`, which never
     * matched `PHPSESSID`. An application whose signed-in state lives only in
     * `$_SESSION` therefore had nothing here to stop it: measured in a consuming
     * application, every public page differed when signed in, and a signed-in
     * response set no cookie — so `store()`'s `Set-Cookie` rule did not catch it
     * either.
     *
     * The reason it is safe as a *default* is `'session' => 'lazy'`: with it an
     * anonymous reader carries no session cookie at all, so this costs nothing.
     */
    public function testTheSessionCookieBypassesTheCache(): void
    {
        // Arrange
        $cache = new PageCache(['enabled' => true], new ArrayAdapter());
        $_COOKIE[(string) session_name()] = 'abc123';

        // Act
        $reason = $cache->whyBypassed($this->request());

        // Assert — named by the cookie, so a diagnostic header says which rule.
        $this->assertSame('cookie:' . session_name(), $reason);
    }

    /**
     * An anonymous request — no session cookie — is still cacheable.
     *
     * Proves the rule above is the cookie and not the path: a default that
     * bypassed everything would pass the previous test and cache nothing at all.
     */
    public function testAnAnonymousRequestIsStillCacheable(): void
    {
        // Arrange — no cookies at all, which is what lazy sessions produce.
        $cache = new PageCache(['enabled' => true], new ArrayAdapter());

        // Act
        $reason = $cache->whyBypassed($this->request());

        // Assert
        $this->assertNull($reason);
    }
}
