<?php

namespace Pramnos\Tests\Unit\Application;

use Pramnos\Application\Application;
use Pramnos\Logs\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Which CSP directives an application can extend, and what happens when it names one
 * that no directive consults.
 *
 * The bug this file exists for: `form-action 'self'` was hard-coded, and an OAuth2
 * authorization server cannot work under it — the whole point of the endpoint is to
 * send a user, after a form POST, to a registered third-party callback, and a browser
 * applies `form-action` to every redirect in the chain. The server issues the code and
 * the `Location`; the browser cancels the navigation; nothing server-side knows. Four
 * directives in this list have now failed the same way, so the last test here is the
 * general one: every directive the framework claims is configurable must actually
 * consult configuration.
 */
#[CoversClass(Application::class)]
class CspConfigurableDirectivesTest extends TestCase
{
    /**
     * Split a policy header value into directive => list of sources.
     *
     * @return array<string,list<string>>
     */
    private function directives(string $policy): array
    {
        $parsed = [];
        foreach (explode('; ', $policy) as $segment) {
            $parts = preg_split('/\s+/', trim($segment)) ?: [];
            $name = array_shift($parts);
            if ($name !== null && $name !== '') {
                $parsed[$name] = $parts;
            }
        }

        return $parsed;
    }

    /**
     * The reported defect, exactly: an authorization server names its client callbacks
     * and the policy carries them.
     *
     * Without this, `POST /Home/login` → 302 `/oauth/authorize` → 302 `https://client/…`
     * is cancelled at the last hop by a policy the application could not influence, and
     * the console blames the same-origin URL the chain started at.
     */
    public function testFormActionCarriesTheApplicationsRegisteredCallbacks(): void
    {
        // Arrange — an authorization server with one registered client
        $csp = ['form-action' => ['https://client.example']];

        // Act
        $policy = Application::buildCspPolicy($csp);

        // Assert — 'self' is kept, the client is added
        $this->assertSame(
            ["'self'", 'https://client.example'],
            $this->directives($policy)['form-action'],
            'form-action must keep its default and take the application sources'
        );
    }

    /**
     * A deployment embedded in somebody else's page can say who may embed it.
     *
     * `frame-ancestors` has no fallback to `default-src`, so a hard-coded `'self'` is
     * absolute: there was no other way for a dashboard living inside a customer portal
     * to be allowed to load at all.
     */
    public function testFrameAncestorsCarriesThePartnerThatEmbedsUs(): void
    {
        // Arrange
        $csp = ['frame-ancestors' => ['https://portal.partner.example']];

        // Act
        $policy = Application::buildCspPolicy($csp);

        // Assert
        $this->assertSame(
            ["'self'", 'https://portal.partner.example'],
            $this->directives($policy)['frame-ancestors']
        );
    }

    /**
     * And the mirror of it — what this application may embed.
     */
    public function testFrameSrcCarriesTheThirdPartyWeEmbed(): void
    {
        // Arrange
        $csp = ['frame-src' => ['https://www.youtube.com']];

        // Act
        $policy = Application::buildCspPolicy($csp);

        // Assert
        $this->assertSame(
            ["'self'", 'https://www.youtube.com'],
            $this->directives($policy)['frame-src']
        );
    }

    /**
     * The default posture is unchanged by all of the above.
     *
     * `form-action 'self'` is the mitigation for a form injected by an XSS that would
     * otherwise post credentials off-site. Opening it to configuration must not open it
     * to an application that says nothing — which is every application that has not
     * been changed.
     */
    public function testAnApplicationThatSaysNothingGetsTheClosedDefaults(): void
    {
        // Act — no csp block at all
        $parsed = $this->directives(Application::buildCspPolicy([]));

        // Assert
        $this->assertSame(["'self'"], $parsed['form-action']);
        $this->assertSame(["'self'"], $parsed['frame-ancestors']);
        $this->assertSame(["'self'"], $parsed['frame-src']);
        $this->assertSame(["'none'"], $parsed['default-src'], 'the floor is unchanged');
    }

    /**
     * The directives that are meant to stay absolute do.
     *
     * These are excluded from `CSP_CONFIGURABLE` on purpose, so the exclusion is worth
     * an assertion: a later change that loops over every directive uniformly would
     * otherwise quietly hand an attacker `object-src` or a `<base href>` rewrite.
     */
    public function testTheDirectivesMeantToStayAbsoluteIgnoreConfiguration(): void
    {
        // Arrange — an application asking for exactly what it must not get
        $csp = [
            'object-src'  => ['https://flash.example'],
            'base-uri'    => ['https://evil.example'],
            'default-src' => ['*'],
        ];

        // Act
        $parsed = $this->directives(Application::buildCspPolicy($csp));

        // Assert
        $this->assertSame(["'none'"], $parsed['object-src']);
        $this->assertSame(["'self'"], $parsed['base-uri']);
        $this->assertSame(["'none'"], $parsed['default-src']);
    }

