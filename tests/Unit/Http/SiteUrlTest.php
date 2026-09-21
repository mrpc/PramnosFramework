<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Http\SiteUrl;

/**
 * `SiteUrl` — the site root, which `sURL` is not once there is a second front controller.
 *
 * WHAT: the configured value wins, the request-derived fallback answers the **document
 *       root** rather than the script's directory, and a CLI process with nothing
 *       configured answers `''` instead of inventing a host.
 *
 * WHY:  `getUrl()` — and therefore `sURL` — ends in `dirname($_SERVER['SCRIPT_NAME'])`. A
 *       scaffolded hybrid project serves its API from `www/api/index.php`, so every
 *       request handled there has `sURL === 'https://site/api/'`, and an asset built as
 *       `sURL . 'uploads/x.jpg'` is a 404. The URL is absolute and well-formed, so every
 *       "is this fetchable" guard passes it: one reached an external service, which
 *       fetched it, got a 404, and answered with an error that never named the URL.
 *
 *       And `getUrl()` cannot answer at all from cron — no `SERVER_NAME` — so a scheduled
 *       job putting a public URL in an email has nothing to build one from.
 *
 * `$_SERVER` and the environment are process-wide, so each test restores what it changed
 * and resets the memo; otherwise the first test to run decides the answer for all of them.
 */
