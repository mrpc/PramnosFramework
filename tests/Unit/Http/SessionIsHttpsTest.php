<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Http\Session;

/**
 * Tests for {@see Session::isHttps()} behind a TLS-terminating proxy.
 *
 * `$_SERVER['HTTPS']` describes the connection *this process* received. Behind a
 * load balancer or reverse proxy that terminates TLS, that is the plaintext hop
 * between the proxy and PHP: the browser is on HTTPS and the variable is empty.
 * Everything that keys off this answer then behaves as though the site were
 * plaintext, and the one that matters is the session cookie, which loses its
 * `secure` flag and starts travelling on any http:// request to the domain.
 *
 * The fix cannot be "read `X-Forwarded-Proto`", because that header is
 * client-supplied: any visitor could assert `https` and be handed a secure cookie
 * over a plaintext connection. It is read only when the peer is a declared trusted
 * proxy — so both halves are tested, and the untrusted half is the one that keeps
 * this from being a vulnerability of its own.
 */
#[CoversClass(Session::class)]
class SessionIsHttpsTest extends TestCase
{
    /** @var array<string, mixed> $_SERVER as it was before the test. */
    private array $server = [];

    protected function setUp(): void
    {
        $this->server = $_SERVER;

        unset(
            $_SERVER['HTTPS'],
            $_SERVER['HTTP_X_FORWARDED_PROTO'],
            $_SERVER['REMOTE_ADDR']
        );
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $this->trustProxies([]);
    }

    /**
     * Declare the trusted-proxy list the resolver will read.
     *
     * `ClientIpResolver::fromApplication()` takes it from the *existing* application
     * instance's `applicationInfo`, so the list is set there rather than by
     * constructing a resolver the code under test would not use.
     *
     * @param list<string> $ranges
     */
    private function trustProxies(array $ranges): void
    {
        // getInstance(), not currentInstance(): the resolver reads whichever instance
        // exists, and in a unit run there may not be one yet. The production code
        // deliberately never constructs one — see ClientIpResolver::fromApplication() —
        // but a test that needs a proxy list configured has to make somewhere to put it.
        $app = \Pramnos\Application\Application::getInstance();
        $app->applicationInfo['trusted_proxies'] = $ranges;
    }

    // ── Direct TLS ────────────────────────────────────────────────────────────

    /**
     * The original behaviour, which must not have changed: `HTTPS` on the request
     * settles it, in either spelling, with no proxy involved.
     */
    public function testHttpsServerVariableIsEnough(): void
    {
        // Arrange + Act + Assert
        $_SERVER['HTTPS'] = 'on';
        $this->assertTrue(Session::isHttps());

        $_SERVER['HTTPS'] = '1';
        $this->assertTrue(Session::isHttps());
    }

    /** A plaintext request with nothing else present is plaintext. */
    public function testPlainRequestIsNotHttps(): void
    {
        // Arrange
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';

        // Act + Assert
        $this->assertFalse(Session::isHttps());
    }

    // ── Behind a proxy ────────────────────────────────────────────────────────

    /**
     * THE fix: a trusted proxy saying `https` is believed.
     *
     * Before this, the session cookie behind every TLS-terminating proxy was issued
     * without `secure`.
     */
    public function testForwardedProtoFromATrustedProxyIsBelieved(): void
    {
        // Arrange
        $this->trustProxies(['10.0.0.0/8']);
        $_SERVER['REMOTE_ADDR']            = '10.0.0.5';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

        // Act + Assert
        $this->assertTrue(Session::isHttps());
    }

    /**
     * THE guard: the same header from anyone else is ignored.
     *
     * Without this the fix would be worse than the bug — a visitor could assert
     * `https` on a plaintext connection and be handed a cookie marked `secure`,
     * which the browser would then withhold from the very connection that set it.
     */
    public function testForwardedProtoFromAnUntrustedPeerIsIgnored(): void
    {
        // Arrange
        $this->trustProxies(['10.0.0.0/8']);
        $_SERVER['REMOTE_ADDR']            = '203.0.113.9';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

        // Act + Assert
        $this->assertFalse(Session::isHttps());
    }

    /**
     * With no proxies declared, the header is ignored however it arrived.
     *
     * The default has to stay exactly as it was: an installation that never
     * configured `trusted_proxies` cannot have its answer changed by a header.
     */
    public function testForwardedProtoIsIgnoredWhenNoProxiesAreTrusted(): void
    {
        // Arrange
        $this->trustProxies([]);
        $_SERVER['REMOTE_ADDR']            = '10.0.0.5';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

        // Act + Assert
        $this->assertFalse(Session::isHttps());
    }

    /** A trusted proxy reporting plaintext is believed too. */
    public function testTrustedProxyReportingHttpIsNotHttps(): void
    {
        // Arrange
        $this->trustProxies(['10.0.0.0/8']);
        $_SERVER['REMOTE_ADDR']            = '10.0.0.5';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'http';

        // Act + Assert
        $this->assertFalse(Session::isHttps());
    }

    /**
     * Two proxies deep, the header is a list and the first entry is what the client
     * spoke — the value the cookie's `secure` flag has to answer to.
     */
    public function testTheFirstEntryOfAProtoListWins(): void
    {
        // Arrange
        $this->trustProxies(['10.0.0.0/8']);
        $_SERVER['REMOTE_ADDR']            = '10.0.0.5';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https, http';

        // Act + Assert
        $this->assertTrue(Session::isHttps());
    }

    /** Case is not significant: proxies write `HTTPS` as well as `https`. */
    public function testTheProtoComparisonIsCaseInsensitive(): void
    {
        // Arrange
        $this->trustProxies(['10.0.0.0/8']);
        $_SERVER['REMOTE_ADDR']            = '10.0.0.5';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'HTTPS';

        // Act + Assert
        $this->assertTrue(Session::isHttps());
    }

    /**
     * No peer at all — the CLI, or a test that has not populated `$_SERVER` — cannot
     * be a trusted proxy, so the header decides nothing.
     */
    public function testHeaderWithoutAPeerIsIgnored(): void
    {
        // Arrange
        $this->trustProxies(['10.0.0.0/8']);
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

        // Act + Assert
        $this->assertFalse(Session::isHttps());
    }
}