    /**
     * The invariant that would have caught all four occurrences.
     *
     * `CSP_CONFIGURABLE` is the framework's claim about what an application can set.
     * A directive listed there but not wired to `cspDomains()` is the exact defect that
     * has now shipped four times — `media-src`, `worker-src` twice and `form-action` —
     * and each time the symptom was a browser refusing something with no server-side
     * trace. This asserts the claim rather than the four instances of it.
     */
    public function testEveryDirectiveDeclaredConfigurableActuallyConsultsConfiguration(): void
    {
        foreach (Application::CSP_CONFIGURABLE as $directive) {
            // Arrange — a host unique to this directive, so a source landing in the
            // wrong segment fails rather than passing by coincidence
            $host = 'https://' . str_replace('-', '', $directive) . '.example';

            // Act
            $parsed = $this->directives(
                Application::buildCspPolicy([$directive => [$host]])
            );

            // Assert
            $this->assertArrayHasKey(
                $directive,
                $parsed,
                $directive . ' is declared configurable but is not in the policy at all'
            );
            $this->assertContains(
                $host,
                $parsed[$directive],
                $directive . ' is declared configurable but its sources are discarded'
            );
        }
    }

    /**
     * A `csp` key nothing consults is reported, instead of being read and dropped.
     *
     * This is the shape that cost a day: the configuration is read, the directive never
     * asks for it, and the header comes out unchanged — an option that looks applied.
     * A log line is the strongest thing available, because the same builder runs on a
     * page-cache hit before there is an application to refuse on behalf of.
     */
    public function testAKeyNoDirectiveConsultsIsReportedOnce(): void
    {
        // Arrange — a stream we can read back, and a directive name unique to this run
        // so nothing from an earlier run or an earlier test can satisfy the assertion
        $stream = fopen('php://memory', 'w+');
        $unknown = 'x-made-up-' . bin2hex(random_bytes(4));
        Logger::setStreamTarget($stream);
        Logger::setOutputMode(Logger::OUTPUT_STREAM);

        try {
            // Act — built twice, because buildCspPolicy() can run more than once per
            // request and a warning repeated on every call is a warning skipped
            Application::buildCspPolicy([$unknown => ['https://nowhere.example']]);
            Application::buildCspPolicy([$unknown => ['https://nowhere.example']]);

            rewind($stream);
            $logged = (string) stream_get_contents($stream);
        } finally {
            Logger::setOutputMode(Logger::OUTPUT_FILE);
            Logger::setStreamTarget(null);
            fclose($stream);
        }

        // Assert
        $this->assertSame(
            1,
            substr_count($logged, $unknown),
            'the unconsulted key must be reported exactly once per process'
        );
        $this->assertStringContainsString(
            'form-action',
            $logged,
            'the message must name the directives that can be set'
        );
    }

    /**
     * And a key that *is* consulted is not reported.
     *
     * Guards the other half: a warning that fires for correct configuration is noise
     * everybody learns to ignore, at which point the fifth occurrence is invisible again.
     */
    public function testAConfigurableKeyIsNotReported(): void
    {
        // Arrange
        $stream = fopen('php://memory', 'w+');
        Logger::setStreamTarget($stream);
        Logger::setOutputMode(Logger::OUTPUT_STREAM);

        try {
            // Act
            Application::buildCspPolicy(['form-action' => ['https://client.example']]);

            rewind($stream);
            $logged = (string) stream_get_contents($stream);
        } finally {
            Logger::setOutputMode(Logger::OUTPUT_FILE);
            Logger::setStreamTarget(null);
            fclose($stream);
        }

        // Assert
        $this->assertStringNotContainsString('CSP:', $logged);
    }

    /**
     * A request can add a source the configuration could not have known.
     *
     * The case that needed it: an authorization endpoint knows the registered callback a
     * form submission will end at, and `app.php` cannot — a `form-action` list naming
     * every client of an authorization server would be the union of everything anybody
     * might be authorising, applied to every page of the site.
     */
    public function testAControllerCanAddASourceForThisRequestOnly(): void
    {
        // Arrange
        $app = $this->application();

        // Act
        $app->allowCspSource('form-action', 'https://client.example');

        // Assert
        $this->assertSame(
            ["'self'", 'https://client.example'],
            $this->directives($app->cspPolicy())['form-action']
        );
    }