#[CoversClass(SiteUrl::class)]
class SiteUrlTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $server;

    private string|false $appUrl;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $this->appUrl = getenv('APP_URL');

        putenv('APP_URL');
        unset($_ENV['APP_URL']);
        foreach (['HTTP_HOST', 'SERVER_NAME', 'HTTPS', 'HTTP_X_FORWARDED_PROTO',
                  'SCRIPT_NAME', 'SCRIPT_FILENAME', 'DOCUMENT_ROOT'] as $key) {
            unset($_SERVER[$key]);
        }
        SiteUrl::reset();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        if (is_string($this->appUrl)) {
            putenv('APP_URL=' . $this->appUrl);
        } else {
            putenv('APP_URL');
        }
        unset($_ENV['APP_URL']);
        SiteUrl::reset();
    }

    /** A request for a script at the given URL path and file path. */
    private function request(string $scriptName, string $scriptFilename, string $documentRoot): void
    {
        $_SERVER['HTTP_HOST']       = 'example.com';
        $_SERVER['SCRIPT_NAME']     = $scriptName;
        $_SERVER['SCRIPT_FILENAME'] = $scriptFilename;
        $_SERVER['DOCUMENT_ROOT']   = $documentRoot;
        SiteUrl::reset();
    }

    /**
     * The sub-application case, which is the whole reason this class exists.
     *
     * `www/api/index.php` under a document root of `www/` is still the same site, and its
     * root is `/`. `sURL` says `/api/` here, and an upload path built on it 404s.
     */
    public function testAnApiFrontControllerStillAnswersTheSiteRoot(): void
    {
        // Arrange
        $this->request('/api/index.php', '/srv/site/www/api/index.php', '/srv/site/www');

        // Act + Assert
        $this->assertSame('http://example.com/', SiteUrl::get());
        $this->assertSame('http://example.com/uploads/x.jpg', SiteUrl::to('uploads/x.jpg'));
    }

    /**
     * And the ordinary front controller answers the same thing.
     *
     * The control: a class that returned `/` for everything would pass the test above and
     * be no better than a constant.
     */
    public function testTheMainFrontControllerAnswersTheSameRoot(): void
    {
        // Arrange
        $this->request('/index.php', '/srv/site/www/index.php', '/srv/site/www');

        // Act + Assert
        $this->assertSame('http://example.com/', SiteUrl::get());
    }

    /**
     * A site in a subdirectory keeps its subdirectory.
     *
     * The derivation removes the part of the file path below the document root from the
     * URL path; what is left is the prefix the site is mounted at. Returning `/` here
     * would break every link on such an installation, which is the failure mode of the
     * obvious "just use the host" implementation.
     */
    public function testASiteInASubdirectoryKeepsIt(): void
    {
        // Arrange — mounted at /shop, API at /shop/api
        $this->request('/shop/api/index.php', '/srv/site/www/api/index.php', '/srv/site/www');

        // Act + Assert
        $this->assertSame('http://example.com/shop/', SiteUrl::get());
    }

    /**
     * With no usable document root it falls back to the script's directory.
     *
     * Which is `sURL`'s answer — wrong for a sub-application and no worse than today. The
     * branch exists for a rewrite or an SAPI where the two paths do not line up, and the
     * point of asserting it is that it degrades rather than returning something invented.
     */
    public function testItFallsBackToTheScriptDirectoryWhenTheRootCannotBeMatched(): void
    {
        // Arrange — a document root the script is not under
        $this->request('/api/index.php', '/elsewhere/api/index.php', '/srv/site/www');

        // Act + Assert
        $this->assertSame('http://example.com/api/', SiteUrl::get());
    }

    /**
     * `APP_URL` wins over anything a request would say.
     *
     * A deployment behind a proxy, on a hostname the container never sees, or serving two
     * hostnames for one site is the normal case in production, and the request is the
     * least reliable of the three sources.
     */
    public function testTheConfiguredValueWins(): void
    {
        // Arrange
        $this->request('/api/index.php', '/srv/site/www/api/index.php', '/srv/site/www');
        putenv('APP_URL=https://cdn.example.org/site');
        SiteUrl::reset();

        // Act + Assert — and it is normalised to one trailing slash
        $this->assertSame('https://cdn.example.org/site/', SiteUrl::get());
        $this->assertTrue(SiteUrl::isConfigured());
    }

    /**
     * `$_ENV` is read as well as `getenv()`.
     *
     * `loadDotenv()` populates both, and a project whose `variables_order` omits `E` has
     * one and not the other — a difference nobody discovers until the value silently stops
     * being found on one host.
     */
    public function testItReadsTheValueFromEnvSuperglobalToo(): void
    {
        // Arrange
        $_ENV['APP_URL'] = 'https://from-env.example.com';
        SiteUrl::reset();

        // Act + Assert
        $this->assertSame('https://from-env.example.com/', SiteUrl::get());
    }

    /**
     * From CLI with nothing configured it answers `''`, and `to()` stays relative.
     *
     * **The assertion that matters most.** Inventing a host produces
     * `http:///uploads/x.jpg`, which is absolute, well-formed and wrong — accepted by
     * everything and fetched by something. A relative path is visibly incomplete to
     * whatever receives it, which is the failure that gets noticed.
     */
    public function testCliWithNothingConfiguredAnswersEmptyRatherThanGuessing(): void
    {
        // Act + Assert — no HTTP_HOST, no SERVER_NAME, no APP_URL
        $this->assertSame('', SiteUrl::get());
        $this->assertFalse(SiteUrl::isConfigured());
        $this->assertSame('uploads/x.jpg', SiteUrl::to('uploads/x.jpg'));
    }

    /**
     * A leading slash on the path does not become a double slash.
     *
     * `//uploads/x.jpg` after a host is a different URL to a cache and to anything that
     * signs one, and callers write it both ways.
     */
    public function testAPathIsJoinedRatherThanConcatenated(): void
    {
        // Arrange
        putenv('APP_URL=https://example.com/');
        SiteUrl::reset();

        // Act + Assert
        $this->assertSame('https://example.com/uploads/x.jpg', SiteUrl::to('/uploads/x.jpg'));
        $this->assertSame('https://example.com/uploads/x.jpg', SiteUrl::to('uploads/x.jpg'));
    }

    /**
     * HTTPS is recognised behind a terminating proxy.
     *
     * The proxy speaks HTTP to the container, so `$_SERVER['HTTPS']` is unset and a scheme
     * taken from it is `http` — which on a mixed-content page is a blocked asset, and in
     * an email is a link that redirects.
     */
    public function testAForwardedProtoIsHonoured(): void
    {
        // Arrange
        $this->request('/index.php', '/srv/site/www/index.php', '/srv/site/www');
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        SiteUrl::reset();

        // Act + Assert
        $this->assertSame('https://example.com/', SiteUrl::get());
    }

    /**
     * `HTTPS=off` is not HTTPS.
     *
     * Some SAPIs set it to the three characters `off` rather than leaving it unset, and a
     * truthiness test on that reads as on.
     */
    public function testHttpsOffIsNotHttps(): void
    {
        // Arrange
        $this->request('/index.php', '/srv/site/www/index.php', '/srv/site/www');
        $_SERVER['HTTPS'] = 'off';
        SiteUrl::reset();

        // Act + Assert
        $this->assertSame('http://example.com/', SiteUrl::get());
    }
}