    /**
     * The same source twice is one source.
     *
     * A flow can pass through the same allowance more than once in a request — the
     * consent screen and the decision it posts are the same endpoint — and a directive
     * that repeats itself is a header that grows for no reason.
     */
    public function testTheSameSourceIsNotAddedTwice(): void
    {
        // Arrange
        $app = $this->application();

        // Act
        $app->allowCspSource('form-action', 'https://client.example');
        $app->allowCspSource('form-action', 'https://client.example');

        // Assert
        $this->assertSame(
            ["'self'", 'https://client.example'],
            $this->directives($app->cspPolicy())['form-action']
        );
    }

    /**
     * `allowUnsafeInline()` still does what it did, now through the general method.
     *
     * It is public and applications call it, so the delegation has to be invisible.
     */
    public function testAllowUnsafeInlineStillWorksThroughTheGeneralMethod(): void
    {
        // Arrange
        $app = $this->application();

        // Act
        $app->allowUnsafeInline('style-src');

        // Assert — and `'unsafe-inline'` cancels the nonce, which is the documented
        // behaviour of the builder rather than of this method
        $this->assertContains(
            "'unsafe-inline'",
            $this->directives($app->cspPolicy())['style-src']
        );
    }

    /**
     * An empty source is not a source.
     *
     * Guards the caller that derives one from a URL: `cspSourceForUri()` returns '' when
     * there is nothing usable to name, and a policy segment ending in a stray space is
     * how a directive comes to look like it has a source it does not.
     */
    public function testAnEmptySourceIsIgnored(): void
    {
        // Arrange
        $app = $this->application();

        // Act
        $app->allowCspSource('form-action', '');

        // Assert
        $this->assertSame(["'self'"], $this->directives($app->cspPolicy())['form-action']);
    }

    /**
     * A URL becomes its origin, and only its origin.
     *
     * A policy names origins, not URLs: a browser matches a path as a *prefix*, so
     * handing `form-action` a full callback is wider than it looks, and a client that
     * appends its own query parameters stops matching. The origin is the honest unit.
     *
     * @param string $uri      What a client registered
     * @param string $expected The source expression that covers it
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('uriSources')]
    public function testAUrlBecomesItsOrigin(string $uri, string $expected): void
    {
        // Act & Assert
        $this->assertSame($expected, Application::cspSourceForUri($uri));
    }

    /**
     * @return array<string,array{string,string}>
     */
    public static function uriSources(): array
    {
        return [
            'plain https'          => ['https://client.example/login-sso', 'https://client.example'],
            'path and query drop'  => ['https://client.example/cb?x=1#f', 'https://client.example'],
            'explicit port kept'   => ['https://client.example:8443/cb', 'https://client.example:8443'],
            'localhost with port'  => ['http://localhost:3000/callback', 'http://localhost:3000'],
            'host is lowercased'   => ['https://Client.EXAMPLE/cb', 'https://client.example'],
            'scheme is lowercased' => ['HTTPS://client.example/cb', 'https://client.example'],
            // A custom scheme has no authority worth matching: `parse_url` reports
            // `oauth` as the *host* of `hwmapp://oauth`, which is true of the string and
            // useless as a policy — `hwmapp://oauth` and `hwmapp://callback` are one app.
            'custom scheme'        => ['hwmapp://oauth/callback', 'hwmapp:'],
            'custom scheme, bare'  => ['hwmapp://oauth', 'hwmapp:'],
            'no scheme'            => ['client.example/cb', ''],
            'relative path'        => ['/callback', ''],
            'empty'                => ['', ''],
            'whitespace'           => ['   ', ''],
            // Two different refusals: `https:///cb` has no scheme *as far as parse_url is
            // concerned* (it rejects the whole string), while `https:` parses a scheme and
            // no host. Both have to answer '' or the directive gets a source that is a
            // bare scheme where an origin was meant.
            'parse_url refuses'    => ['https:///cb', ''],
            'scheme, no host'      => ['https:', ''],
            'empty authority'      => ['https://:8443/cb', ''],
        ];
    }

    /**
     * An application stand-in with no boot: the constructor is what reads configuration,
     * starts a database and a session, and none of that is needed to build a header.
     */
    private function application(): Application
    {
        return new class extends Application {
            public function __construct()
            {
            }
        };
    }
}
